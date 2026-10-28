<?php
require __DIR__ . '/helpers.php';

$alice = Agent\Identity::authenticate('alice', 'phillip-demo');
$bob = Agent\Identity::authenticate('bob', 'phillip-demo');
$carol = Agent\Identity::authenticate('carol', 'phillip-demo');
$admin = Agent\Identity::authenticate('admin', 'phillip-demo');
$frontend = new Agent\ProjectFrontend($alice);
$threads = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
try {
    expectException(function () { new Agent\ProjectFrontend(null); }, 'Authentication required');
    foreach ([['settings' => ['workspace' => '/tmp']], ['tools' => ['Agent\\Tools\\Source']], ['settings' => ['project_frontend' => false]], ['settings' => ['profile' => ['url' => 'https://example.invalid']]] ] as $bad) {
        $rejected = false;
        try { $frontend->create(array_replace(['title' => 'Rejected project frontend fixture'], $bad)); } catch (Throwable $error) { $rejected = true; }
        check($rejected, 'Web project creation rejects local execution authority and unapproved profile/tool settings');
    }
    $project = $frontend->create(['title' => 'Project <script>fixture</script>', 'profile' => 'default', 'tools' => [], 'status' => 'completed', 'preflight_status' => 'ready', 'owner_id' => $bob['id']]);
    $threads[] = $project->get()['thread_id'];
    check($project->get()['owner_id'] === $alice['id'] && $project->get()['status'] === 'planning' && $project->get()['preflight_status'] === 'unchecked', 'Frontend derives owner and lifecycle state from authenticated core creation');
    check(!empty($project->get()['settings']['project_frontend']), 'Frontend project creation records its execution policy on the planning thread');
    foreach ([$bob, $carol, $admin] as $outsider) expectException(function () use ($outsider, $project) { (new Agent\ProjectFrontend($outsider))->project($project->id); }, 'access denied');
    $frontend->action($project->id, 'add', ['title' => 'Parent <img src=x onerror=alert(1)>', 'goal' => 'Goal', 'requirements' => "Requirement one\nRequirement two", 'context' => 'Known context', 'gates' => "First gate\n\nSecond gate", 'status' => 'done', 'result' => 'forged', 'max_attempts' => 3]);
    $parent = $project->todos()[0];
    check($parent['status'] === 'pending' && $parent['result'] === '' && $parent['gates'] === ['First gate', 'Second gate'], 'Todo web payloads ignore protected completion fields and normalize gate lines');
    $frontend->action($project->id, 'add', ['title' => 'Child fixture', 'parent_id' => $parent['id']]);
    $child = $project->todos()[1];
    check(count($project->validate()) > 0, 'Incomplete child drafts remain editable and fail readiness validation');
    $frontend->action($project->id, 'preflight');
    check($project->get()['status'] === 'planning' && $project->get()['preflight_status'] === 'gaps', 'Readiness checks report missing plan fields without starting execution');
    expectException(function () use ($frontend, $project) { $frontend->action($project->id, 'start'); }, 'Every ToDo');
    $state = ['user' => $alice, 'csrf' => 'project-csrf-fixture', 'personas' => [], 'profiles' => [['name' => 'default']], 'teams' => Agent\Identity::teams($alice['id']), 'tools' => []];
    $html = Agent\Views::render('projects', ['state' => $state, 'projectId' => $project->id]);
    $document = new DOMDocument(); @$document->loadHTML($html); $xpath = new DOMXPath($document);
    check(strpos($html, '<script>fixture') === false && strpos($html, '&lt;script&gt;fixture') !== false && strpos($html, '<img src=x') === false, 'Projects render stored titles and Todo content as safe server HTML');
    check($xpath->query('//form[@data-project-form]')->length >= 8 && $xpath->query('//form[@data-project-form and not(input[@name="csrf"])]')->length === 0, 'Project actions render ordinary POST forms with CSRF fallbacks');
    check($xpath->query('//form[@data-project-form="start:"]/button[@disabled]')->length === 1, 'Start is disabled until current plan validation and preflight both pass');
    check(strpos($html, 'Add child ToDo') !== false && strpos($html, 'Child fixture') !== false && strpos($html, 'Acceptance gates') !== false, 'Project view renders child creation, nested drafts, and explicit acceptance gates');
    check(strpos($html, '?thread=' . $project->get()['thread_id']) !== false && strpos($html, 'data-thread=') === false, 'Planning chat uses normal navigation that initializes the existing chat workflow');
    $list = Agent\Views::render('projects', ['state' => $state]);
    check(strpos($list, 'data-project-form="create"') !== false && strpos($list, 'project-create') !== false && strpos($list, 'creation_key') !== false, 'Project list includes a server-rendered idempotent creation form');
    $frontend->action($project->id, 'share', ['visibility' => 'team', 'team_id' => 'engineering']);
    $collaborator = new Agent\ProjectFrontend($bob);
    $collaborator->action($project->id, 'update', ['context' => 'Shared teammate context'], $child['id']);
    check($project->todos()[1]['context'] === 'Shared teammate context', 'Visible team collaborators may edit project Todo drafts');
    expectException(function () use ($project, $carol) { (new Agent\ProjectFrontend($carol))->project($project->id); }, 'access denied');
    foreach (['share', 'settings', 'delete'] as $action) expectException(function () use ($collaborator, $project, $action) { $collaborator->action($project->id, $action, ['visibility' => 'organization', 'settings' => []]); }, 'creator');
    $frontend->action($project->id, 'share', ['visibility' => 'organization']);
    check((new Agent\ProjectFrontend($carol))->project($project->id)->id === $project->id, 'Organization visibility uses the existing authenticated thread audience');
    // Team ownership alone must never bypass a changed team membership boundary.
    Agent\Database::execute('UPDATE threads SET visibility = ?, team_id = ? WHERE id = ?', ['team', 'sales', $project->get()['thread_id']]);
    expectException(function () use ($frontend, $project) { $frontend->project($project->id); }, 'access denied');
    $listed = array_column($frontend->projects(), 'id');
    check(!in_array($project->id, array_map('intval', $listed), true), 'Project lists respect the same strict team boundary as detail access');
} finally {
    foreach ($threads as $threadId) (new Agent\Thread($threadId))->delete();
}

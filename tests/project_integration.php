<?php
require __DIR__ . '/helpers.php';

$alice = Agent\Identity::authenticate('alice', 'phillip-demo');
$bob = Agent\Identity::authenticate('bob', 'phillip-demo');
$carol = Agent\Identity::authenticate('carol', 'phillip-demo');
$frontend = new Agent\Frontend($alice['id']);
$db = Agent\Database::connection();
$projects = []; $threads = [];
$complete = ['title' => 'Integration deliverable', 'goal' => 'Check shared application boundaries', 'requirements' => 'No model or external effects', 'context' => 'An isolated database fixture', 'gates' => ['All regression checks pass']];
$create = function ($visibility = 'private', $teamId = null) use ($alice, &$projects) {
    $project = Agent\Project::create($alice['id'], ['title' => 'Project integration ' . bin2hex(random_bytes(8)), 'settings' => ['manual' => true], 'visibility' => $visibility, 'team_id' => $teamId]);
    // Hold the worker's session lock throughout this fixture, including queued preflight assertions.
    Agent\Database::row("SELECT pg_advisory_lock(hashtext('phill-project-runner'), ?::integer)", [$project->id]);
    $projects[] = $project;
    return $project;
};
$ready = function ($project) { Agent\Database::execute("UPDATE projects SET preflight_status = 'ready', preflight_revision = revision WHERE id = ?", [$project->id]); };
$predicateCount = function ($userId, $threadId) {
    list($where, $params) = Agent\Access::predicate($userId);
    $rows = Agent\Database::rows('SELECT t.id FROM threads t WHERE t.id = ? AND ' . $where, array_merge([$threadId], $params));
    return count($rows);
};
try {
    $project = $create(); $todo = $project->addTodo($complete);
    $planning = new Agent\Thread($project->get()['thread_id']);
    $execution = Agent\Thread::create($alice['id'], 'Read-only project execution fixture', ['manual' => true]); $threads[] = $execution;
    Agent\Database::execute('UPDATE project_todos SET thread_id = ? WHERE id = ?', [$execution->id, $todo['id']]);
    foreach ([['organization', null, true, true], ['team', 'engineering', true, false], ['private', null, false, false]] as $scope) {
        $frontend->share($planning->id, $scope[0], $scope[1]);
        $child = $execution->get();
        check($child['visibility'] === $scope[0] && $child['team_id'] === $scope[1], 'Sharing propagates ' . $scope[0] . ' visibility to task rows used by memory predicates');
        foreach ([[$bob['id'], $scope[2], 'bob'], [$carol['id'], $scope[3], 'carol']] as $viewer) {
            if ($viewer[1]) { Agent\Access::read($viewer[0], $execution); check(true, $scope[0] . ' task permits ' . $viewer[2]); }
            else expectException(function () use ($viewer, $execution) { Agent\Access::read($viewer[0], $execution); }, 'access denied');
            check($predicateCount($viewer[0], $execution->id) === ($viewer[1] ? 1 : 0), $scope[0] . ' SQL visibility predicate agrees for ' . $viewer[2]);
        }
    }
    // Even a stale child row cannot overrule the authoritative parent ACL on direct reads.
    Agent\Database::execute("UPDATE threads SET visibility = 'organization' WHERE id = ?", [$execution->id]);
    expectException(function () use ($bob, $execution) { Agent\Access::read($bob['id'], $execution); }, 'access denied');
    $frontend->share($planning->id, 'private');
    $frontend->thread($execution->id);
    check(!in_array($execution->id, array_column($frontend->threads(), 'id')), 'Execution histories remain inspectable without appearing as ordinary chats');
    $executionRun = $execution->enqueue($alice['id'], 'Read-only execution message');
    Agent\Database::execute("UPDATE runs SET status = 'stopped' WHERE id = ?", [$executionRun['id']]);
    $message = $execution->messages()[1];
    foreach (['send', 'edit', 'configure', 'delete', 'share'] as $operation) expectException(function () use ($frontend, $execution, $message, $operation) {
        if ($operation === 'send') $frontend->send($execution->id, ['content' => 'Cannot enqueue work', 'submission_key' => bin2hex(random_bytes(16))]);
        elseif ($operation === 'edit') $frontend->edit($execution->id, $message['id'], 'Cannot replace execution');
        elseif ($operation === 'configure') $frontend->configure($execution->id, ['temperature' => 0.1]);
        elseif ($operation === 'share') $frontend->share($execution->id, 'organization');
        else $frontend->delete($execution->id);
    }, 'read-only');

    foreach (['execution_context', 'project_frontend'] as $field) foreach ([null, false, ['user_id' => $bob['id']]] as $value) {
        expectException(function () use ($frontend, $field, $value) { $frontend->create(['title' => 'Rejected execution identity', 'settings' => [$field => $value]]); }, 'managed by the server');
        expectException(function () use ($frontend, $planning, $field, $value) { $frontend->configure($planning->id, [$field => $value]); }, 'managed by the server');
    }
    $ordinary = Agent\Thread::create($alice['id'], 'Ordinary tool scope fixture', ['manual' => true]); $threads[] = $ordinary;
    check(Agent\Access::tools($alice['id'], $ordinary, ['Agent\\Tools\\Project']) === [], 'Internal Project tools are unavailable outside project threads');
    check(Agent\Access::tools($alice['id'], $planning, ['Agent\\Tools\\Project']) === ['Agent\\Tools\\Project'], 'Planning context receives scoped internal Project tools without an external tool grant');
    check(Agent\Access::tools($alice['id'], $execution, ['Agent\\Tools\\Project::report']) === ['Agent\\Tools\\Project::report'], 'Execution context permits its scoped report tool');
    Agent\ToolContext::withValues(['thread_id' => $ordinary->id], function () { expectException(function () { Agent\Tools\Project::status(); }, 'not a project thread'); });
    $safe = Agent\ProjectRunner::safeTools(['Source', 'Workspace', 'SQL', 'Delegate', 'History', 'Attachments', 'SearchWeb', 'Memory']);
    check($safe === ['Agent\\Tools\\SearchWeb', 'Agent\\Tools\\Memory', 'Agent\\Tools\\Project'], 'Project tool ceiling excludes all local execution and database authority');

    $project->requestPreflight(); $before = $project->get(); $events = count($project->history());
    $project->requestPreflight();
    check($project->get()['preflight_status'] === 'queued' && count($project->history()) === $events, 'Duplicate queued preflight is idempotent');
    Agent\Database::execute("UPDATE projects SET preflight_status = 'checking', preflight_started_at = '2020-01-01 00:00:00+00' WHERE id = ?", [$project->id]);
    $checking = $project->get(); $project->requestPreflight();
    check($project->get()['preflight_status'] === 'checking' && $project->get()['preflight_started_at'] === $checking['preflight_started_at'] && count($project->history()) === $events, 'Duplicate in-flight preflight preserves its heartbeat and durable work');
    $ready($project); $planningRun = $planning->enqueue($alice['id'], 'Outstanding planning response');
    expectException(function () use ($project, $alice) { $project->start($alice['id']); }, 'planning conversation work');
    Agent\Database::execute("UPDATE runs SET status = 'running' WHERE id = ?", [$planningRun['id']]);
    expectException(function () use ($project, $alice) { $project->start($alice['id']); }, 'planning conversation work');
    expectException(function () use ($frontend, $planning) { $frontend->delete($planning->id); }, 'active planning run');
    Agent\Database::execute("UPDATE runs SET status = 'stopped' WHERE id = ?", [$planningRun['id']]);
    $project->start($alice['id']);
    check($project->get()['status'] === 'running', 'Human Start proceeds after outstanding planning work has finished');
    $project->pause($alice['id']);

    $normalized = $create();
    $jsonTodo = $normalized->addTodo(json_encode(array_replace($complete, ['gates' => "First gate\n\n Second gate \r\n"])));
    check($jsonTodo['gates'] === ['First gate', 'Second gate'], 'Core ToDo payload normalizes JSON objects and newline gate text');
    $objectTodo = $normalized->updateTodo($jsonTodo['id'], (object)['context' => 'Named object update']);
    check($objectTodo['context'] === 'Named object update', 'Core ToDo updates accept named objects');
    expectException(function () use ($normalized) { $normalized->addTodo(['not', 'an', 'object']); }, 'not a list');

    $ready($normalized); $beforePlanning = $normalized->get();
    $planningMessage = ['content' => 'Flesh out the nested ToDos from this brief', 'submission_key' => 'project-planning-' . bin2hex(random_bytes(12))];
    $queuedPlanning = $normalized->plan($alice['id'], $planningMessage);
    $afterPlanning = $normalized->get();
    check($queuedPlanning['status'] === 'queued' && $queuedPlanning['thread_id'] == $beforePlanning['thread_id'] && $afterPlanning['status'] === 'planning', 'Planning queues a normal shared-engine run without starting project execution');
    check($afterPlanning['revision'] === $beforePlanning['revision'] + 1 && $afterPlanning['preflight_status'] === 'unchecked', 'New planning conversation invalidates ready preflight exactly once');
    $retryPlanning = $normalized->plan($alice['id'], $planningMessage);
    check($retryPlanning['id'] == $queuedPlanning['id'] && $normalized->get()['revision'] === $afterPlanning['revision'], 'Retrying a planning submission returns its durable run without invalidating again');
    $historyBeforeConflict = count($normalized->history());
    expectException(function () use ($normalized, $alice, $planningMessage) { $normalized->plan($alice['id'], array_replace($planningMessage, ['content' => 'Changed prompt with the same key'])); }, 'different message payload');
    check($normalized->get()['revision'] === $afterPlanning['revision'] && count($normalized->history()) === $historyBeforeConflict, 'Conflicting planning submission rolls back without changing readiness or events');
    foreach (['', " \n\t ", ['content' => null]] as $emptyMessage) expectException(function () use ($normalized, $alice, $emptyMessage) { $normalized->plan($alice['id'], $emptyMessage); }, 'nonempty planning message');
    check($normalized->get()['revision'] === $afterPlanning['revision'], 'Rejected empty planning prompts do not change project revision');
    $ready($normalized);
    expectException(function () use ($normalized, $alice) { $normalized->start($alice['id']); }, 'planning conversation work');
    Agent\Database::execute("UPDATE runs SET status = 'stopped' WHERE id = ?", [$queuedPlanning['id']]);
    $normalized->start($alice['id']);
    $runningRevision = $normalized->get()['revision'];
    $checkin = $normalized->plan($alice['id'], 'How is this project progressing?');
    check(Agent\Submission::key($checkin['submission_key']) === $checkin['submission_key'] && $normalized->get()['revision'] === $runningRevision && $normalized->get()['status'] === 'running', 'Running-project check-in generates a stable submission key while preserving its active plan');
    $normalized->pause($alice['id']);
    Agent\Database::execute("UPDATE runs SET status = 'stopped' WHERE id = ?", [$checkin['id']]);
    $pausedRevision = $normalized->get()['revision'];
    $pausedPlan = $normalized->plan($alice['id'], (object)['content' => 'Revise this paused project plan']);
    check($normalized->get()['revision'] === $pausedRevision + 1 && $normalized->get()['status'] === 'paused', 'Paused planning normalizes message objects and invalidates readiness without resuming');
    Agent\Database::execute("UPDATE runs SET status = 'stopped' WHERE id = ?", [$pausedPlan['id']]);

    // Run::execute must stop before constructing/sending a model request when ownership changed or paused.
    $profile = ['url' => 'http://127.0.0.1:1/never-connect', 'model' => 'no-provider-allowed', 'max_retries' => 0];
    $execution->configure(['profile' => $profile, 'tools' => ['Agent\\Tools\\Workspace'], 'manual' => true]);
    $pausedRun = $execution->enqueue($alice['id'], 'Paused execution');
    Agent\Database::execute("UPDATE project_todos SET status = 'working', run_id = ? WHERE id = ?", [$pausedRun['id'], $todo['id']]);
    $claimed = Agent\Run::claim($execution->id);
    check($claimed['id'] == $pausedRun['id'], 'Fixture claims only its manual task run');
    check(Agent\Run::execute($pausedRun['id']) === null && Agent\Run::get($pausedRun['id'])['status'] === 'stopped', 'Paused project run stops before any provider request or local tool');
    $staleRun = $execution->enqueue($alice['id'], 'Superseded execution');
    $newRun = $execution->enqueue($alice['id'], 'Current execution');
    Agent\Database::execute("UPDATE projects SET status = 'running' WHERE id = ?", [$project->id]);
    Agent\Database::execute('UPDATE project_todos SET run_id = ? WHERE id = ?', [$newRun['id'], $todo['id']]);
    Agent\Run::claim($execution->id);
    check(Agent\Run::execute($staleRun['id']) === null && Agent\Run::get($staleRun['id'])['status'] === 'stopped', 'Superseded run pointer stops stale execution before any provider request');
    Agent\Database::execute("UPDATE runs SET status = 'stopped' WHERE id = ?", [$newRun['id']]);
    Agent\Database::execute("UPDATE projects SET status = 'paused' WHERE id = ?", [$project->id]);
} finally {
    if ($db->inTransaction()) $db->rollBack();
    foreach ($projects as $fixture) {
        $row = Agent\Database::row('SELECT thread_id FROM projects WHERE id = ?', [$fixture->id]);
        if ($row) {
            foreach ($fixture->todos() as $todo) if ($todo['thread_id']) Agent\Database::execute('DELETE FROM threads WHERE id = ?', [$todo['thread_id']]);
            Agent\Database::execute('DELETE FROM threads WHERE id = ?', [$row['thread_id']]);
        }
        Agent\Database::row("SELECT pg_advisory_unlock(hashtext('phill-project-runner'), ?::integer)", [$fixture->id]);
    }
    foreach ($threads as $thread) $thread->delete();
}

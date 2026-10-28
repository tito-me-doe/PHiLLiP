<?php
require __DIR__ . '/helpers.php';

$alice = Agent\Identity::authenticate('alice', 'phillip-demo');
$bob = Agent\Identity::authenticate('bob', 'phillip-demo');
$carol = Agent\Identity::authenticate('carol', 'phillip-demo');
$projects = []; $threads = [];
$complete = ['title' => 'Verify behavior', 'goal' => 'Produce the agreed result', 'requirements' => 'Use the existing application patterns', 'context' => 'An isolated database fixture', 'gates' => ['Record observed verification evidence']];
$db = Agent\Database::connection();
$ready = function ($project) {
    // A trusted runner writes this only after semantic preflight. Never exposed as user input.
    Agent\Database::execute("UPDATE projects SET preflight_status = 'ready', preflight_revision = revision WHERE id = ?", [$project->id]);
};
try {
    $project = Agent\Project::create($alice['id'], ['title' => 'Project fixture ' . bin2hex(random_bytes(8)), 'settings' => ['manual' => true]]); $projects[] = $project;
    check($project->get()['status'] === 'planning' && $project->get()['preflight_status'] === 'unchecked', 'New project always begins in planning without readiness');
    $forged = new Agent\Project(['id' => $project->id, 'status' => 'running']);
    check($forged->get()['status'] === 'planning', 'Row-shaped project references reload authoritative state');
    check((new Agent\Project($project->get()['title']))->id === $project->id, 'Exact unique project name resolves to authoritative identity');
    check(Agent\Project::forThread($project->get()['thread_id'])->id === $project->id, 'Planning thread resolves its project');
    expectException(function () use ($alice) { Agent\Project::create($alice['id'], ['status' => 'running']); }, 'Unknown project creation field');
    expectException(function () use ($project, $alice) { $project->start($alice['id']); }, 'Every ToDo');
    check($project->requestPreflight()['preflight_status'] === 'gaps', 'Empty plans return required-field gaps without model execution');

    $todo = $project->addTodo(['title' => 'Incomplete draft']);
    $fields = array_column($project->validate(), 'field');
    foreach (['goal', 'requirements', 'context', 'gates'] as $field) check(in_array($field, $fields, true), 'Draft reports missing ' . $field);
    expectException(function () use ($project, $todo) { $project->updateTodo($todo['id'], ['status' => 'done']); }, 'protected todo field');
    expectException(function () use ($project, $todo) { $project->updateTodo($todo['id'], ['review' => ['passed' => true]]); }, 'protected todo field');
    expectException(function () use ($project) { $project->addTodo(['gates' => ['']]); }, 'nonempty text');
    expectException(function () use ($project) { $project->addTodo(['gates' => ['named' => 'gate']]); }, 'list of strings');
    $todo = $project->updateTodo($todo['id'], $complete);
    check($project->validate() === [], 'All required ToDo fields satisfy structural validation');
    check($project->requestPreflight()['preflight_status'] === 'queued', 'Complete draft queues independent preflight without starting');
    expectException(function () use ($project, $alice) { $project->start($alice['id']); }, 'ready preflight');
    $ready($project);
    $rev = $project->get()['revision'];
    $project->updateTodo($todo['id'], ['context' => 'Updated fixture context']);
    check($project->get()['revision'] === $rev + 1 && $project->get()['preflight_status'] === 'unchecked', 'Planning edit increments revision and invalidates ready preflight');
    Agent\Database::execute("UPDATE projects SET preflight_status = 'ready', preflight_revision = revision - 1 WHERE id = ?", [$project->id]);
    expectException(function () use ($project, $alice) { $project->start($alice['id']); }, 'ready preflight');
    $ready($project);
    expectException(function () use ($project, $alice) { Agent\ToolContext::withValues(['run_id' => 7], function () use ($project, $alice) { $project->start($alice['id']); }); }, 'Only a human');
    check($project->get()['status'] === 'planning', 'Agent cannot Start even a ready plan');

    $child = $project->addTodo(array_replace($complete, ['title' => 'Child', 'parent_id' => $todo['id']]));
    expectException(function () use ($project, $todo, $child) { $project->updateTodo($todo['id'], ['parent_id' => $child['id']]); }, 'cycle');
    $other = Agent\Project::create($alice['id'], ['title' => 'Other project fixture', 'settings' => ['manual' => true]]); $projects[] = $other;
    $foreign = $other->addTodo($complete);
    expectException(function () use ($project, $todo, $foreign) { $project->updateTodo($todo['id'], ['parent_id' => $foreign['id']]); }, 'belong to this project');
    expectException(function () use ($project, $foreign, $complete) { $project->updateTodo($foreign['id'], $complete); }, 'not found in this project');
    expectException(function () use ($project, $todo) { $project->removeTodo($todo['id']); }, 'explicit descendant deletion');
    $grandchild = $project->addTodo(['parent_id' => $child['id'], 'title' => 'Missing required fields']);
    check(count($project->validate()) === 4, 'Nested draft validation checks every descendant');
    $project->removeTodo($grandchild['id']);

    $ready($project); $project->start($alice['id']);
    check($project->get()['status'] === 'running' && $project->get()['started_by'] === $alice['id'], 'Explicit human Start durably records actor and running state');
    expectException(function () use ($project, $complete) { $project->addTodo($complete); }, 'Pause the project');
    expectException(function () use ($project, $todo) { $project->removeTodo($todo['id'], true); }, 'Pause the project');
    $project->pause($alice['id']);
    check($project->get()['status'] === 'paused', 'Human pause persists execution state');
    $before = $project->get()['revision']; $project->invalidatePlan();
    check($project->get()['revision'] === $before + 1 && $project->get()['preflight_status'] === 'unchecked', 'Settings adapter invalidates plan readiness atomically');

    $sibling = $project->addTodo(array_replace($complete, ['title' => 'Sibling', 'parent_id' => $todo['id']]));
    $execution = Agent\Thread::create($alice['id'], 'Project execution fixture', ['manual' => true]); $threads[] = $execution;
    $run = $execution->enqueue($alice['id'], 'Task fixture');
    Agent\Database::execute("UPDATE project_todos SET thread_id = ?, run_id = ?, status = 'working', run_revision = revision WHERE id = ?", [$execution->id, $run['id'], $child['id']]);
    check(Agent\Project::forThread($execution->id)->id === $project->id, 'Execution thread resolves through durable task linkage');
    expectException(function () use ($project, $todo) { $project->updateTodo($todo['id'], ['title' => 'Cannot race queued execution']); }, 'active project work');
    // Establish the execution state normally supplied by the trusted dispatcher, without a provider request.
    Agent\Database::execute("UPDATE projects SET status = 'running' WHERE id = ?", [$project->id]);
    check(Agent\Run::claim($execution->id)['id'] === $run['id'], 'Runtime report fixture uses an actually claimed run');
    $context = ['thread_id' => $execution->id, 'run_id' => $run['id']];
    Agent\ToolContext::withValues($context, function () use ($complete, $child, $sibling) {
        $created = Agent\Tools\Project::addTodo(array_replace($complete, ['title' => 'Runtime direct child']));
        check((string)$created['parent_id'] === (string)$child['id'] && (string)$created['creator_todo_id'] === (string)$child['id'], 'Execution agents create only their own direct child with fixed provenance');
        expectException(function () use ($child) { Agent\Tools\Project::updateTodo($child['id'], ['title' => 'Forbidden self edit']); }, 'direct child');
        expectException(function () use ($sibling) { Agent\Tools\Project::updateTodo($sibling['id'], ['title' => 'Forbidden sibling edit']); }, 'direct child');
        Agent\Tools\Project::report('Verification observed', ['gate' => 'observed evidence']);
    });
    $reported = Agent\Database::row('SELECT status, result FROM project_todos WHERE id = ?', [$child['id']]);
    check($reported['status'] === 'working' && $reported['result'] === 'Verification observed', 'Agent report records evidence without setting done');
    Agent\ToolContext::withValues(['thread_id' => $project->get()['thread_id'], 'run_id' => $run['id']], function () {
        expectException(function () { Agent\Tools\Project::report('Forged execution'); }, 'currently working task');
    });
    Agent\ToolContext::withValues($context, function () { Agent\Tools\Project::blocked('Need a human choice', ['question' => 'Choose destination']); });
    $decision = Agent\Database::row('SELECT * FROM project_decisions WHERE project_id = ? AND todo_id = ? ORDER BY id DESC LIMIT 1', [$project->id, $child['id']]);
    check($project->get()['status'] === 'running' && (string)$decision['target_todo_id'] === (string)$todo['id'] && $decision['answer'] === null, 'Execution blocker records a question for its nearest parent');
    $project->pause($alice['id']);
    check((int)Agent\Run::get($run['id'])['stop_level'] > 0, 'Pause requests that the claimed task stop');
    Agent\Run::finishStopped($run['id'], 'Fixture acknowledges pause');
    Agent\Database::execute("UPDATE project_todos SET status = 'blocked' WHERE id = ?", [$child['id']]);
    $project->retryTodo($child['id']);
    check($project->get()['preflight_status'] === 'unchecked', 'Explicit blocked-task retry requires fresh preflight and human Start');
    $project->removeTodo($todo['id'], true);
    check($project->todos() === [] && count($project->history()) > 10, 'Explicit subtree deletion cleans descendants while durable history survives');
    check(!Agent\Database::row('SELECT id FROM threads WHERE id = ?', [$execution->id]) && !Agent\Database::row('SELECT id FROM runs WHERE id = ?', [$run['id']]), 'Removing a task subtree also cleans its execution threads, runs, and attached state');
    $feed = $project->history(); $last = end($feed);
    check($project->history($last['id']) === [], 'History cursor resumes strictly after the last durable event');

    foreach (['private' => null, 'team' => 'engineering', 'organization' => null] as $visibility => $team) {
        $scoped = Agent\Project::create($alice['id'], ['title' => 'Project ACL ' . $visibility, 'visibility' => $visibility, 'team_id' => $team, 'settings' => ['manual' => true]]); $projects[] = $scoped;
        Agent\ProjectAccess::read($alice['id'], $scoped);
        if ($visibility === 'private') expectException(function () use ($bob, $scoped) { Agent\ProjectAccess::read($bob['id'], $scoped); }, 'access denied');
        else { Agent\ProjectAccess::edit($bob['id'], $scoped); check(true, $visibility . ' collaborator may edit project planning'); }
        if ($visibility === 'team') expectException(function () use ($carol, $scoped) { Agent\ProjectAccess::edit($carol['id'], $scoped); }, 'access denied');
        if ($visibility === 'organization') { Agent\ProjectAccess::edit($carol['id'], $scoped); check(true, 'Organization project allows active nonmember collaborator'); }
        expectException(function () use ($scoped) { Agent\ProjectAccess::read(null, $scoped); }, 'access denied');
        if ($visibility !== 'private') expectException(function () use ($bob, $scoped) { Agent\ProjectAccess::owner($bob['id'], $scoped); }, 'Only the thread creator');
    }
    $adopt = Agent\Thread::create($alice['id'], 'Adopt project fixture', ['manual' => true]); $threads[] = $adopt;
    expectException(function () use ($bob, $adopt) { Agent\Project::create($bob['id'], ['thread_id' => $adopt->id]); }, 'Only the thread owner');
    $adopted = Agent\Project::create($alice['id'], ['thread_id' => $adopt->id]); $projects[] = $adopted;
    check($adopted->get()['thread_id'] == $adopt->id && Agent\Project::create($alice['id'], ['thread_id' => $adopt->id])->id === $adopted->id, 'Frontend-created planning thread is adopted idempotently');
    $deleting = Agent\Project::create($alice['id'], ['title' => 'Project deletion fixture', 'settings' => ['manual' => true]]);
    $deleteTodo = $deleting->addTodo($complete);
    $deleteExecution = Agent\Thread::create($alice['id'], 'Execution deletion fixture', ['manual' => true]); $threads[] = $deleteExecution;
    Agent\Database::execute('UPDATE project_todos SET thread_id = ? WHERE id = ?', [$deleteExecution->id, $deleteTodo['id']]);
    $deleting->delete();
    check(!Agent\Database::row('SELECT id FROM projects WHERE id = ?', [$deleting->id]) && !Agent\Database::row('SELECT id FROM threads WHERE id = ?', [$deleteExecution->id]), 'Project deletion cleans both its planning and execution resources');
} finally {
    if ($db->inTransaction()) $db->rollBack();
    foreach ($projects as $fixture) Agent\Database::execute('DELETE FROM threads WHERE id = ?', [$fixture->get()['thread_id']]);
    foreach ($threads as $thread) $thread->delete();
}

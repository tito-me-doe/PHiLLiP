<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/cli_process.php';

$marker = 'project-cli-' . bin2hex(random_bytes(6));
$fixtures = [];
$profile = json_encode(['url' => 'http://127.0.0.1:1', 'model' => 'unused-fixture', 'max_retries' => 0]);

function projectCliCommand(array $args, $expected = 0, $input = '', $unprivileged = true)
{
    global $fixtures, $marker;
    $response = cliProcess(array_merge(['projects'], $args), $input, $unprivileged);
    $body = json_decode($response['out'], true);
    // Track even an unexpectedly successful create before checking its expected exit status.
    if ($args[0] === 'create' && is_array($body) && isset($body['id'], $body['thread_id'])) {
        $project = new Agent\Project($body['id']); $row = $project->get();
        if (strpos($row['title'], $marker) !== 0) throw new RuntimeException('Unexpected fixture title');
        $fixtures[$project->id] = ['thread_id' => $row['thread_id'], 'owner_id' => $row['owner_id']];
        (new Agent\Thread($row['thread_id']))->configure(['manual' => true, 'project_manual' => true]);
    }
    check($response['status'] === $expected, 'Project CLI ' . $args[0] . ' exits ' . $expected . ($response['status'] === $expected ? '' : ': ' . $response['out'] . $response['err']));
    check(is_array($body) && json_last_error() === JSON_ERROR_NONE, 'Project CLI ' . $args[0] . ' returns uncontaminated JSON stdout');
    return $body;
}

try {
    foreach ([['bogus'], ['create'], ['show'], ['create', $marker, '-p', 'a', '-p', 'b'], ['create', $marker, '--timeout', '5'], ['wait', '1', '--timeout', '99'], ['wait', '1', '--until', 'invalid'], ['events', '1', '--after', 'abc']] as $args) {
        $error = projectCliCommand($args, 2);
        check(isset($error['error']), 'Invalid project arguments explain the usage error');
    }
    $created = projectCliCommand(['create', $marker, '-p', $profile]);
    $id = (string)$created['id']; $project = new Agent\Project($id);
    $unsupported = projectCliCommand(['create', $marker . '-unsupported', '-p', $profile, '-t', 'Source'], 2);
    check(strpos($unsupported['error'], 'web and memory') !== false, 'Unsupported workspace tools are explicitly rejected');
    $snapshot = projectCliCommand(['show', $id]);
    check((string)$snapshot['project']['id'] === $id && $snapshot['todos'] === [], 'Show returns the project snapshot and its ToDos');
    $compact = projectCliCommand(['show', $id, '--compact']);
    check((string)$compact['id'] === $id && isset($compact['progress'], $compact['activeTodo'], $compact['blockedTodos'], $compact['planning']), 'Compact show exposes actionable progress and planning state');
    $listed = projectCliCommand(['list', '--compact']);
    check(in_array($id, array_map('strval', array_column($listed, 'id')), true), 'The created project appears in its real CLI owner list');

    projectCliCommand(['add', $id], 2);
    foreach (['[]', 'null', '"text"', '{invalid', '{"status":"done"}'] as $bad) projectCliCommand(['add', $id, $bad], 2);
    $draft = projectCliCommand(['add', $id, '{}']);
    check($draft['status'] === 'pending', 'An empty JSON object creates an incomplete draft, never a completed task');
    $gaps = projectCliCommand(['preflight', $id]);
    check($gaps['preflight_status'] === 'gaps', 'Incomplete drafts return actionable preflight gaps without a model call');
    projectCliCommand(['remove-todo', $id, (string)$draft['id']]);
    $todo = ['title' => 'Fixture task', 'goal' => 'Produce a fixture result', 'requirements' => 'Use fixture facts', 'context' => 'No model or side effects', 'gates' => ['A verified fixture result exists']];
    $saved = projectCliCommand(['add', $id], 0, json_encode($todo));
    $todoId = (string)$saved['id'];
    check($saved['gates'] === $todo['gates'], 'Piped JSON preserves the explicit acceptance gates');
    $updated = projectCliCommand(['update', $id, $todoId, '{"title":"Updated fixture task"}']);
    check($updated['title'] === 'Updated fixture task', 'Argument JSON updates the requested ToDo');
    $updated = projectCliCommand(['update', $id, $todoId], 0, '{"context":"Updated fixture context"}');
    check($updated['context'] === 'Updated fixture context', 'Updates also accept JSON through stdin');
    projectCliCommand(['update', $id, $todoId, '[]'], 2);
    projectCliCommand(['update', $id, $todoId, '{"status":"done"}'], 2);
    projectCliCommand(['start', $id], 1);
    projectCliCommand(['wait', $id, '--until', 'completed', '--timeout', '0'], 3);
    projectCliCommand(['wait', $id, '--until', 'ready', '--timeout', '0'], 3);
    // Unit fixture readiness tests wait semantics, without impersonating the model checker.
    Agent\Database::execute("UPDATE projects SET preflight_status = 'ready', preflight_revision = revision WHERE id = ?", [$id]);
    $ready = projectCliCommand(['wait', $id, '--until', 'ready', '--timeout', '0', '--compact']);
    check($ready['status'] === 'planning', 'Waiting for ready does not automatically Start execution');

    $planned = projectCliCommand(['plan', $id], 0, 'Clarify this fixture plan without executing it.');
    check((string)$planned['project_id'] === $id && (string)$planned['thread_id'] === (string)$created['thread_id'] && isset($planned['run_id']) && $planned['status'] === 'queued', 'Planning queues an ordinary shared run in the project thread');
    $result = projectCliCommand(['result', $id], 3);
    check($result['status'] === 'queued' && $result['answer'] === null && $project->get()['status'] === 'planning', 'Queued planning reports pending and never starts project tasks');
    $raw = Agent\Database::row("SELECT raw->>'content' AS content FROM messages WHERE run_id = ? AND role = 'user'", [$planned['run_id']]);
    check($raw['content'] === 'Clarify this fixture plan without executing it.', 'Planning stdin reaches the durable run unchanged');
    projectCliCommand(['events', $id]);
    projectCliCommand(['delete', $id], 2);

    $foreignUser = Agent\Identity::user(['username' => 'alice']);
    check($foreignUser && (string)$foreignUser['id'] !== (string)$created['owner_id'], 'Private foreign fixture uses a distinct real ERP account');
    $foreign = Agent\Project::create($foreignUser['id'], ['title' => $marker . '-foreign', 'settings' => ['profile' => $profile, 'manual' => true, 'project_manual' => true]]);
    $fixtures[$foreign->id] = ['thread_id' => $foreign->get()['thread_id'], 'owner_id' => (string)$foreignUser['id']];
    $listed = projectCliCommand(['list', '--compact']);
    check(!in_array((string)$foreign->id, array_map('strval', array_column($listed, 'id')), true), 'Private foreign projects are absent from CLI listings');
    foreach (['show', 'result', 'events', 'pause'] as $action) {
        $denied = projectCliCommand([$action, (string)$foreign->id], 1);
        check(strpos($denied['error'], 'local project owner') !== false, 'Foreign private project ' . $action . ' is denied to the real CLI identity');
    }
    if (posix_geteuid() === 0) {
        $denied = projectCliCommand(['pause', $id], 1, '', false);
        check(strpos($denied['error'], 'unprivileged') !== false, 'An actual root process cannot mutate projects');
    }
    Agent\Database::execute("UPDATE runs SET status = 'stopped', error = 'Fixture cleanup before execution', finished_at = NOW() WHERE id = ? AND thread_id = ? AND status = 'queued'", [$planned['run_id'], $created['thread_id']]);
    $deleted = projectCliCommand(['delete', $id, '--confirm']);
    check($deleted['deleted'] === true && !Agent\Database::row('SELECT id FROM projects WHERE id = ?', [$id]) && !Agent\Database::row('SELECT id FROM threads WHERE id = ?', [$created['thread_id']]), 'Confirmed deletion removes the fixture project and its history');
    unset($fixtures[(int)$id]);
} finally {
    foreach ($fixtures as $id => $fixture) {
        $row = Agent\Database::row('SELECT t.title, t.owner_id FROM projects p JOIN threads t ON t.id = p.thread_id WHERE p.id = ? AND p.thread_id = ?', [$id, $fixture['thread_id']]);
        if (!$row) continue;
        if (strpos($row['title'], $marker) !== 0 || (string)$row['owner_id'] !== (string)$fixture['owner_id']) throw new RuntimeException('Refusing cleanup outside captured fixture ownership');
        Agent\Database::execute("UPDATE runs SET status = 'stopped', error = 'Fixture cleanup before execution', finished_at = NOW() WHERE thread_id = ? AND status = 'queued'", [$fixture['thread_id']]);
        (new Agent\Project($id))->delete();
    }
}

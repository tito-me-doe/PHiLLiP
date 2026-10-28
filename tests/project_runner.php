<?php
require __DIR__ . '/helpers.php';

function projectFixtureTodo($title, array $overrides = [])
{
    return array_replace(['title' => $title, 'goal' => 'Produce the requested fixture deliverable', 'requirements' => 'Use the supplied fixture context', 'context' => 'A deterministic local test; no external effects', 'gates' => ['Output contains the verified fixture deliverable']], $overrides);
}
function projectFixtureRun(Agent\Project $project)
{
    foreach ($project->todos() as $todo) if ($todo['status'] === 'working') {
        $run = Agent\Run::claim($todo['thread_id']);
        if (!$run) return null;
        try { Agent\Run::execute($run['id']); } catch (Throwable $e) { /* Reconciliation must record this failure honestly. */ }
        return $run;
    }
    return null;
}
function projectFixtureDrive(Agent\Project $project, $limit = 35)
{
    for ($i = 0; $i < $limit && $project->get()['status'] === 'running'; $i++) {
        Agent\ProjectRunner::tick($project->id);
        projectFixtureRun($project);
    }
    return $project->get();
}

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException('Cannot allocate Project fixture port');
$address = stream_socket_get_name($socket, false); fclose($socket);
$server = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($address) . ' ' . escapeshellarg(__DIR__ . '/fixtures/project_engine.php'), [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
$marker = 'project-runner-' . bin2hex(random_bytes(5));
$owner = 'cli:' . substr(hash('sha256', $marker), 0, 12) . ':33';
$config = ['url' => 'http://' . $address . '/v1/chat/completions', 'model' => 'project-fixture', 'context_window' => 65536, 'max_retries' => 0, 'max_contract_corrections' => 0];
$projects = []; $personaId = null;
$create = function ($suffix, array $profile = []) use (&$projects, $owner, $marker, $config) {
    $project = Agent\Project::create($owner, ['title' => $marker . '-' . $suffix, 'settings' => ['profile' => array_replace($config, $profile), 'tools' => [], 'project_manual' => true]]);
    $projects[] = $project;
    return $project;
};
try {
    usleep(200000);
    $project = $create('nested');
    $parent = $project->addTodo(projectFixtureTodo('Parent rollup'));
    $child = $project->addTodo(projectFixtureTodo('Child deliverable', ['parent_id' => $parent['id']]));
    $project->requestPreflight();
    $planningCount = count((new Agent\Thread($project->get()['thread_id']))->messages());
    Agent\ProjectRunner::tick($project->id);
    check($project->get()['preflight_status'] === 'ready' && $project->get()['status'] === 'planning', 'Preflight ready never automatically starts a project');
    check(count((new Agent\Thread($project->get()['thread_id']))->messages()) === $planningCount && !Agent\Database::row('SELECT r.id FROM runs r JOIN project_todos td ON td.thread_id = r.thread_id WHERE td.project_id = ?', [$project->id]), 'Ephemeral preflight leaves planning history and execution untouched');
    $project->start($owner);
    Agent\ProjectRunner::tick($project->id); Agent\ProjectRunner::tick($project->id);
    $todos = $project->todos();
    check($todos[0]['status'] === 'pending' && $todos[1]['status'] === 'working' && (int)$todos[1]['attempts'] === 1, 'Children run before parents and duplicate ticks do not duplicate dispatch');
    $thread = (new Agent\Thread($todos[1]['thread_id']))->get();
    $globallyClaimable = Agent\Database::row("SELECT id FROM threads WHERE id = ? AND COALESCE((settings->>'manual')::boolean, FALSE) = FALSE", [$thread['id']]);
    check($thread['settings']['manual'] === true && !$globallyClaimable && $thread['settings']['tools'] === ['Agent\\Tools\\Project'] && $thread['owner_id'] === $owner, 'Explicitly managed Project tasks retain the tool ceiling and stay outside the global Run queue');
    projectFixtureRun($project);
    // A newly loaded worker reconciles the completed durable run, without replaying it.
    Agent\ProjectRunner::tick((new Agent\Project($project->id))->id);
    check($project->todos()[1]['status'] === 'done' && (int)$project->todos()[1]['attempts'] === 1, 'Restart reconciliation reviews saved output without replay');
    check(projectFixtureDrive($project)['status'] === 'completed', 'Nested task and parent rollup complete only after their independent gate reviews');
    check(strpos($project->todos()[0]['result'], 'Children: 1') !== false, 'Parent execution receives completed child summaries');

    $retry = $create('retry');
    $retry->addTodo(projectFixtureTodo('Retry deliverable', ['gates' => ['RETRY_ONCE produce verified evidence']]));
    $retry->requestPreflight(); Agent\ProjectRunner::tick($retry->id); $retry->start($owner);
    check(projectFixtureDrive($retry)['status'] === 'completed' && (int)$retry->todos()[0]['attempts'] === 2, 'A failed gate produces bounded corrective execution with persisted feedback');
    $attempts = Agent\Database::rows("SELECT r.thread_id FROM runs r JOIN messages m ON m.run_id = r.id AND m.role = 'user' WHERE m.raw->>'content' LIKE ? ORDER BY r.id", ['%"id":' . $retry->todos()[0]['id'] . ',%']);
    check(count($attempts) >= 2 && $attempts[0]['thread_id'] === $attempts[1]['thread_id'], 'Corrective attempts continue only their own ToDo history');

    $blocked = $create('cap'); $blocked->addTodo(projectFixtureTodo('Unmet deliverable', ['gates' => ['ALWAYS_FAIL missing verification']]));
    $blocked->requestPreflight(); Agent\ProjectRunner::tick($blocked->id); $blocked->start($owner);
    check(projectFixtureDrive($blocked)['status'] === 'blocked' && (int)$blocked->todos()[0]['attempts'] === 3 && $blocked->todos()[0]['status'] === 'blocked', 'Three unsuccessful attempts stop with a durable blocker, never completed');
    $before = $blocked->todos()[0]['attempts']; Agent\ProjectRunner::tick($blocked->id);
    check($blocked->todos()[0]['attempts'] === $before, 'A blocked project is never blindly retried');

    $failed = $create('failed'); $failed->addTodo(projectFixtureTodo('FAIL_RUN task'));
    $failed->requestPreflight(); Agent\ProjectRunner::tick($failed->id); $failed->start($owner);
    check(projectFixtureDrive($failed)['status'] === 'blocked' && (int)$failed->todos()[0]['attempts'] === 1, 'An execution error requires explicit retry rather than replaying possible side effects');

    $gaps = $create('gaps'); $gapTodo = $gaps->addTodo(projectFixtureTodo('PREFLIGHT_GAP task'));
    $gaps->requestPreflight(); Agent\ProjectRunner::tick($gaps->id);
    check($gaps->get()['preflight_status'] === 'gaps', 'Preflight stores actionable model gaps');
    expectException(function () use ($gaps, $owner) { $gaps->start($owner); }, 'ready');
    $gaps->updateTodo($gapTodo['id'], ['title' => 'Fixed task']);
    check($gaps->get()['preflight_status'] === 'unchecked', 'Editing a plan invalidates old readiness');

    $stale = $create('stale', ['model' => 'project-slow-preflight']);
    $staleTodo = $stale->addTodo(projectFixtureTodo('Old plan title'));
    $stale->requestPreflight();
    $worker = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/phill-project-worker') . ' --once --project=' . (int)$stale->id, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $workerPipes);
    try {
        $until = microtime(true) + 5;
        while ($stale->get()['preflight_status'] !== 'checking' && microtime(true) < $until) usleep(10000);
        check($stale->get()['preflight_status'] === 'checking', 'Standalone worker claims durable preflight state');
        check(Agent\ProjectRunner::tick($stale->id) === [], 'A second worker cannot duplicate a locked in-flight preflight');
        $stale->updateTodo($staleTodo['id'], ['title' => 'Changed while checking']);
        while (proc_get_status($worker)['running'] && microtime(true) < $until) usleep(10000);
        check($stale->get()['preflight_status'] === 'unchecked' && $stale->get()['preflight_revision'] === null, 'A stale ready response cannot approve an edited plan');
    } finally { if (is_resource($worker)) { if (proc_get_status($worker)['running']) proc_terminate($worker); proc_close($worker); } }

    $outage = $create('outage', ['model' => 'project-outage']); $outage->addTodo(projectFixtureTodo('Outage task'));
    $outage->requestPreflight(); Agent\ProjectRunner::tick($outage->id);
    check($outage->get()['preflight_status'] === 'error' && $outage->get()['status'] === 'planning', 'A model outage cannot manufacture preflight readiness');

    $expired = $create('expired'); $expired->addTodo(projectFixtureTodo('Expired task'));
    Agent\Database::execute("UPDATE projects SET preflight_status = 'checking', preflight_started_at = NOW() - INTERVAL '3 minutes' WHERE id = ?", [$expired->id]);
    Agent\ProjectRunner::tick($expired->id);
    check($expired->get()['preflight_status'] === 'error', 'Worker restart recovers an expired preflight without claiming readiness');

    $invalid = $create('invalid-review'); $invalid->addTodo(projectFixtureTodo('DUPLICATE_REVIEW task', ['gates' => ['First gate', 'Second gate']]));
    $invalid->requestPreflight(); Agent\ProjectRunner::tick($invalid->id); $invalid->start($owner);
    check(projectFixtureDrive($invalid)['status'] === 'blocked' && $invalid->todos()[0]['status'] !== 'done', 'Duplicate gate indexes never count as complete coverage');

    $paused = $create('paused'); $paused->addTodo(projectFixtureTodo('Pause task'));
    $paused->requestPreflight(); Agent\ProjectRunner::tick($paused->id); $paused->start($owner); Agent\ProjectRunner::tick($paused->id); $paused->pause($owner);
    Agent\ProjectRunner::tick($paused->id);
    check($paused->get()['status'] === 'paused' && Agent\Run::get($paused->todos()[0]['run_id'])['status'] === 'stopped', 'Pause stops queued work and suppresses further dispatch');
    $paused->start($owner);
    check(projectFixtureDrive($paused)['status'] === 'completed' && (int)$paused->todos()[0]['attempts'] === 1, 'Resume safely requeues a paused task that never started without consuming an attempt');

    $persona = Agent\Database::row("INSERT INTO personas (owner_id, name, prompt, visibility, tools) VALUES (?, ?, ?, 'private', ?::jsonb) RETURNING id", [$owner, $marker . '-persona', 'Use the isolated specialist prompt.', json_encode(['Agent\\Tools\\CLI'])]);
    $personaId = $persona['id'];
    $specialist = $create('specialist');
    $specialist->addTodo(projectFixtureTodo('REPORT_RESULT specialist', ['persona_id' => $personaId]));
    $specialist->requestPreflight(); Agent\ProjectRunner::tick($specialist->id); $specialist->start($owner); Agent\ProjectRunner::tick($specialist->id);
    $specialistThread = new Agent\Thread($specialist->todos()[0]['thread_id']);
    $specialistSettings = $specialistThread->get()['settings'];
    check($specialistSettings['assistant_name'] === $marker . '-persona' && $specialistSettings['profile']['model'] === $config['model'] && $specialistSettings['tools'] === ['Agent\\Tools\\Project'] && strpos($specialistThread->messages()[0]['raw']['content'], 'isolated specialist prompt') !== false, 'A ToDo specialist copies persona name and prompt while retaining the project model and tool ceiling');
    check(projectFixtureDrive($specialist)['status'] === 'completed' && $specialist->todos()[0]['evidence']['citation'] === 'fixture:verified', 'Project reports persist real tool evidence for independent gate review');

    Agent\Database::execute('UPDATE personas SET tools = ?::jsonb, contract = ?::jsonb WHERE id = ?', [json_encode(['Agent\\Tools\\Fetch::fetch']), json_encode(['answer' => ['type' => 'string']]), $personaId]);
    $contracted = $create('persona-contract');
    (new Agent\Thread($contracted->get()['thread_id']))->configure(['tools' => ['Agent\\Tools\\Fetch', 'Agent\\Tools\\SearchWeb']]);
    $contracted->addTodo(projectFixtureTodo('Contract specialist', ['persona_id' => $personaId]));
    $contracted->requestPreflight(); Agent\ProjectRunner::tick($contracted->id); $contracted->start($owner); Agent\ProjectRunner::tick($contracted->id);
    $contractSettings = (new Agent\Thread($contracted->todos()[0]['thread_id']))->get()['settings'];
    check($contractSettings['tools'] === ['Agent\\Tools\\Fetch::fetch', 'Agent\\Tools\\Project'], 'Persona method defaults narrow class grants without inheriting unrelated project tools');
    check($contractSettings['contract'] === ['answer' => ['type' => 'string']], 'ToDo execution retains its persona response contract');

    $four = $create('configured-attempts');
    $four->addTodo(projectFixtureTodo('Configured retry cap', ['max_attempts' => 4, 'gates' => ['ALWAYS_FAIL missing verification']]));
    $four->requestPreflight(); Agent\ProjectRunner::tick($four->id); $four->start($owner);
    check(projectFixtureDrive($four)['status'] === 'blocked' && (int)$four->todos()[0]['attempts'] === 4, 'An explicit attempt budget above the default three is honored');

    check(Agent\ProjectRunner::safeTools(['Local', 'CLI', 'Delegate', 'Fetch::fetch', 'Memory']) === ['Agent\\Tools\\Fetch::fetch', 'Agent\\Tools\\Memory', 'Agent\\Tools\\Project'], 'Project execution excludes local, shell and delegation tools');
} finally {
    // Exact fixture owner and captured project IDs only; preserve unrelated user data.
    foreach ($projects as $project) {
        $row = $project->get();
        foreach (Agent\Database::rows('SELECT id FROM threads WHERE owner_id = ? AND title LIKE ?', [$owner, 'Project ' . $project->id . ':%']) as $thread) Agent\Database::execute('DELETE FROM threads WHERE id = ? AND owner_id = ?', [$thread['id'], $owner]);
        Agent\Database::execute('DELETE FROM threads WHERE id = ? AND owner_id = ?', [$row['thread_id'], $owner]);
    }
    if ($personaId) Agent\Database::execute('DELETE FROM personas WHERE id = ? AND owner_id = ?', [$personaId, $owner]);
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
}

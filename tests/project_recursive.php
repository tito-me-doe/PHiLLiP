<?php
require __DIR__ . '/helpers.php';
function recursiveTodo($title, array $overrides = [])
{
    return array_replace(['title' => $title, 'goal' => 'Produce a verified recursive deliverable', 'requirements' => 'Use actual scoped Project tools', 'context' => 'Deterministic HTTP model fixture', 'gates' => ['Verified fixture deliverable']], $overrides);
}
function recursiveRun(Agent\Project $project)
{
    foreach ($project->todos() as $todo) if ($todo['status'] === 'working') {
        $run = Agent\Run::claim($todo['thread_id']);
        if (!$run) return null;
        try { Agent\Run::execute($run['id']); } catch (Throwable $e) { /* Reconcile actual run failures. */ }
        return $run;
    }
    return null;
}
function recursiveStep(Agent\Project $project) { Agent\ProjectRunner::tick($project->id); recursiveRun($project); }
function recursiveDrive(Agent\Project $project, $limit = 100)
{
    for ($i = 0; $i < $limit && $project->get()['status'] === 'running'; $i++) recursiveStep($project);
    return $project->get();
}
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException('Cannot allocate fixture port');
$address = stream_socket_get_name($socket, false); fclose($socket);
$server = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($address) . ' ' . escapeshellarg(__DIR__ . '/fixtures/project_recursive_engine.php'), [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
$marker = 'recursive-' . bin2hex(random_bytes(5));
$owner = 'cli:' . substr(hash('sha256', $marker), 0, 12) . ':33';
$config = ['url' => 'http://' . $address . '/v1/chat/completions', 'model' => 'recursive-fixture', 'context_window' => 65536, 'max_retries' => 0, 'max_contract_corrections' => 0];
$projects = [];
$create = function ($title, array $overrides = [], array $parameters = []) use (&$projects, $marker, $owner, $config) {
    $p = Agent\Project::create($owner, ['title' => $marker . '-' . $title, 'settings' => ['profile' => $config, 'parameters' => $parameters, 'tools' => [], 'project_manual' => true]]);
    $projects[] = $p; $p->addTodo(recursiveTodo($title, $overrides));
    $p->requestPreflight(); Agent\ProjectRunner::tick($p->id); $p->start($owner); return $p;
};
try {
    usleep(200000);
    $batch = $create('Batch yield root', [], ['max_rounds' => 1]);
    recursiveStep($batch);
    $batchRoot = $batch->todos()[0]; $batchRun = Agent\Run::get($batchRoot['run_id']);
    $batchResponse = json_decode($batchRun['result'], true);
    $batchReplies = Agent\Database::rows("SELECT data FROM events WHERE run_id = ? AND type = 'tool.result' ORDER BY id", [$batchRun['id']]);
    check($batchRun['status'] === 'completed' && !empty($batchResponse['yielded']) && count($batchReplies) === 3 && count($batch->todos()) === 2 && $batchRoot['evidence']['batch'] === 'all-tool-results', 'A one-round tool batch finishes every tool reply and yields cleanly without another model call');
    Agent\ProjectRunner::tick($batch->id);
    check($batch->todos()[0]['status'] === 'waiting' && (int)$batch->todos()[0]['attempts'] === 1, 'A forced tool yield reconciles into waiting rather than failed review');
    $p = $create('Root recursive', ['gates' => ['RETRY_ONCE independently integrate the child output']]);
    $questions = [];
    for ($i = 0; $i < 40; $i++) {
        recursiveStep($p);
        $questions = Agent\Database::rows('SELECT * FROM project_decisions WHERE project_id = ? AND target_todo_id IS NULL AND answer IS NULL', [$p->id]);
        if ($questions) break;
    }
    check(count($p->todos()) === 3 && count($questions) === 1, 'HTTP tool calls create root, child and grandchild and escalate to the human');
    for ($i = 0; $i < 4; $i++) Agent\ProjectRunner::tick($p->id);
    $todos = $p->todos();
    check($p->get()['status'] === 'running' && count(array_filter($todos, function ($t) { return $t['status'] === 'waiting'; })) === 3, 'Waiting-only projects remain running without spinning');
    $leafQuestions = Agent\Database::rows('SELECT answer FROM project_decisions WHERE project_id = ? AND todo_id = ? ORDER BY id', [$p->id, $todos[2]['id']]);
    check(count($leafQuestions) === 2 && $leafQuestions[0]['answer'] !== null && $leafQuestions[1]['answer'] === null && $todos[2]['status'] === 'waiting', 'A child with two questions remains waiting after only one answer');
    check(count(Agent\Database::rows("SELECT r.id FROM runs r JOIN project_todos t ON t.run_id = r.id WHERE t.project_id = ? AND r.status IN ('queued','running')", [$p->id])) === 0, 'Waiting nodes release the shared active run slot');
    (new Agent\ProjectWorkflow($p))->answer($questions[0]['id'], 'Use the deterministic fixture choice.', $owner);
    Agent\ProjectRunner::tick($p->id);
    check($p->todos()[0]['status'] === 'working' && (int)$p->todos()[0]['attempts'] === 1, 'An answered root question queues a continuation without charging an attempt');
    $p->pause($owner); $p->start($owner); Agent\ProjectRunner::tick($p->id);
    check($p->todos()[0]['status'] === 'pending' && (int)$p->todos()[0]['attempts'] === 1, 'Pausing an unstarted continuation does not refund or consume a failed attempt');
    check(recursiveDrive($p)['status'] === 'completed', 'Human answer resumes the recursive tree through decisions, revision and independent gates');
    $todos = $p->todos();
    check((int)$todos[0]['attempts'] === 2 && (int)$todos[0]['continuations'] >= 3, 'Continuations preserve failed-attempt budget while parent gate failure consumes a retry');
    check((int)$todos[1]['revision'] === 2 && (int)$todos[2]['revision'] === 2, 'Child rework revises its subtree and requires fresh readiness and completion');
    foreach ($todos as $todo) {
        $threads = Agent\Database::rows("SELECT DISTINCT data->>'thread_id' AS thread_id FROM project_events WHERE project_id = ? AND todo_id = ? AND type = 'todo.dispatched'", [$p->id, $todo['id']]);
        check(count($threads) === 1 && (string)$threads[0]['thread_id'] === (string)$todo['thread_id'] && $todo['status'] === 'done' && $todo['evidence']['fixture'] === 'actual-tool-output' && count($todo['review']['gates']) === count($todo['gates']), 'Each node retains its thread and independently verified tool evidence');
    }
    $failed = Agent\Database::row("SELECT id FROM project_events WHERE project_id = ? AND todo_id = ? AND type = 'todo.gates_unmet'", [$p->id, $todos[0]['id']]);
    check((bool)$failed, 'Passing children do not bypass parent gates');
    $gaps = $create('Root readiness');
    check(recursiveDrive($gaps)['status'] === 'completed' && (int)$gaps->todos()[1]['revision'] === 2, 'Readiness gaps reach the parent and revision resolves them');
    $failure = $create('Root child failure');
    check(recursiveDrive($failure)['status'] === 'completed' && (int)$failure->todos()[1]['revision'] === 2, 'Exhausted child gates escalate locally for parent revision');
    $answerOnly = $create('Root gate budget'); $humanQuestion = null;
    for ($i = 0; $i < 30 && !$humanQuestion; $i++) {
        recursiveStep($answerOnly);
        $humanQuestion = Agent\Database::row('SELECT id FROM project_decisions WHERE project_id = ? AND target_todo_id IS NULL', [$answerOnly->id]);
    }
    check((bool)$humanQuestion, 'Parent answers an exhausted child through the real decision tool');
    for ($i = 0; $i < 3; $i++) Agent\ProjectRunner::tick($answerOnly->id);
    $exhausted = $answerOnly->todos()[1];
    $childRuns = Agent\Database::rows('SELECT id FROM runs WHERE thread_id = ?', [$exhausted['thread_id']]);
    check(count($childRuns) === 1 && (int)$exhausted['attempts'] === 1 && $exhausted['status'] === 'waiting', 'An answer without revision cannot bypass an exhausted child gate budget');
    $loop = $create('Root readiness loop'); $humanQuestion = null;
    for ($i = 0; $i < 35 && !$humanQuestion; $i++) {
        recursiveStep($loop);
        $humanQuestion = Agent\Database::row('SELECT id FROM project_decisions WHERE project_id = ? AND target_todo_id IS NULL', [$loop->id]);
    }
    check((bool)$humanQuestion, 'A parent readiness gap with its own inbound child question escalates upstream');
    Agent\ProjectRunner::tick($loop->id);
    $beforeTurns = $loop->todos()[1]['continuations'];
    $beforeEvents = count($loop->history());
    for ($i = 0; $i < 10; $i++) Agent\ProjectRunner::tick($loop->id);
    check($loop->todos()[1]['continuations'] === $beforeTurns && count($loop->history()) === $beforeEvents, 'Consumed inbound questions do not repeat readiness or burn continuation budget while an upstream answer is pending');
    $budget = $create('Yield forever');
    check(recursiveDrive($budget, 150)['status'] === 'blocked' && (int)$budget->todos()[0]['attempts'] === 1 && (int)$budget->todos()[0]['continuations'] === 64, 'Continuation turns stop at 64 without consuming failed-attempt budget');
    $cap = $create('Gate cap', ['gates' => ['ALWAYS_FAIL'], 'max_attempts' => 2]);
    check(recursiveDrive($cap)['status'] === 'blocked' && (int)$cap->todos()[0]['attempts'] === 2, 'Failed gate retries respect the configured cap');
    $stale = $create('Slow review'); recursiveStep($stale);
    $worker = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/phill-project-worker') . ' --once --project=' . $stale->id, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $workerPipes);
    try {
        $until = microtime(true) + 5;
        while ($stale->todos()[0]['status'] !== 'review' && microtime(true) < $until) usleep(10000);
        check($stale->todos()[0]['status'] === 'review', 'Independent reviewer captures the current revision');
        $stale->pause($owner); $stale->updateTodo($stale->todos()[0]['id'], ['context' => 'Changed during in-flight review.']);
        while (proc_get_status($worker)['running'] && microtime(true) < $until) usleep(10000);
        check((int)$stale->todos()[0]['revision'] === 2 && $stale->todos()[0]['status'] === 'pending' && !$stale->todos()[0]['review'], 'Stale review cannot commit after a real paused human revision');
    } finally { if (is_resource($worker)) { if (proc_get_status($worker)['running']) proc_terminate($worker); proc_close($worker); } }
    $readyRace = $create('Root slow readiness'); recursiveStep($readyRace); Agent\ProjectRunner::tick($readyRace->id);
    $readyChild = $readyRace->todos()[1];
    $worker = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/phill-project-worker') . ' --once --project=' . $readyRace->id, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $workerPipes);
    try {
        $until = microtime(true) + 5; $checking = null;
        while (!$checking && microtime(true) < $until) {
            $checking = Agent\Database::row("SELECT id FROM project_events WHERE project_id = ? AND todo_id = ? AND type = 'todo.readiness.checking'", [$readyRace->id, $readyChild['id']]);
            if (!$checking) usleep(10000);
        }
        check((bool)$checking, 'Dynamic readiness starts against the captured child revision');
        $readyRace->pause($owner); $readyRace->updateTodo($readyChild['id'], ['context' => 'Child definition changed during readiness.']);
        while (proc_get_status($worker)['running'] && microtime(true) < $until) usleep(10000);
        $readyChild = $readyRace->todos()[1];
        check((int)$readyChild['revision'] === 2 && $readyChild['ready_revision'] === null && $readyChild['run_id'] === null, 'A stale readiness response cannot approve or dispatch a revised child');
    } finally { if (is_resource($worker)) { if (proc_get_status($worker)['running']) proc_terminate($worker); proc_close($worker); } }
    foreach ($projects as $project) {
        $errors = Agent\Database::row("SELECT e.id FROM events e JOIN runs r ON r.id = e.run_id JOIN project_todos t ON t.thread_id = r.thread_id WHERE t.project_id = ? AND e.type = 'tool.result' AND e.data->>'_tool_error' = 'true' LIMIT 1", [$project->id]);
        check(!$errors, 'Recursive fixture mutations all succeeded through real tools');
    }
} finally {
    foreach ($projects as $project) {
        $row = $project->get();
        foreach (Agent\Database::rows('SELECT id FROM threads WHERE owner_id = ? AND title LIKE ?', [$owner, 'Project ' . $project->id . ':%']) as $thread) Agent\Database::execute('DELETE FROM threads WHERE id = ? AND owner_id = ?', [$thread['id'], $owner]);
        Agent\Database::execute('DELETE FROM threads WHERE id = ? AND owner_id = ?', [$row['thread_id'], $owner]);
    }
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
}

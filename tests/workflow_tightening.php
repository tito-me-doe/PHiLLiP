<?php
if (PHP_SAPI === 'cli-server') {
    header('Content-Type: application/json');
    if (!isset($_SERVER['HTTP_AUTHORIZATION']) || $_SERVER['HTTP_AUTHORIZATION'] !== 'Bearer PROFILE_CONTRACT_SECRET') {
        http_response_code(401); echo '{"error":"Missing fixture authorization"}'; return;
    }
    $body = json_decode(file_get_contents('php://input'), true);
    $extracting = isset($body['messages'][0]['content']) && strpos($body['messages'][0]['content'], 'Extract durable source-backed knowledge') !== false;
    echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => $extracting ? '{"claims":[],"remove":[]}' : 'PROFILE_AUTH_READY'], 'finish_reason' => 'stop']]]);
    return;
}
require __DIR__ . '/helpers.php';
putenv('PHP_CLI_SERVER_WORKERS');
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$listener) throw new RuntimeException('Cannot reserve fixture port: ' . $error);
$fixtureAddress = stream_socket_get_name($listener, false); fclose($listener);
$server = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($fixtureAddress) . ' ' . escapeshellarg(__FILE__),
    [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
$worker = null; $workerServer = null;
$db = Agent\Database::connection();
$threads = [];
$headers = ['Authorization' => 'Bearer PROFILE_CONTRACT_SECRET'];
try {
    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        if (!is_resource($server) || !proc_get_status($server)['running']) throw new RuntimeException('Authentication fixture process failed to start');
        $socket = @stream_socket_client('tcp://' . $fixtureAddress, $errno, $error, 0.05);
        if ($socket) {
            fclose($socket);
            if (!proc_get_status($server)['running']) throw new RuntimeException('Authentication fixture process exited during startup');
            $ready = true; break;
        }
        usleep(50000);
    }
    if (!$ready) throw new RuntimeException('Authentication fixture did not become ready');
    $config = ['url' => 'http://' . $fixtureAddress . '/v1/chat/completions', 'model' => 'auth-fixture', 'headers' => $headers, 'stream' => false, 'max_retries' => 0];
    $thread = Agent\Thread::create('fixture-workflow', 'Durable projection recovery', ['manual' => true, 'profile' => $config]); $threads[] = $thread;
    $run = $thread->enqueue('fixture-workflow', 'Recover projection');
    Agent\Run::claim($thread->id);
    Agent\Run::event($run['id'], ['type' => 'text.delta', 'data' => ['content' => 'Before ']]);
    $db->prepare("DELETE FROM states WHERE scope = 'run' AND run_id = ?")->execute([$run['id']]);
    $eventId = Agent\Run::event($run['id'], ['type' => 'text.delta', 'data' => ['content' => 'after']]);
    $state = Agent\State::run($run['id']);
    check($state->read()['value']['content'] === 'Before after', 'An event restores a missing projection by replaying durable output');
    Agent\WorkflowState::apply($run['id'], ['type' => 'text.delta', 'data' => ['content' => 'after']], $eventId);
    check($state->read()['value']['content'] === 'Before after', 'Replayed event IDs never append output twice');
    $db->beginTransaction();
    $db->prepare("DELETE FROM states WHERE scope = 'run' AND run_id = ?")->execute([$run['id']]);
    Agent\Run::event($run['id'], ['type' => 'text.delta', 'data' => ['content' => 'rolled back']]);
    $db->rollBack();
    Agent\Run::event($run['id'], ['type' => 'text.delta', 'data' => ['content' => '!']]);
    check($state->read()['value']['content'] === 'Before after!', 'Rolled back projection handles do not poison later worker events');
    check(Agent\Run::execute($run['id']) === 'PROFILE_AUTH_READY', 'Canonical snapshot credential references restore authenticated headers');
    check(Agent\Indexer::process($run['id']) === [], 'Background knowledge extraction restores canonical snapshot credentials');
    check(strpos(json_encode($thread->messages()), 'PROFILE_CONTRACT_SECRET') === false, 'Message snapshots keep credential values private');

    $legacy = $thread->enqueue('fixture-workflow', 'Legacy snapshot');
    $snapshot = json_decode($legacy['settings'], true);
    $snapshot['credentials'] = $snapshot['config']['credentials_ref']; unset($snapshot['config']['credentials_ref']);
    $db->prepare('UPDATE runs SET settings = ?::jsonb WHERE id = ?')->execute([json_encode($snapshot), $legacy['id']]);
    Agent\Run::claim($thread->id);
    check(Agent\Run::execute($legacy['id']) === 'PROFILE_AUTH_READY', 'Previously queued snapshots retain their legacy credential references');
    check(Agent\Indexer::process($legacy['id']) === [], 'Background knowledge extraction retains legacy snapshot credentials');

    $failed = $thread->enqueue('fixture-workflow', 'Missing credentials');
    $snapshot = json_decode($failed['settings'], true); $snapshot['config']['credentials_ref'] = 'credentials.fixture-missing-' . bin2hex(random_bytes(8));
    $db->prepare('UPDATE runs SET settings = ?::jsonb WHERE id = ?')->execute([json_encode($snapshot), $failed['id']]);
    $next = $thread->enqueue('fixture-workflow', 'Continue after setup failure');
    Agent\Run::claim($thread->id);
    expectException(function () use ($failed) { Agent\Run::execute($failed['id']); }, 'credentials are no longer configured');
    check(Agent\Run::get($failed['id'])['status'] === 'failed' && Agent\Run::claim($thread->id)['id'] === $next['id'], 'Execution setup failure releases the one-active-run queue');
    Agent\Run::finishStopped($next['id'], 'fixture cleanup');
    $db->prepare('DELETE FROM runs WHERE id = ?')->execute([$next['id']]);
    Agent\Run::finishStopped($next['id'], 'Source was deliberately removed');
    expectException(function () use ($next) { Agent\Run::get($next['id']); }, 'Run not found');

    $fork = Agent\Thread::create('fixture-workflow', 'Fork garbage collection', ['manual' => true]); $threads[] = $fork;
    $first = $fork->enqueue('fixture-workflow', 'Retained source');
    $second = $fork->enqueue('fixture-workflow', 'Replaced source');
    $messages = $fork->messages(); $firstId = $messages[1]['id']; $secondId = $messages[2]['id'];
    $marker = 'workflow-' . bin2hex(random_bytes(8));
    $retained = Agent\Knowledge::remember($fork->id, 'fixture-workflow', $marker . '-retained', $marker . '-subject', [$firstId, $secondId], 'knows', $marker . '-object');
    $removed = Agent\Knowledge::remember($fork->id, 'fixture-workflow', $marker . '-removed', $marker . '-removed-subject', [$secondId], 'knows', $marker . '-removed-object');
    $fork->edit($secondId, 'Replacement source');
    check(Agent\Knowledge::get($retained)['id'] === $retained, 'Fork keeps claims that still have retained source messages');
    expectException(function () use ($removed) { Agent\Knowledge::get($removed); }, 'Memory not found');
    $q = $db->prepare('SELECT COUNT(*) FROM graph_nodes WHERE key IN (?, ?)'); $q->execute([$marker . '-removed-subject', $marker . '-removed-object']);
    check($q->fetchColumn() == 0, 'Fork removes graph nodes left dangling by unsupported claims');

    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$listener) throw new RuntimeException('Cannot reserve worker fixture port: ' . $error);
    $workerAddress = stream_socket_get_name($listener, false); fclose($listener);
    $workerServer = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($workerAddress) . ' ' . escapeshellarg(__DIR__ . '/fixtures/engine.php'),
        [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        if (!is_resource($workerServer) || !proc_get_status($workerServer)['running']) throw new RuntimeException('Worker fixture process failed to start');
        $socket = @stream_socket_client('tcp://' . $workerAddress, $errno, $error, 0.05);
        if ($socket) {
            fclose($socket);
            if (!proc_get_status($workerServer)['running']) throw new RuntimeException('Worker fixture process exited during startup');
            $ready = true; break;
        }
        usleep(50000);
    }
    if (!$ready) throw new RuntimeException('Worker fixture did not become ready');
    $supervised = Agent\Thread::create('fixture-workflow', 'Deleted worker source', ['manual' => true, 'profile' => [
        'url' => 'http://' . $workerAddress . '/v1/chat/completions', 'model' => 'slow', 'stream' => true, 'max_retries' => 0
    ]]); $threads[] = $supervised;
    $disappearing = $supervised->enqueue('fixture-workflow', 'Source will disappear');
    $supervised->configure(['parameters' => ['model' => 'fast', 'stream' => false]]);
    $following = $supervised->enqueue('fixture-workflow', 'Next source survives');
    $worker = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/phill-worker') . ' --thread ' . $supervised->id,
        [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
    $until = microtime(true) + 10;
    do {
        $q = $db->prepare("SELECT 1 FROM events WHERE run_id = ? AND type = 'text.delta'"); $q->execute([$disappearing['id']]);
        if ($q->fetchColumn()) break;
        usleep(20000);
    } while (microtime(true) < $until);
    check(microtime(true) < $until, 'Supervised child emits output before its source is removed');
    $db->prepare('DELETE FROM runs WHERE id = ?')->execute([$disappearing['id']]);
    $until = microtime(true) + 10;
    do {
        if (Agent\Run::get($following['id'])['status'] === 'completed') break;
        usleep(20000);
    } while (microtime(true) < $until);
    check(Agent\Run::get($following['id'])['status'] === 'completed' && proc_get_status($worker)['running'], 'A deleted child run does not kill the supervisor or block its queue');
} finally {
    if ($db->inTransaction()) $db->rollBack();
    if (is_resource($worker)) { proc_terminate($worker); proc_close($worker); }
    if (is_resource($workerServer)) { proc_terminate($workerServer); proc_close($workerServer); }
    foreach ($threads as $thread) $thread->delete();
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
}

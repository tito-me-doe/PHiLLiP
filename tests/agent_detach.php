<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/cli_process.php';

if (isset($argv[1]) && $argv[1] === '--startup-failure') {
    if (posix_geteuid() === 0) { posix_setgid(33); posix_setuid(33); }
    $agent = Agent\Agents::get($argv[2]); // Establish the caller's healthy connection first.
    putenv('DB_DSN=pgsql:host=127.0.0.1;port=1;dbname=unreachable;connect_timeout=1');
    expectException(function () use ($agent) { Agent\Agents::assign($agent['id'], 'Boot failure must not silently strand this task.'); }, 'did not initialize within 3 seconds');
    check(Agent\Agents::result($agent['id'])['status'] === 'queued', 'Failed bootstrap leaves its task durably queued and explicitly resumable');
    exit(0);
}

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException('Cannot allocate detached worker fixture port');
$address = stream_socket_get_name($socket, false); fclose($socket);
$server = proc_open('exec /usr/bin/setsid ' . escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($address) . ' ' . escapeshellarg(__DIR__ . '/fixtures/agent_engine.php'), [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
$agent = null;
try {
    usleep(150000);
    $profile = ['url' => 'http://' . $address . '/v1/chat/completions', 'model' => 'agents-slow', 'max_retries' => 0, 'context_window' => 262144];
    $created = cliProcess(['agents', 'create', 'detachment-' . bin2hex(random_bytes(5)), '-p', json_encode($profile)], '', true);
    $agent = json_decode($created['out'], true);
    check($created['status'] === 0 && isset($agent['id']), 'Created an isolated detached-worker fixture');
    $command = 'exec /usr/bin/setsid env LOG_MIN_FILE=3 NO_COLOR=1 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/cli_unprivileged.php') . ' agents assign ' . (int)$agent['id'] . ' ' . escapeshellarg('Outlive the assigning terminal process group.');
    $started = microtime(true);
    $caller = proc_open($command, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $streams);
    $callerGroup = proc_get_status($caller)['pid'];
    $out = stream_get_contents($streams[1]); $err = stream_get_contents($streams[2]);
    fclose($streams[1]); fclose($streams[2]);
    check(proc_close($caller) === 0, 'Assignment exits successfully after worker initialization: ' . $err);
    check(microtime(true) - $started < 1.5, 'Startup acknowledgement does not wait for a two-second model response');
    $worker = Agent\Database::row('SELECT worker_pid FROM agents WHERE id = ?', [$agent['id']]);
    $pid = (int)$worker['worker_pid'];
    check($pid > 0 && posix_getpgid($pid) !== $callerGroup && posix_getsid($pid) === $pid, 'Detached worker owns an OS session outside its assigning CLI process group');
    @posix_kill(-$callerGroup, SIGTERM); // Simulate terminal/tool process-group cleanup after command exit.
    $task = json_decode($out, true);
    $finished = cliProcess(['agents', 'wait', (string)$agent['id'], '--run', (string)$task['run_id'], '--timeout', '10'], '', true);
    check($finished['status'] === 0 && json_decode($finished['out'], true)['status'] === 'completed', 'Worker completes after its former terminal process group is cleaned up');

    // A completed run can still have a live supervisor reaping its child. That supervisor
    // legitimately accepts another assignment with its original healthy environment.
    // The bootstrap-failure probe specifically needs a fresh supervisor, so await both
    // its durable idle marker and session-lock release before changing the launch DSN.
    $deadline = microtime(true) + 5;
    do {
        $idle = Agent\Database::row("SELECT a.worker_pid, EXISTS (SELECT 1 FROM pg_locks l WHERE l.locktype = 'advisory' AND l.database = (SELECT oid FROM pg_database WHERE datname = current_database()) AND l.classid = 74296 AND l.objid = a.id::oid AND l.objsubid = 2 AND l.granted) AS alive FROM agents a WHERE a.id = ?", [$agent['id']]);
        $cold = $idle && $idle['worker_pid'] === null && in_array($idle['alive'], [false, 0, '0', 'f'], true);
        if ($cold) break;
        usleep(20000);
    } while (microtime(true) < $deadline);
    check($cold, 'Completed fixture supervisor releases its idle marker and session lock before the cold-start failure probe');

    $probe = proc_open('exec env LOG_MIN_FILE=3 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --startup-failure ' . (int)$agent['id'], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $streams);
    $probeOut = stream_get_contents($streams[1]); $probeError = stream_get_contents($streams[2]);
    fclose($streams[1]); fclose($streams[2]);
    check(proc_close($probe) === 0, 'Bootstrap failure is acknowledged with an actionable error instead of silent success: ' . $probeError);
    echo $probeOut;
    $resumed = cliProcess(['agents', 'resume', (string)$agent['id']], '', true);
    $finished = cliProcess(['agents', 'wait', (string)$agent['id'], '--timeout', '10'], '', true);
    check($resumed['status'] === 0 && $finished['status'] === 0, 'Correcting worker configuration and resuming executes the original queued task');
} finally {
    if ($agent && isset($agent['id'])) {
        for ($i = 0; $i < 3; $i++) cliProcess(['agents', 'stop', (string)$agent['id']], '', true);
        cliProcess(['agents', 'wait', (string)$agent['id'], '--timeout', '5'], '', true);
        cliProcess(['agents', 'remove', (string)$agent['id']], '', true);
    }
    if (is_resource($server)) { @posix_kill(-proc_get_status($server)['pid'], SIGTERM); proc_close($server); }
}

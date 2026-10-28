<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/cli_process.php';

putenv('PHP_CLI_SERVER_WORKERS');
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException('Could not reserve a CLI fixture port');
$address = stream_socket_get_name($socket, false); fclose($socket);
$server = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($address) . ' ' . escapeshellarg(__DIR__ . '/fixtures/cli_engine.php'), [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
$config = ['url' => 'http://' . $address . '/v1/chat/completions', 'model' => 'inspect', 'max_retries' => 0, 'context_window' => 262144];
$db = Agent\Database::connection(); $threads = []; $sessions = []; $credentialRef = null; $async = null;
$marker = 'CLI_THREADS-' . bin2hex(random_bytes(5));

function cliSavedThread(array $result, array &$threads)
{
    $valid = $result['status'] === 0 && preg_match('/\[Info\] Thread ([1-9][0-9]*)\b/', $result['err'], $match);
    check($valid, 'CLI reports a stable saved thread ID on stderr' . ($valid ? '' : ': ' . $result['err']));
    $thread = new Agent\Thread($match[1]); $threads[] = $thread;
    return $thread;
}
function cliThreadSnapshot(Agent\Thread $thread)
{
    return [$thread->get()['settings'], $thread->messages(), Agent\Database::rows('SELECT * FROM runs WHERE thread_id = ? ORDER BY id', [$thread->id])];
}
function cliThreadStart(array $args)
{
    $command = 'exec env LOG_MIN_FILE=3 NO_COLOR=1 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/cli_unprivileged.php');
    foreach ($args as $arg) $command .= ' ' . escapeshellarg($arg);
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $streams);
    fclose($streams[0]); return [$process, $streams];
}

try {
    usleep(200000);
    $result = cliProcess(['-p', json_encode($config), $marker . ' stateless'], '', true);
    check($result['status'] === 0 && strpos($result['err'], '[Info] Thread ') === false && !isset(json_decode($result['out'], true)['tools']), 'Plain invocations remain stateless and tool-free');

    $headers = ['Authorization' => 'Bearer ' . $marker . '-PRIVATE'];
    $credentialRef = 'credentials.' . hash('sha256', json_encode($headers));
    $injected = ['headers' => $headers, 'tools' => ['Agent\\Tools\\Fetch'], 'body' => ['functions' => [['name' => 'legacy_grant']], 'function_call' => 'auto', 'tool_choice' => 'auto']];
    $result = cliProcess(['--thread-id', 'new', '-p', json_encode(array_replace($config, $injected)), $marker . ' first'], '', true);
    $thread = cliSavedThread($result, $threads); $owner = $thread->get()['owner_id'];
    $body = json_decode($result['out'], true);
    check($body['model'] === 'inspect' && count($body['messages']) === 2 && !$body['stream'] && !isset($body['tools']), 'A new saved conversation uses shared system/user history with zero tools');
    check(!isset($body['functions']) && !isset($body['function_call']) && !isset($body['tool_choice']), 'Saved profile legacy function/tool directives cannot silently grant tools');
    check($thread->get()['visibility'] === 'private' && $thread->get()['settings']['manual'] === false, 'New CLI conversations remain private without requiring manual execution ownership');
    check(strpos(json_encode(cliThreadSnapshot($thread)), $marker . '-PRIVATE') === false && strpos($result['err'], $marker . '-PRIVATE') === false, 'Saved inline credentials never enter thread/message snapshots or ordinary diagnostics');
    foreach (['second', 'third', 'fourth'] as $index => $word) {
        $result = cliProcess(['-T', (string)$thread->id, $marker . ' ' . $word], '', true);
        $body = json_decode($result['out'], true);
        $valid = $result['status'] === 0 && $body['model'] === 'inspect' && count($body['messages']) === 4 + 2 * $index;
        check($valid, 'Saved profile and sequential message history survive follow-up ' . $word . ($valid ? '' : ': ' . $result['err']));
        $users = array_values(array_filter($body['messages'], function ($message) { return $message['role'] === 'user'; }));
        check($users[0]['content'] === $marker . ' first' && end($users)['content'] === $marker . ' ' . $word, 'Follow-up ' . $word . ' retains original and latest user messages');
        check(strpos($result['out'], '[Success]') === false && strpos($result['err'], '[Success] Response completed') !== false, 'Success diagnostics remain on stderr, not in the answer');
    }
    $switched = array_replace($config, ['model' => 'inspect-switched']);
    $result = cliProcess(['-T', (string)$thread->id, '-p', json_encode($switched), $marker . ' switched'], '', true);
    $valid = $result['status'] === 0 && json_decode($result['out'], true)['model'] === 'inspect-switched';
    check($valid, 'An explicit profile switch overrides the saved profile without replacing history' . ($valid ? '' : ': ' . $result['err']));
    $result = cliProcess(['-T', (string)$thread->id, $marker . ' still switched'], '', true);
    check($result['status'] === 0 && json_decode($result['out'], true)['model'] === 'inspect-switched', 'Subsequent continuations reuse the switched profile');

    $result = cliProcess(['-T', (string)$thread->id, '-t', 'Fetch', $marker . ' explicit tools'], '', true);
    $body = json_decode($result['out'], true);
    check($result['status'] === 0 && count($body['tools']) === 1 && $body['tools'][0]['function']['name'] === 'Agent_Tools_Fetch_fetch', 'A continuation grants only explicitly named tools for that invocation');
    $result = cliProcess(['-T', (string)$thread->id, $marker . ' no tools'], '', true);
    check($result['status'] === 0 && !isset(json_decode($result['out'], true)['tools']) && $thread->get()['settings']['tools'] === [], 'A later invocation cannot inherit earlier tool grants');

    $foreign = Agent\Thread::create('different-cli-owner', $marker . ' foreign', ['profile' => $config, 'manual' => true]); $threads[] = $foreign;
    $foreign->enqueue('different-cli-owner', 'FOREIGN_PRIVATE_CONTENT'); $before = cliThreadSnapshot($foreign);
    $result = cliProcess(['-T', (string)$foreign->id, '-p', json_encode($config), 'unauthorized'], '', true);
    check($result['status'] === 1 && $result['out'] === '' && strpos($result['err'], 'owned by the current CLI identity') !== false && strpos($result['err'], 'FOREIGN_PRIVATE_CONTENT') === false && cliThreadSnapshot($foreign) === $before, 'A foreign thread cannot be read, mutated, or have its queued run executed');

    $busy = Agent\Thread::create($owner, $marker . ' queued', ['profile' => $config, 'manual' => true]); $threads[] = $busy;
    $busy->enqueue('a-different-author', 'QUEUED_PRIVATE_CONTENT'); $before = cliThreadSnapshot($busy);
    $result = cliProcess(['-T', (string)$busy->id, 'second submission'], '', true);
    check($result['status'] === 1 && $result['out'] === '' && strpos($result['err'], 'Thread is busy') !== false && cliThreadSnapshot($busy) === $before, 'A queued response is refused before enqueue/configuration and is never executed by the CLI');

    $running = Agent\Thread::create($owner, $marker . ' running', ['profile' => array_replace($config, ['model' => 'slow']), 'manual' => false]); $threads[] = $running;
    $async = cliThreadStart(['-T', (string)$running->id, 'slow first response']);
    $started = false;
    for ($i = 0; $i < 100; $i++) {
        $q = $db->prepare("SELECT 1 FROM runs WHERE thread_id = ? AND status = 'running'"); $q->execute([$running->id]);
        if ($q->fetchColumn()) { $started = true; break; } usleep(20000);
    }
    check($started, 'Concurrent fixture reaches a running shared Thread/Run response');
    $before = cliThreadSnapshot($running);
    check($before[0]['manual'] === false, 'Atomic CLI enqueue/claim does not temporarily change thread manual policy');
    $result = cliProcess(['-T', (string)$running->id, 'concurrent duplicate'], '', true);
    check($result['status'] === 1 && $result['out'] === '' && strpos($result['err'], 'Thread is busy') !== false && count($running->messages()) === count($before[1]), 'A concurrent invocation fails without adding or executing a second run');
    $out = stream_get_contents($async[1][1]); $err = stream_get_contents($async[1][2]); fclose($async[1][1]); fclose($async[1][2]);
    $valid = proc_close($async[0]) === 0 && json_decode($out, true)['model'] === 'slow' && $running->get()['settings']['manual'] === false;
    check($valid, 'The original response completes with unchanged manual policy after concurrent refusal' . ($valid ? '' : ': ' . $err)); $async = null;

    foreach (['false' => false, 'true' => true, 'missing' => null] as $kind => $manual) {
        $settings = ['profile' => array_replace($config, ['model' => 'error'])];
        if ($kind !== 'missing') $settings['manual'] = $manual;
        $failed = Agent\Thread::create($owner, $marker . ' failure ' . $kind, $settings); $threads[] = $failed;
        $result = cliProcess(['-T', (string)$failed->id, $marker . ' failed ' . $kind], '', true);
        $saved = $failed->get()['settings']; $runs = Agent\Database::rows('SELECT status FROM runs WHERE thread_id = ?', [$failed->id]);
        check($result['status'] === 1 && $result['out'] === '' && count($failed->messages()) === 2 && $runs[0]['status'] === 'failed', 'Model failure retains saved user history and marks the run failed for manual=' . $kind);
        check($kind === 'missing' ? !array_key_exists('manual', $saved) : $saved['manual'] === $manual, 'Failure preserves original manual flag presence/value for manual=' . $kind);
    }

    $result = cliProcess(['-T', 'new', '-p', json_encode($config), $marker . ' quiet'], '', true, ['LOG_MIN_USER' => '3', 'LOG_MIN_ADMIN' => '3', 'LOG_MIN_CONSOLE' => '3', 'LOG_MIN_DATABASE' => '3']);
    $quiet = cliSavedThread($result, $threads);
    check(preg_match('/^\[Info\] Thread ' . $quiet->id . '\n$/D', $result['err']) === 1 && json_decode($result['out'], true)['model'] === 'inspect', 'Thread result metadata is emitted even when ordinary logging is disabled');

    $interrupted = Agent\Thread::create($owner, $marker . ' interrupted', ['profile' => array_replace($config, ['model' => 'slow']), 'manual' => false]); $threads[] = $interrupted;
    $result = cliProcess(['-T', (string)$interrupted->id, 'interrupt this saved response'], '', true, [], SIGINT);
    $runs = Agent\Database::rows('SELECT status FROM runs WHERE thread_id = ?', [$interrupted->id]);
    check($result['status'] === 130 && $result['out'] === '' && $runs[0]['status'] === 'stopped' && count($interrupted->messages()) === 2 && $interrupted->get()['settings']['manual'] === false, 'Ctrl+C retains saved history, stops its own run, and leaves manual policy unchanged');
    usleep(2600000);

    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $result = cliProcess(['-T', 'new', '-p', json_encode($config), $marker . ' unmapped'], '', true, ['PHILLIP_TEST_UID' => '65534']);
        $unmapped = cliSavedThread($result, $threads);
        check(substr($unmapped->get()['owner_id'], -6) === ':65534', 'An unmapped Unix user owns its own host/UID-scoped conversation');
        $result = cliProcess(['-T', (string)$unmapped->id, 'unmapped follow-up'], '', true, ['PHILLIP_TEST_UID' => '65534']);
        check($result['status'] === 0 && count(json_decode($result['out'], true)['messages']) === 4, 'The same unmapped Unix user can continue its conversation');
        $before = cliThreadSnapshot($unmapped);
        $result = cliProcess(['-T', (string)$unmapped->id, 'other local user'], '', true);
        check($result['status'] === 1 && $result['out'] === '' && cliThreadSnapshot($unmapped) === $before, 'A different Unix identity cannot continue an unmapped user conversation');
        $result = cliProcess(['-T', 'new', '-t', 'Fetch', '-p', json_encode($config), 'root tools']);
        check($result['status'] === 1 && $result['out'] === '' && strpos($result['err'], 'Root') !== false, 'Persisted mode does not bypass root tool restrictions');

        $command = 'exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/cli_session.php') . ' alice';
        $child = proc_open($command, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']], $streams);
        $sid = stream_get_contents($streams[1]); fclose($streams[1]);
        check(proc_close($child) === 0 && preg_match('/^[a-zA-Z0-9,-]{16,256}$/D', $sid), 'Created an isolated frontend session for saved CLI execution');
        $sessionPath = (ini_get('session.save_path') ?: sys_get_temp_dir()) . '/sess_' . $sid; $sessions[] = $sessionPath; chown($sessionPath, 33);
        $result = cliProcess(['-T', 'new', '-s', $sid, '-p', json_encode($config), $marker . ' session'], '', true);
        $sessionThread = cliSavedThread($result, $threads);
        $alice = Agent\Identity::user(['username' => 'alice']);
        check((string)$sessionThread->get()['owner_id'] === (string)$alice['id'], 'A session-backed conversation belongs to the authenticated frontend user');
        $result = cliProcess(['-T', (string)$sessionThread->id, '-s', $sid, 'session follow-up'], '', true);
        check($result['status'] === 0 && count(json_decode($result['out'], true)['messages']) === 4, 'A session-backed owner can continue through frontend access checks');
        $before = cliThreadSnapshot($thread);
        $result = cliProcess(['-T', (string)$thread->id, '-s', $sid, 'foreign session continuation'], '', true);
        check($result['status'] === 1 && $result['out'] === '' && cliThreadSnapshot($thread) === $before, 'A valid session cannot continue a different local identity conversation');
    }
    foreach (['0', '-1', '1e2', '{}'] as $id) {
        $result = cliProcess(['-T', $id, 'invalid']);
        check($result['status'] === 2 && $result['out'] === '' && strpos($result['err'], 'positive integer') !== false, 'Thread reference validation rejects invalid ID ' . $id);
    }
} finally {
    if ($async !== null) { proc_terminate($async[0]); foreach ([1, 2] as $index) if (is_resource($async[1][$index])) fclose($async[1][$index]); proc_close($async[0]); }
    proc_terminate($server); proc_close($server);
    foreach ($threads as $thread) $thread->delete();
    foreach ($sessions as $path) if (is_file($path)) unlink($path);
    if ($credentialRef !== null) $db->prepare('DELETE FROM settings WHERE key = ?')->execute([$credentialRef]);
}

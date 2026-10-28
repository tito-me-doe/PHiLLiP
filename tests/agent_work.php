<?php
// The router uses real reflected Workspace calls; ordinary responses reuse the existing agent fixture.
if (PHP_SAPI === 'cli-server') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || !isset($body['model']) || $body['model'] !== 'agents-workflow') { require __DIR__ . '/fixtures/agent_engine.php'; return; }
    header('Content-Type: application/json');
    $used = 0; $task = null;
    foreach ($body['messages'] as $message) {
        if ($message['role'] === 'tool') $used++;
        if ($message['role'] === 'user') $task = json_decode($message['content'], true);
    }
    $initial = "alpha FILE_PAYLOAD_SECRET\n"; $second = "beta FILE_PAYLOAD_SECRET\n"; $final = "gamma FILE_PAYLOAD_SECRET\n";
    $steps = [
        ['create', ['path' => 'result.txt', 'content' => $initial]],
        ['replace', ['path' => 'result.txt', 'search' => 'alpha', 'replacement' => 'beta']],
        ['write', ['path' => 'result.txt', 'content' => $final, 'expectedSha256' => hash('sha256', $second)]],
        ['run', ['command' => $task['commands'][0]]],
        ['run', ['command' => $task['commands'][1]]],
        ['create', ['path' => 'unapproved.txt', 'content' => 'Must not be written']],
        ['source', ['path' => 'result.txt']]
    ];
    if (isset($steps[$used])) {
        list($method, $arguments) = $steps[$used];
        $name = $method === 'source' ? 'Agent_Tools_Source_read' : 'Agent_Tools_Workspace_' . $method;
        $message = ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'work-fixture-' . $used, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($arguments)]]]];
    } else $message = ['role' => 'assistant', 'content' => 'Workflow completed with confirmed changes and checks.'];
    echo json_encode(['choices' => [['message' => $message, 'finish_reason' => isset($message['tool_calls']) ? 'tool_calls' : 'stop']]]);
    return;
}

require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/cli_process.php';

// Docker starts as root; assert the actual root boundary before switching the actual process UID.
if (posix_geteuid() === 0) {
    expectException(function () { Agent\Agents::work('root-must-never-create', 'Root work is denied'); }, 'unprivileged');
    if (!posix_setgid(33) || !posix_setuid(33)) throw new RuntimeException('Cannot drop work fixture privileges');
}
check(Agent\Runtime::unixIdentity()['uid'] === posix_geteuid() && posix_geteuid() !== 0, 'Work tests use the actual unprivileged Unix identity');

function workCli(array $args, $expected = 0, $input = '')
{
    $response = cliProcess(array_merge(['agents'], $args), $input, true);
    check($response['status'] === $expected, 'Work CLI ' . $args[0] . ' exits ' . $expected . ($response['status'] === $expected ? '' : ': ' . $response['out'] . $response['err']));
    $value = json_decode($response['out'], true);
    check(is_array($value) && json_last_error() === JSON_ERROR_NONE, 'Work CLI stdout is one JSON value');
    return $value;
}

$marker = 'work-' . bin2hex(random_bytes(5));
$directory = sys_get_temp_dir() . '/' . $marker;
if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create work fixture directory');
$original = getcwd(); $server = null;
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException('Cannot allocate work fixture port');
$address = stream_socket_get_name($socket, false); fclose($socket);
$server = proc_open('exec setsid ' . escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($address) . ' ' . escapeshellarg(__FILE__), [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
$config = ['url' => 'http://' . $address . '/v1/chat/completions', 'model' => 'agents-inspect', 'max_retries' => 0, 'context_window' => 262144];
$commands = [['php', '-r', 'echo file_get_contents("result.txt");'], ['php', '-r', 'fwrite(STDERR, "fixture check failed"); exit(7);']];
$db = Agent\Database::connection();
try {
    usleep(200000);
    $invalid = [
        ['files' => ['result.txt', '*.txt']], ['files' => ['result.txt', '../outside.txt']],
        ['files' => ['result.txt', 'missing/leaf.txt']], ['files' => ['result.txt', 'result.txt ']],
        ['files' => null], ['files' => ['path' => 'result.txt']], ['commands' => ['php -v']],
        ['commands' => [['php', '-v'], ['argv' => 'bad']]], ['commands' => null],
        ['commands' => [['php', 7]]], ['key' => 'bad'], ['unknown' => true]
    ];
    foreach ($invalid as $index => $options) {
        $name = $marker . '-invalid-' . $index;
        try { Agent\Agents::work($name, 'Validate before creation', array_replace(['profile' => $config, 'workspace' => $directory], $options)); throw new RuntimeException('Invalid setup accepted'); }
        catch (InvalidArgumentException $error) { check(true, 'Invalid work option ' . $index . ' fails before setup'); }
        catch (RuntimeException $error) { check(strpos($error->getMessage(), 'Workspace parent') !== false, 'Missing parent fails before setup'); }
        check(!(int)Agent\Database::row('SELECT COUNT(*) AS count FROM agents WHERE name = ?', [$name])['count'], 'Invalid setup leaves no agent');
    }
    expectException(function () use ($marker, $config, $directory) { Agent\Agents::work($marker . '-empty', ['content' => ' ', 'tool_calls' => [['id' => 'not-real-text']]], ['profile' => $config, 'workspace' => $directory]); }, 'must contain text');
    expectException(function () use ($marker, $config) { Agent\State::transaction(function () use ($marker, $config) { Agent\Agents::work($marker . '-outer', 'Reject an uncommitted caller', ['profile' => $config]); }); }, 'outside an existing transaction');
    $threadsBefore = (int)Agent\Database::row('SELECT COUNT(*) AS count FROM threads')['count'];
    expectException(function () use ($marker, $config, $directory) {
        Agent\Events::withListener(function ($event) { if ($event['type'] === 'agent.created') throw new RuntimeException('Injected setup failure'); }, function () use ($marker, $config, $directory) {
            Agent\Agents::work($marker . '-rollback', 'Rollback all setup', ['profile' => $config, 'workspace' => $directory, 'files' => ['first.txt', 'second.txt']]);
        });
    }, 'Injected setup failure');
    check(!(int)Agent\Database::row('SELECT COUNT(*) AS count FROM agents WHERE name = ?', [$marker . '-rollback'])['count'] && (int)Agent\Database::row('SELECT COUNT(*) AS count FROM threads')['count'] === $threadsBefore, 'A failure after creation rolls back both agent and private thread');
    check(getcwd() === $original, 'Setup failures restore the caller workspace');

    $work = Agent\Agents::work($marker . '-flow', json_encode(['commands' => $commands]), json_encode(['profile' => array_replace($config, ['model' => 'agents-workflow']), 'tools' => ['Workspace', 'Source::read'], 'team' => $marker, 'workspace' => $directory, 'files' => 'result.txt', 'commands' => [$commands[0], json_encode($commands[1])]]));
    check($work['created'] && $work['approvals'] === ['files' => ['result.txt'], 'commands' => $commands] && !isset($work['profile'], $work['owner_id']), 'One work call creates an agent and returns only the exact explicit file and command grants');
    $finished = Agent\Agents::wait($work['agent_id'], $work['run_id'], 12);
    check($finished['status'] === 'completed', 'Committed work setup launches a detached worker through final completion: ' . json_encode($finished));
    check(file_get_contents($directory . '/result.txt') === "gamma FILE_PAYLOAD_SECRET\n" && !file_exists($directory . '/unapproved.txt'), 'A detached worker creates, replaces and hash-writes its approved file without expanding permission');
    $peek = Agent\Agents::peek($work['agent_id']);
    check($peek['run']['id'] === $work['run_id'] && $peek['run']['status'] === 'completed' && $peek['changes'] === [['path' => 'result.txt', 'sha256' => hash_file('sha256', $directory . '/result.txt'), 'bytes' => filesize($directory . '/result.txt')]], 'Peek deduplicates actual durable mutations by path and keeps the latest confirmed hash');
    check(count($peek['checks']) === 2 && $peek['checks'][0]['exit_code'] === 0 && !isset($peek['checks'][0]['stderr']) && $peek['checks'][1]['exit_code'] === 7 && $peek['checks'][1]['stderr'] === 'fixture check failed', 'Peek reports executed checks with exit status and failure stderr');
    check(count($peek['tool_errors']) === 1 && strpos($peek['tool_errors'][0]['message'], 'Operator approval required') !== false && strpos(json_encode($peek), 'FILE_PAYLOAD_SECRET') === false && strpos(json_encode($peek), 'tool_calls') === false, 'Peek exposes durable tool errors while excluding source contents, command output and raw tool arguments');
    $grants = Agent\State::thread($work['thread_id'])->read()['value']['approvals'];
    check(count($grants) === 3, 'Only the requested file and two command prefixes are saved');
    $cliPeek = workCli(['peek', (string)$work['agent_id']]);
    check($cliPeek['agent_id'] === $work['agent_id'] && $cliPeek['changes'] === $peek['changes'], 'CLI peek reads the same evidence under its real Unix identity');

    $idle = Agent\Agents::create($marker . '-reuse', ['profile' => $config, 'team' => $marker, 'workspace' => $directory, 'persona' => ['name' => 'Preserved fixture persona', 'prompt' => 'Preserve copied configuration.'], 'tools' => ['Source::read']]);
    $saved = (new Agent\Thread($idle['thread_id']))->get()['settings'];
    Agent\Agents::pause($idle['id']);
    $reuse = Agent\Agents::work($idle['id'], 'Queued without changing copied defaults', ['key' => $marker . '-once']);
    $retry = Agent\Agents::work($idle['id'], 'Queued without changing copied defaults', ['key' => $marker . '-once']);
    check(!$reuse['created'] && $reuse['run_id'] === $retry['run_id'] && (new Agent\Thread($idle['thread_id']))->get()['settings'] === $saved, 'Reusing an agent preserves copied configuration and explicit submission keys deduplicate');
    $second = Agent\Agents::work($idle['id'], 'A different unkeyed follow-up');
    check($second['run_id'] !== $reuse['run_id'] && Agent\Agents::get($idle['id'])['queued'] === 2, 'A busy agent accepts follow-ups without implicit deduplication');
    foreach ([['profile' => $config], ['tools' => ['Workspace']], ['files' => 'another.txt'], ['commands' => [$commands[0]]]] as $changes) expectException(function () use ($idle, $changes) { Agent\Agents::work($idle['id'], 'Must reject changes while busy', $changes); }, 'before changing');
    check((new Agent\Thread($idle['thread_id']))->get()['settings'] === $saved && Agent\State::thread($idle['thread_id'])->read()['value'] === [] && Agent\Agents::get($idle['id'])['queued'] === 2, 'Busy setup rejection changes no configuration, grants or assignments');
    Agent\ToolContext::withValues(['agent_id' => $idle['id'], 'thread_id' => $idle['thread_id'], 'run_id' => $reuse['run_id']], function () use ($idle, $work, $marker) {
        expectException(function () use ($marker) { Agent\Agents::work($marker . '-self-approve', 'Try to self-approve', ['files' => 'result.txt']); }, 'cannot approve');
        expectException(function () use ($idle) { Agent\WorkspaceAccess::approve($idle['thread_id'], 'patch', 'result.txt'); }, 'cannot approve');
        expectException(function () use ($work) { Agent\Agents::peek($work['agent_id']); }, 'not owned');
        expectException(function () use ($idle) { Agent\Agents::peek($idle['id']); }, 'not owned');
    });
    Agent\ToolContext::withValues(['frontend' => true], function () use ($idle) { expectException(function () use ($idle) { Agent\Agents::work($idle['id'], 'Frontend setup must fail'); }, 'local CLI identity'); });
    $duplicate = Agent\Agents::create($idle['name'], ['profile' => $config, 'team' => $marker . '-other', 'workspace' => $directory]);
    Agent\Agents::pause($duplicate['id']);
    expectException(function () use ($idle) { Agent\Agents::work($idle['name'], 'Ambiguous name must fail'); }, 'Ambiguous');
    $scoped = Agent\Agents::work($idle['name'], 'Resolve exactly the selected team', ['team' => $marker]);
    check($scoped['agent_id'] === $idle['id'] && Agent\Agents::get($duplicate['id'])['queued'] === 0, 'Explicit team selection cannot accidentally assign the duplicate name elsewhere');
    expectException(function () use ($idle, $directory) { Agent\Agents::work($idle['id'], 'Cannot move an existing worker', ['workspace' => dirname($directory)]); }, 'must match');

    // Durable SQL fixtures exercise bounded summaries and cursor semantics without running imaginary checks.
    $fixtureRun = $reuse['run_id'];
    Agent\Database::execute("UPDATE runs SET status = 'running', started_at = NOW(), heartbeat_at = NOW() WHERE id = ?", [$fixtureRun]);
    Agent\Database::execute("INSERT INTO events (run_id, type, data) SELECT ?, 'tool.result', ?::jsonb FROM generate_series(1, 105)", [$fixtureRun, json_encode(['name' => 'Agent_Tools_Source_read', 'content' => 'SOURCE_EVENT_MUST_NOT_LEAK'])]);
    $lineEvidence = ['path' => 'fixture-lines.php', 'sha256' => str_repeat('a', 64), 'bytes' => 17];
    Agent\Database::execute("INSERT INTO events (run_id, type, data) VALUES (?, 'tool.result', ?::jsonb)", [$fixtureRun, json_encode(['name' => 'Agent_Tools_Workspace_replaceLines', 'content' => json_encode($lineEvidence)])]);
    Agent\Database::execute("INSERT INTO events (run_id, type, data) VALUES (?, 'tool.result', ?::jsonb)", [$fixtureRun, json_encode(['name' => 'Agent_Tools_Workspace_run', 'content' => json_encode(['command' => ['fixture-command'], 'exit_code' => 9, 'timed_out' => false, 'truncated' => true, 'stdout' => 'STDOUT_MUST_NOT_LEAK', 'stderr' => str_repeat('x', 700) . 'TAIL'])])]);
    for ($i = 0; $i < 4; $i++) Agent\Database::execute("INSERT INTO events (run_id, type, data) VALUES (?, 'tool.result', ?::jsonb)", [$fixtureRun, json_encode(['name' => 'Fixture_error_' . $i, '_tool_error' => true, 'content' => str_repeat('é', 500)])]);
    Agent\Database::execute("INSERT INTO events (run_id, type, data) VALUES (?, 'run.completed', ?::jsonb)", [$fixtureRun, json_encode(['content' => str_repeat('é', 1400)])]);
    Agent\Database::execute("INSERT INTO events (run_id, type, data) VALUES (?, 'progress', ?::jsonb)", [$second['run_id'], json_encode(['content' => 'Latest cursor is independent of the selected active run'])]);
    $bounded = Agent\Agents::peek($idle['id']);
    $max = (int)Agent\Database::row('SELECT MAX(e.id) AS id FROM events e JOIN runs r ON r.id = e.run_id WHERE r.thread_id = ?', [$idle['thread_id']])['id'];
    check($bounded['active_run_id'] === $fixtureRun && $bounded['run']['id'] === $fixtureRun && $bounded['queued'] === 2 && $bounded['cursor'] === $max, 'Peek chooses active work before later queued assignments and returns the independent maximum cursor');
    check(count($bounded['tool_errors']) === 3 && $bounded['tool_errors'][0]['name'] === 'Fixture_error_1' && mb_strlen($bounded['tool_errors'][0]['message']) === 400 && mb_strlen($bounded['run']['answer']) === 1200 && substr($bounded['checks'][0]['stderr'], -4) === 'TAIL' && strlen($bounded['checks'][0]['stderr']) === 600, 'Peek bounds UTF-8 answers and last-three errors and retains only a failure stderr tail');
    check(strpos(json_encode($bounded), 'MUST_NOT_LEAK') === false && $bounded['changes'] === [$lineEvidence], 'Bounded peek preserves replaceLines result evidence and excludes Source payloads and command stdout');
    Agent\Database::execute("UPDATE runs SET status = 'stopped', finished_at = NOW() WHERE id = ?", [$fixtureRun]);
    Agent\Agents::stop($idle['id']);
    $stateBefore = Agent\State::thread($idle['thread_id'])->read()['value'];
    expectException(function () use ($idle, $directory) {
        Agent\Events::withListener(function ($event) { if ($event['type'] === 'agent.configured') throw new RuntimeException('Injected configure failure'); }, function () use ($idle, $directory) {
            Agent\Agents::work($idle['id'], 'Rollback changed defaults and grants', ['tools' => ['Workspace'], 'files' => 'later.txt']);
        });
    }, 'Injected configure failure');
    check((new Agent\Thread($idle['thread_id']))->get()['settings'] === $saved && Agent\State::thread($idle['thread_id'])->read()['value'] === $stateBefore, 'Failed reconfiguration restores existing defaults and grants');
    $foreignUid = posix_geteuid() === 34 ? 35 : 34;
    Agent\Database::execute('UPDATE agents SET unix_uid = ? WHERE id = ?', [$foreignUid, $duplicate['id']]);
    try {
        expectException(function () use ($duplicate) { Agent\Agents::work($duplicate['id'], 'Foreign identity cannot be reused'); }, 'not owned');
        expectException(function () use ($duplicate) { Agent\Agents::peek($duplicate['id']); }, 'not owned');
    } finally { Agent\Database::execute('UPDATE agents SET unix_uid = ? WHERE id = ?', [posix_geteuid(), $duplicate['id']]); }
    $cliWork = workCli(['work', (string)$idle['id'], 'CLI follow-up after stop'], 0, 'PIPED_WORK_CONTEXT');
    check($cliWork['agent_id'] === $idle['id'] && !$cliWork['created'] && $cliWork['approvals'] === ['files' => [], 'commands' => []], 'CLI work shares the API reuse and approval contract');
    $stored = Agent\Database::row("SELECT raw->>'content' AS content FROM messages WHERE run_id = ? AND role = 'user'", [$cliWork['run_id']]);
    check(strpos($stored['content'], 'CLI follow-up after stop') !== false && strpos($stored['content'], 'PIPED_WORK_CONTEXT') !== false, 'CLI work appends piped context to an argv task');
    $waited = workCli(['work', $idle['name'], 'Wait for the selected team worker', '--team', $marker, '--timeout', '0'], 3);
    check($waited['agent_id'] === $idle['id'] && $waited['status'] === 'queued', 'CLI work waits by resolved agent ID when another team shares its name');
} finally {
    chdir($original);
    $rows = Agent\Database::rows('SELECT id, thread_id FROM agents WHERE name LIKE ? ORDER BY depth DESC, id DESC', [$marker . '%']);
    foreach ($rows as $row) Agent\Agents::stop($row['id']);
    $deadline = microtime(true) + 5;
    do {
        $running = (int)Agent\Database::row("SELECT COUNT(*) AS count FROM runs r JOIN agents a ON a.thread_id = r.thread_id WHERE a.name LIKE ? AND r.status = 'running'", [$marker . '%'])['count'];
        if (!$running) break;
        usleep(100000);
    } while (microtime(true) < $deadline);
    foreach ($rows as $row) $db->prepare('DELETE FROM threads WHERE id = ?')->execute([$row['thread_id']]);
    if (is_resource($server)) { $pid = proc_get_status($server)['pid']; if ($pid) @posix_kill(-$pid, SIGTERM); proc_terminate($server); proc_close($server); }
    foreach (['result.txt', 'unapproved.txt'] as $file) if (is_file($directory . '/' . $file)) unlink($directory . '/' . $file);
    rmdir($directory);
}

<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/cli_process.php';

// Exercise delegate contexts in a real unprivileged process, not forged frontend sessions.
if (isset($argv[1]) && $argv[1] === '--delegate-probe') {
    if (posix_geteuid() === 0 && (!posix_setgid(33) || !posix_setuid(33))) throw new RuntimeException('Cannot drop delegation probe privileges');
    $parent = Agent\Agents::get($argv[2]); $other = $argv[3]; $marker = $argv[4];
    $run = Agent\Database::row('SELECT id FROM runs WHERE thread_id = ? ORDER BY id DESC LIMIT 1', [$parent['thread_id']]);
    $created = [];
    Agent\ToolContext::withValues(['agent_id' => $parent['id'], 'thread_id' => $parent['thread_id'], 'run_id' => $run['id']], function () use ($other, $marker, &$created) {
        expectException(function () use ($other) { Agent\Agents::get($other); }, 'not owned');
        expectException(function () use ($marker) { Agent\Agents::create($marker . '-excess', ['tools' => ['BrowseWeb']]); }, 'exceeds');
        $child = Agent\Agents::create($marker . '-child', ['tools' => ['Fetch::fetch']]); $created[] = $child;
        check($child['depth'] === 1 && $child['parent_id'] !== null, 'A delegated child records direct parent and bounded depth');
        Agent\Agents::pause($child['id']);
        $childRun = Agent\Agents::assign($child['id'], 'Do not execute this paused scope probe');
        Agent\ToolContext::withValues(['agent_id' => $child['id'], 'thread_id' => $child['thread_id'], 'run_id' => $childRun['run_id']], function () use ($child, $marker, &$created) {
            expectException(function () use ($child) { Agent\Agents::get($child['parent_id']); }, 'not owned');
            expectException(function () use ($marker) { Agent\Agents::create($marker . '-broader', ['tools' => ['Fetch']]); }, 'exceeds');
            $grandchild = Agent\Agents::create($marker . '-grandchild', ['tools' => ['Fetch::fetch']]); $created[] = $grandchild;
            check($grandchild['parent_id'] === $child['id'] && $grandchild['depth'] === 2 && $grandchild['parent_thread_id'] === $child['parent_thread_id'], 'Grandchildren preserve root lineage and cannot widen inherited method grants');
            Agent\Agents::pause($grandchild['id']); $grandRun = Agent\Agents::assign($grandchild['id'], 'Paused grandchild');
            Agent\ToolContext::withValues(['agent_id' => $grandchild['id'], 'thread_id' => $grandchild['thread_id'], 'run_id' => $grandRun['run_id']], function () use ($marker) {
                expectException(function () use ($marker) { Agent\Agents::create($marker . '-too-deep'); }, 'depth limit');
            });
        });
        check(count(Agent\Agents::all()) === 2, 'A running parent sees only its descendants, not unrelated teams or itself');
    });
    exit(0);
}

function agentCommand(array $args, $expected = 0, $input = '', array $environment = [])
{
    $response = cliProcess(array_merge(['agents'], $args), $input, true, $environment);
    check($response['status'] === $expected, 'Agent CLI ' . implode(' ', array_slice($args, 0, 2)) . ' exits ' . $expected . ($response['status'] === $expected ? '' : ': ' . $response['err'] . ' ' . $response['out']));
    $value = json_decode($response['out'], true);
    check(is_array($value) && json_last_error() === JSON_ERROR_NONE && strpos($response['out'], '[Success]') === false, 'Agent management stdout is uncontaminated machine-readable JSON');
    return $value;
}
function agentUntil($callback, $label, $seconds = 8)
{
    $until = microtime(true) + $seconds;
    do { if (call_user_func($callback)) { check(true, $label); return; } usleep(50000); } while (microtime(true) < $until);
    check(false, $label);
}

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException('Cannot allocate agent fixture port');
$address = stream_socket_get_name($socket, false); fclose($socket);
putenv('PHP_CLI_SERVER_WORKERS=4');
$server = proc_open('exec setsid ' . escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($address) . ' ' . escapeshellarg(__DIR__ . '/fixtures/agent_engine.php'), [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
putenv('PHP_CLI_SERVER_WORKERS');
$marker = 'agents-' . bin2hex(random_bytes(5));
$config = ['url' => 'http://' . $address . '/v1/chat/completions', 'model' => 'agents-inspect', 'max_retries' => 0, 'context_window' => 262144];
$slow = array_replace($config, ['model' => 'agents-slow']);
$db = Agent\Database::connection(); $personaId = null; $credential = null;
try {
    usleep(200000);
    $help = cliProcess(['agents', 'help'], '', true);
    check($help['status'] === 0 && strpos($help['out'], 'Usage: ok-phill agents') === 0, 'Agent orchestration help does not need a model request');
    $bareHelp = cliProcess(['agents'], '', true);
    check($bareHelp['status'] === 0 && strpos($bareHelp['out'], 'Usage: ok-phill agents') === 0, 'Bare agents namespace prints help instead of unexpectedly querying a model');
    agentCommand(['misspelled-action'], 2);
    foreach ([['show'], ['create', 'name', '--session-id', 'x'], ['assign', 'name', '--profile', 'bad'], ['stop', 'name', '--team', 'x'], ['wait', 'name', '--timeout', '61'], ['create', 'name', '-t', 'Fetch', '-t', 'Memory']] as $bad) agentCommand($bad, 2);
    $persona = cliProcess(['personas', 'save', json_encode(['name' => $marker . '-persona', 'prompt' => 'Keep fixture history isolated.'])], '', true);
    $persona = json_decode($persona['out'], true); $personaId = $persona['id'];
    $a = agentCommand(['create', $marker . '-a', '--team', $marker, '--persona', (string)$personaId, '-p', json_encode($slow)]);
    $b = agentCommand(['create', $marker . '-b', '--team', $marker, '-p', json_encode($slow)]);
    check($a['thread_id'] !== $b['thread_id'] && $a['owner_id'] === $b['owner_id'] && !$a['paused'], 'Different agents own separate durable private threads under one local identity');
    check(agentCommand(['show', (string)$a['id']])['id'] === $a['id'] && count(agentCommand(['list', '--team', $marker])) === 2, 'Agent IDs and team-scoped listings resolve their saved workers');
    agentCommand(['create', $a['name'], '--team', $marker, '-p', json_encode($config)], 1);
    agentCommand(['create', $a['name'], '--team', $marker . '-other', '-p', json_encode($config)]);
    agentCommand(['show', $a['name']], 1);
    check(count(agentCommand(['list', '--team', $marker])) === 2, 'Duplicate names in another team require an explicit agent ID and do not pollute team listings');
    $foreignUid = $a['unix_uid'] === 34 ? 35 : 34;
    if (posix_geteuid() === 0) {
        foreach (['show', 'pause', 'resume', 'stop', 'remove'] as $action) {
            $denied = agentCommand([$action, (string)$a['id']], 1, '', ['PHILLIP_TEST_UID' => (string)$foreignUid]);
            check(strpos($denied['error'], 'not owned') !== false, 'A different real Unix UID cannot ' . $action . ' the agent');
        }
    } else {
        $unavailable = cliProcess(['agents', 'show', (string)$a['id']], '', true, ['PHILLIP_TEST_UID' => (string)$foreignUid]);
        check($unavailable['status'] === 1 && $unavailable['out'] === '' && strpos($unavailable['err'], 'cannot switch Unix UID') !== false, 'An unavailable UID switch fails before the CLI runs under the wrong identity');
        // Keep the caller's real UID; make only the fixture record foreign to exercise the same ownership boundary.
        $db->prepare('UPDATE agents SET unix_uid = ? WHERE id = ?')->execute([$foreignUid, $a['id']]);
        try {
            foreach (['show', 'pause', 'resume', 'stop', 'remove'] as $action) {
                $denied = agentCommand([$action, (string)$a['id']], 1);
                check(strpos($denied['error'], 'not owned') !== false, 'The real Unix caller cannot ' . $action . ' an agent stored under another UID');
            }
        } finally { $db->prepare('UPDATE agents SET unix_uid = ? WHERE id = ?')->execute([$a['unix_uid'], $a['id']]); }
    }
    $db->prepare('UPDATE agents SET hostname = ? WHERE id = ?')->execute(['foreign-host-fixture', $b['id']]);
    agentCommand(['show', (string)$b['id']], 1);
    $db->prepare('UPDATE agents SET hostname = ? WHERE id = ?')->execute([gethostname(), $b['id']]);

    agentCommand(['pause', (string)$a['id']]);
    $runs = [];
    foreach (['FIRST_ALPHA', 'SECOND_ALPHA', 'THIRD_ALPHA'] as $task) $runs[] = agentCommand(['assign', (string)$a['id'], $task]);
    check(count(array_unique(array_column($runs, 'run_id'))) === 3 && agentCommand(['show', (string)$a['id']])['queued'] === 3, 'Paused agents durably queue three distinct follow-ups without executing');
    agentCommand(['configure', (string)$a['id'], '-p', json_encode($config)], 1);
    agentCommand(['remove', (string)$a['id']], 1);
    agentCommand(['wait', (string)$a['id'], '--run', (string)$runs[0]['run_id'], '--timeout', '0'], 3);
    agentCommand(['resume', (string)$a['id']]);
    $otherRun = agentCommand(['assign', (string)$b['id'], 'ONLY_BETA']);
    $otherResult = agentCommand(['wait', (string)$b['id'], '--run', (string)$otherRun['run_id'], '--timeout', '10']);
    $firstResult = agentCommand(['wait', (string)$a['id'], '--run', (string)$runs[0]['run_id'], '--timeout', '10']);
    $overlap = Agent\Database::row('SELECT COUNT(*) AS count FROM runs a JOIN runs b ON a.started_at < b.finished_at AND b.started_at < a.finished_at WHERE a.thread_id = ? AND b.id = ?', [$a['thread_id'], $otherRun['run_id']]);
    $detail = (int)$overlap['count'] > 0 ? '' : ': ' . json_encode(Agent\Database::rows('SELECT id, thread_id, started_at, finished_at FROM runs WHERE thread_id IN (?, ?) ORDER BY id', [$a['thread_id'], $b['thread_id']]));
    check((int)$overlap['count'] > 0, 'Separate agents have overlapping execution intervals rather than globally serializing model work' . $detail);
    foreach ($runs as $index => $queued) {
        $result = agentCommand(['wait', (string)$a['id'], '--run', (string)$queued['run_id'], '--timeout', '10']);
        $body = json_decode($result['answer'], true);
        $users = array_values(array_filter($body['messages'], function ($message) { return $message['role'] === 'user'; }));
        check(count($users) === $index + 1 && $users[$index]['content'] === ['FIRST_ALPHA', 'SECOND_ALPHA', 'THIRD_ALPHA'][$index], 'Queued turn ' . ($index + 1) . ' sees only sequential history up to its own submission');
        check(strpos($result['answer'], 'ONLY_BETA') === false && strpos($body['messages'][0]['content'], $marker . '-persona') !== false, 'Agent A retains its persona and never receives another agent\'s history');
    }
    check(strpos($otherResult['answer'], 'FIRST_ALPHA') === false, 'Agent B receives no raw parent or neighboring-agent history');
    $events = agentCommand(['events', (string)$a['id']]);
    $completed = array_values(array_filter($events, function ($event) { return $event['type'] === 'run.completed'; }));
    check(count($completed) === 3 && array_column($completed, 'run_id') === array_column($runs, 'run_id'), 'Persisted progress events preserve sequential run completion after the assigning CLI exits');
    check(agentCommand(['events', (string)$a['id'], '--after', (string)end($events)['id']]) === [], 'Progress cursors do not replay old events');
    agentCommand(['result', (string)$a['id'], '--run', (string)$otherRun['run_id']], 1);

    agentCommand(['configure', (string)$a['id'], '-p', json_encode($config)]);
    agentCommand(['pause', (string)$a['id']]);
    $once = agentCommand(['assign', (string)$a['id'], 'Exactly once', '--key', $marker . '-once']);
    $twice = agentCommand(['assign', (string)$a['id'], 'Exactly once', '--key', $marker . '-once']);
    check($once['run_id'] === $twice['run_id'], 'Retrying an assignment returns the same durable run instead of duplicating work');
    agentCommand(['assign', (string)$a['id'], 'Different payload', '--key', $marker . '-once'], 1);
    check(agentCommand(['stop', (string)$a['id']])['stop_level'] === 0, 'Stopping a paused queue cancels pending work without inventing an active stop level');
    agentCommand(['result', (string)$a['id'], '--run', (string)$once['run_id']], 1);
    check(Agent\Run::get($once['run_id'])['status'] === 'stopped', 'Queued cancellation reaches a terminal database state');
    $afterCancel = agentCommand(['assign', (string)$a['id'], 'Continue after queue cancellation']);
    agentCommand(['resume', (string)$a['id']]);
    $afterCancelResult = agentCommand(['wait', (string)$a['id'], '--run', (string)$afterCancel['run_id'], '--timeout', '10']);
    check(strpos($afterCancelResult['answer'], 'Exactly once') === false && strpos($afterCancelResult['answer'], 'FIRST_ALPHA') !== false, 'Never-started cancelled tasks remain in the database but cannot leak into a later model request');

    agentCommand(['configure', (string)$b['id'], '-p', json_encode(array_replace($config, ['model' => 'agents-stall']))]);
    $stalled = agentCommand(['assign', (string)$b['id'], 'Interrupt this running response']);
    agentUntil(function () use ($stalled) { return Agent\Run::get($stalled['run_id'])['status'] === 'running'; }, 'Detached worker starts a running response before progressive stop testing');
    $firstStop = agentCommand(['stop', (string)$b['id']]);
    $secondStop = agentCommand(['stop', (string)$b['id']]);
    $thirdStop = agentCommand(['stop', (string)$b['id']]);
    check($firstStop['stop_level'] === 1 && $secondStop['stop_level'] === 2 && in_array($thirdStop['stop_level'], [0, 3], true), 'Repeated stop requests escalate shoulder-tap to cancellation and forced stop, or recognize terminal cleanup');
    $cancelled = agentCommand(['wait', (string)$b['id'], '--run', (string)$stalled['run_id'], '--timeout', '10'], 1);
    check($cancelled['status'] === 'stopped' && agentCommand(['show', (string)$b['id']])['paused'], 'Stopped workers clean up and do not silently advance their queue');
    agentCommand(['configure', (string)$b['id'], '-p', json_encode($config)]);
    $resumed = agentCommand(['assign', (string)$b['id'], 'Piped task'], 0, "Extra task data\n");
    agentCommand(['resume', (string)$b['id']]);
    $result = agentCommand(['wait', (string)$b['id'], '--run', (string)$resumed['run_id'], '--timeout', '10']);
    check(strpos($result['answer'], 'Extra task data') !== false, 'An agent resumes after cancellation using the same CLI stdin contract');
    agentCommand(['configure', (string)$b['id'], '-p', json_encode(array_replace($config, ['model' => 'agents-error']))]);
    $failedRun = agentCommand(['assign', (string)$b['id'], 'Surface a transport failure']);
    $failedResult = agentCommand(['wait', (string)$b['id'], '--run', (string)$failedRun['run_id'], '--timeout', '10'], 1);
    check($failedResult['status'] === 'failed' && is_string($failedResult['error']) && $failedResult['error'] !== '', 'Failed model responses are terminal machine-readable failures, never successful empty answers');

    if (posix_geteuid() === 0) {
        $root = cliProcess(['agents', 'create', $marker . '-root-tools', '-t', 'Fetch', '-p', json_encode($config)]);
        check($root['status'] === 1 && isset(json_decode($root['out'], true)['error']) && strpos($root['err'], 'Root') !== false, 'Root cannot grant tools through the durable agent pathway');
    } else {
        $native = cliProcess(['agents', 'create', $marker . '-native-tools', '--team', $marker, '-t', 'Fetch', '-p', json_encode($config)]);
        check($native['status'] === 0 && json_decode($native['out'], true)['unix_uid'] === posix_geteuid(), 'A native unprivileged CLI grants tools under its real Unix UID');
    }
    $headerConfig = array_replace($config, ['headers' => ['Authorization' => 'Bearer ' . $marker . '-SECRET']]);
    $credentials = agentCommand(['create', $marker . '-credentials', '--team', $marker, '-p', json_encode($headerConfig)]);
    $credential = 'credentials.' . hash('sha256', json_encode($headerConfig['headers']));
    $secretRun = agentCommand(['assign', (string)$credentials['id'], 'Inspect sanitized configuration']);
    $safeResult = agentCommand(['wait', (string)$credentials['id'], '--run', (string)$secretRun['run_id'], '--timeout', '10']);
    check(strpos(json_encode([$credentials, $safeResult, agentCommand(['events', (string)$credentials['id']]), agentCommand(['list', '--team', $marker])]), $marker . '-SECRET') === false, 'Management results, run answers and progress events never reveal configured HTTP credentials');
    $inline = agentCommand(['create', $marker . '-inline', '--team', $marker, '-p', json_encode($config), '--persona', json_encode(['name' => $marker . '-inline-name', 'prompt' => 'A trusted local inline prompt'])]);
    $inlineThread = new Agent\Thread($inline['thread_id']);
    check($inlineThread->get()['settings']['assistant_name'] === $marker . '-inline-name', 'Trusted CLI JSON personas use the same canonical prompt/default contract as saved personas');
    agentCommand(['configure', (string)$inline['id'], '--persona', json_encode(['name' => $marker . '-updated-inline', 'prompt' => 'Changed local inline instructions'])]);
    check($inlineThread->get()['settings']['assistant_name'] === $marker . '-updated-inline' && strpos($inlineThread->messages()[0]['raw']['content'], 'Changed local inline instructions') !== false, 'Idle agent configuration accepts inline persona JSON without stale assistant labels or prompts');

    $parent = agentCommand(['create', $marker . '-parent', '--team', $marker, '-p', json_encode($config), '-t', 'Fetch']);
    agentCommand(['pause', (string)$parent['id']]); agentCommand(['assign', (string)$parent['id'], 'Paused parent scope']);
    $probe = proc_open('exec env LOG_MIN_FILE=3 NO_COLOR=1 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --delegate-probe ' . (int)$parent['id'] . ' ' . (int)$credentials['id'] . ' ' . escapeshellarg($marker), [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $streams);
    $probeOut = stream_get_contents($streams[1]); $probeErr = stream_get_contents($streams[2]); fclose($streams[1]); fclose($streams[2]);
    $probeStatus = proc_close($probe);
    check($probeStatus === 0, 'Delegation tool ceilings, direct lineage, bounded depth and scoped visibility hold in a real Unix child process' . ($probeStatus === 0 ? '' : ': ' . $probeOut . $probeErr));
    agentCommand(['stop', '--team', $marker]);
    agentUntil(function () use ($db, $marker) { return !(int)Agent\Database::row('SELECT COUNT(*) AS count FROM runs r JOIN agents a ON a.thread_id = r.thread_id WHERE a.name LIKE ? AND r.status = ?', [$marker . '%', 'running'])['count']; }, 'All fixture agent responses are terminal before removal');
    agentCommand(['remove', (string)$credentials['id']]);
    agentCommand(['show', (string)$credentials['id']], 1);
} finally {
    $rows = Agent\Database::rows('SELECT id, thread_id FROM agents WHERE name LIKE ? ORDER BY depth DESC, id DESC', [$marker . '%']);
    foreach ($rows as $row) cliProcess(['agents', 'stop', (string)$row['id']], '', true);
    $until = microtime(true) + 5;
    do {
        $active = Agent\Database::row("SELECT COUNT(*) AS count FROM runs r JOIN agents a ON a.thread_id = r.thread_id WHERE a.name LIKE ? AND r.status = 'running'", [$marker . '%']);
        if (!(int)$active['count']) break;
        foreach ($rows as $row) cliProcess(['agents', 'stop', (string)$row['id']], '', true);
        usleep(100000);
    } while (microtime(true) < $until);
    foreach ($rows as $row) $db->prepare('DELETE FROM threads WHERE id = ?')->execute([$row['thread_id']]);
    if ($personaId) $db->prepare('DELETE FROM personas WHERE id = ?')->execute([$personaId]);
    if ($credential) $db->prepare('DELETE FROM settings WHERE key = ?')->execute([$credential]);
    if (is_resource($server)) {
        $pid = proc_get_status($server)['pid']; if ($pid && function_exists('posix_kill')) @posix_kill(-$pid, SIGTERM);
        proc_terminate($server); proc_close($server);
    }
}

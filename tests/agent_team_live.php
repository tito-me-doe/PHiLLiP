<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/cli_process.php';

use Agent\Runtime;

// Explicit live-model smoke test of the agent CLI team flow.
// php tests/agent_team_live.php [PROFILE]
if (Runtime::unixIdentity()['root']) throw new RuntimeException('Run this live CLI test as an unprivileged Unix user');
$profile = isset($argv[1]) ? $argv[1] : 'nemotron-vl-work';
$suffix = substr(uniqid('', true), -8);
$team = 'live_team_' . $suffix;
$agentPhp = 'live_agent_php_' . $suffix;
$agentResearch = 'live_agent_research_' . $suffix;
$created = [];
$runPhp = null;
$runResearch = null;
$followupId = null;
$token = 'TURQUOISE-LIVE-' . $suffix;

function cli(array $args)
{
    $result = cliProcess(array_merge(['agents'], $args));
    return ['status' => $result['status'], 'stdout' => $result['out'], 'stderr' => $result['err']];
}

function cliJson(array $args)
{
    $out = cli($args);
    check($out['status'] === 0, 'agents ' . $args[0] . ' exited 0 (got ' . $out['status'] . '): ' . ($out['status'] ? $out['stderr'] : ''));
    $decoded = json_decode($out['stdout'], true);
    check($decoded !== null && is_array($decoded), 'agents ' . $args[0] . ' returned JSON on stdout');
    return $decoded;
}

function resultJson(array $args)
{
    $out = cli($args);
    check(in_array($out['status'], [0, 1, 3], true), 'agents ' . implode(' ', $args) . ' exited ' . $out['status'] . ' (expected 0/1/3)');
    $decoded = json_decode($out['stdout'], true);
    check($decoded !== null && is_array($decoded), 'agents ' . $args[0] . ' returned JSON on stdout');
    return $decoded;
}

function stopAndWait($name, $runId, $label)
{
    $stop = cli(['stop', $name]);
    check($stop['status'] === 0, 'stop ' . $label);
    $until = microtime(true) + 60;
    while (microtime(true) < $until) {
        $res = resultJson(['result', $name, '--run', (string)$runId]);
        if (!in_array($res['status'], ['queued', 'running'], true)) break;
        usleep(200000);
    }
    $res = resultJson(['result', $name, '--run', (string)$runId]);
    check(!in_array($res['status'], ['queued', 'running'], true), $label . ' run reached terminal status (got ' . $res['status'] . ')');
    return $res;
}

function toolStartSourceEvent($events, $runId)
{
    foreach ($events as $e) {
        $data = isset($e['data']) && is_array($e['data']) ? $e['data'] : (is_string($e['data'] ?? null) ? json_decode($e['data'], true) : null);
        if (isset($e['type']) && $e['type'] === 'tool.start' && isset($data['name']) && $data['name'] === 'Agent_Tools_Source_read' && isset($e['run_id']) && (int)$e['run_id'] === (int)$runId) {
            return true;
        }
    }
    return false;
}

function cleanup()
{
    global $created, $agentPhp, $agentResearch, $runPhp, $runResearch, $followupId;
    $failed = [];
    $latest = [];
    $latest[$agentPhp] = $followupId !== null ? $followupId : (isset($runPhp['run_id']) ? $runPhp['run_id'] : null);
    $latest[$agentResearch] = isset($runResearch['run_id']) ? $runResearch['run_id'] : null;
    foreach ($created as $name) {
        $runId = isset($latest[$name]) && is_int($latest[$name]) ? $latest[$name] : null;
        if ($runId !== null) {
            try { stopAndWait($name, $runId, $name); } catch (\Throwable $e) { $failed[] = $e->getMessage(); }
        }
        $remove = cli(['remove', $name]);
        if ($remove['status'] !== 0) $failed[] = 'remove ' . $name . ': ' . trim($remove['stderr']);
    }
    if ($failed) throw new RuntimeException('Cleanup failures: ' . implode('; ', $failed));
}

try {
    // Two uniquely named agents in one unique team, profile selected, Source::read tool granted.
    $created1 = cliJson(['create', $agentPhp, '--persona', 'PHP developer', '--team', $team, '-p', $profile, '-t', 'Source::read']);
    $created[] = $agentPhp;
    $created2 = cliJson(['create', $agentResearch, '--persona', 'Research companion', '--team', $team, '-p', $profile, '-t', 'Source::read']);
    $created[] = $agentResearch;
    check(isset($created1['id']) && $created1['name'] === $agentPhp, 'PHP developer agent created in team ' . $team);
    check(isset($created2['id']) && $created2['name'] === $agentResearch, 'Research companion agent created in team ' . $team);

    // Queue real source-inspection tasks to BOTH before waiting.
    $runPhp = cliJson(['assign', $agentPhp, '--', 'Use Source.read to inspect classes/Agent/AgentCli.php. Report the PHP namespace declared at the top of the file and the constant name that holds the supported actions. Remember this token for a follow-up: ' . $token . '.']);
    $runResearch = cliJson(['assign', $agentResearch, '--', 'Use Source.read to inspect classes/Agent/Terminal.php. Report the class name and the method used to write to stdout.']);
    check(isset($runPhp['run_id']) && is_int($runPhp['run_id']), 'PHP agent task queued with run id');
    check(isset($runResearch['run_id']) && is_int($runResearch['run_id']), 'Research agent task queued with run id');

    // Wait for both, bounded.
    $resultPhp = cliJson(['wait', $agentPhp, '--run', (string)$runPhp['run_id'], '--timeout', '60']);
    $resultResearch = cliJson(['wait', $agentResearch, '--run', (string)$runResearch['run_id'], '--timeout', '60']);
    check($resultPhp['status'] === 'completed', 'PHP agent task completed (status ' . $resultPhp['status'] . ')');
    check($resultResearch['status'] === 'completed', 'Research agent task completed (status ' . $resultResearch['status'] . ')');

    // Event feeds prove Source::read actually ran for both agents, keyed to their first runs.
    $eventsPhp = cliJson(['events', $agentPhp, '--after', '0']);
    $eventsResearch = cliJson(['events', $agentResearch, '--after', '0']);
    check(is_array($eventsPhp) && is_array($eventsResearch), 'Event streams readable for both agents');
    check(toolStartSourceEvent($eventsPhp, $runPhp['run_id']), 'PHP agent events show Source::read tool.start for its first run');
    check(toolStartSourceEvent($eventsResearch, $runResearch['run_id']), 'Research agent events show Source::read tool.start for its first run');

    // History isolation: each feed contains only its own expected run_ids.
    $runIds = function ($events) {
        $ids = [];
        foreach ($events as $e) if (isset($e['run_id'])) $ids[] = (int)$e['run_id'];
        return array_values(array_unique($ids));
    };
    check($runIds($eventsPhp) === [(int)$runPhp['run_id']], 'PHP event feed covers only its initial run before follow-up');
    check($runIds($eventsResearch) === [(int)$runResearch['run_id']], 'Research event feed covers only its initial run');

    // Follow-up to the first agent proves saved context is retained across runs.
    $followup = cliJson(['assign', $agentPhp, '--', 'You inspected AgentCli.php earlier. What is the constant name that holds the supported actions, and what token did I ask you to remember?']);
    $followupId = $followup['run_id'];
    check(is_int($followupId), 'Follow-up task queued for PHP agent');
    $resultFollowup = cliJson(['wait', $agentPhp, '--run', (string)$followupId, '--timeout', '60']);
    check($resultFollowup['status'] === 'completed', 'Follow-up task completed (status ' . $resultFollowup['status'] . ')');
    $followupAnswer = (string)($resultFollowup['answer'] ?? '');
    check(strpos($followupAnswer, 'ACTIONS') !== false && strpos($followupAnswer, $token) !== false, 'Follow-up answer reuses saved AgentCli context and recalls token');

    // After the follow-up, PHP feed covers only its two runs; research feed is unchanged.
    $eventsPhp = cliJson(['events', $agentPhp, '--after', '0']);
    check($runIds($eventsPhp) === [(int)$runPhp['run_id'], (int)$followupId], 'PHP event feed covers only its initial and follow-up runs');
} finally {
    cleanup();
}
echo "PASS: agent team live smoke test complete\n";

<?php
require __DIR__ . '/helpers.php';

$owner = 'fixture-worker-recovery-' . bin2hex(random_bytes(6));
$threads = []; $worker = null; $agent = null;
$old = gmdate('c', time() - 180);
$recent = gmdate('c');
// No queued work or provider configuration: these exercise only durable recovery.
$cases = [
    ['missing timestamps expire from creation', $old, null, null, true],
    ['missing heartbeat expires from start', $old, $old, null, true],
    ['old heartbeat expires normally', $old, $old, $old, true],
    ['recent creation survives missing timestamps', $recent, null, null, false],
    ['recent start survives old creation', $old, $recent, null, false],
    ['recent heartbeat survives old start', $old, $old, $recent, false],
];
try {
    // Explicit --thread recovery must not sweep another monitor's manual work.
    $unrelated = Agent\Thread::create($owner, 'Unselected recovery fixture', ['manual' => true]);
    $threads[] = $unrelated;
    $outside = Agent\Database::row("INSERT INTO runs (thread_id, author_id, settings, status, created_at) VALUES (?, ?, '{}'::jsonb, 'running', ?) RETURNING id", [$unrelated->id, $owner, $old]);
    foreach ($cases as $case) {
        list($label, $created, $started, $heartbeat, $expired) = $case;
        $thread = Agent\Thread::create($owner, $label, ['manual' => true]);
        $threads[] = $thread;
        $run = Agent\Database::row("INSERT INTO runs (thread_id, author_id, settings, status, created_at, started_at, heartbeat_at) VALUES (?, ?, '{}'::jsonb, 'running', ?, ?, ?) RETURNING id", [$thread->id, $owner, $created, $started, $heartbeat]);
        $worker = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/phill-worker') . ' --thread ' . (int)$thread->id, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($worker)) throw new RuntimeException('Cannot launch recovery fixture worker');
        if ($expired) {
            $until = microtime(true) + 5;
            do {
                $saved = Agent\Run::get($run['id']);
                if ($saved['status'] === 'stopped') break;
                usleep(20000);
            } while (microtime(true) < $until);
            check($saved['status'] === 'stopped' && strpos($saved['error'], 'heartbeat expired') !== false, $label);
        } else {
            usleep(300000);
            check(Agent\Run::get($run['id'])['status'] === 'running', $label);
        }
        proc_terminate($worker); proc_close($worker); $worker = null;
    }
    check(Agent\Run::get($outside['id'])['status'] === 'running', 'Scoped recovery leaves unrelated running work untouched');
    $agent = Agent\Agents::create($owner, ['tools' => [], 'profile' => ['url' => 'http://127.0.0.1:1/never-connect', 'model' => 'no-provider']]);
    Agent\Agents::pause($agent['id']);
    $agentRun = Agent\Database::row("INSERT INTO runs (thread_id, author_id, settings, status, created_at) VALUES (?, ?, '{}'::jsonb, 'running', ?) RETURNING id", [$agent['thread_id'], $agent['owner_id'], $old]);
    Agent\Agents::supervise($agent['id']);
    $saved = Agent\Run::get($agentRun['id']);
    check($saved['status'] === 'failed' && strpos($saved['error'], 'heartbeat expired') !== false, 'Paused owned agent supervisor recovers an old run with missing timestamps without a model request');
} finally {
    if (is_resource($worker)) { proc_terminate($worker); proc_close($worker); }
    foreach ($threads as $thread) Agent\Database::execute('DELETE FROM threads WHERE id = ? AND owner_id = ?', [$thread->id, $owner]);
    if ($agent) Agent\Database::execute('DELETE FROM threads WHERE id = ? AND owner_id = ?', [$agent['thread_id'], $agent['owner_id']]);
}

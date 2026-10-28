<?php
require __DIR__ . '/helpers.php';
putenv('PHP_CLI_SERVER_WORKERS');
function waitFor($callback, $label, $seconds = 20)
{
    $until = microtime(true) + $seconds;
    do { if (call_user_func($callback)) return; usleep(50000); } while (microtime(true) < $until);
    throw new RuntimeException('Timed out: ' . $label);
}
$server = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:18995 ' . escapeshellarg(__DIR__ . '/fixtures/engine.php'),
    [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
$name = 'fixture-worker-' . bin2hex(random_bytes(4));
$config = ['url' => 'http://127.0.0.1:18995/v1/chat/completions', 'model' => 'slow', 'stream' => true, 'max_retries' => 0];
Agent\Database::connection()->prepare('INSERT INTO profiles (name, config) VALUES (?, ?::jsonb)')->execute([$name, json_encode($config)]);
$thread = Agent\Thread::create('fixture-alice', 'Worker integration', ['manual' => true, 'profile' => $name]);
$worker = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/phill-worker') . ' --thread ' . $thread->id,
    [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
try {
    usleep(200000);
    $first = $thread->enqueue('fixture-alice', 'Slow first turn');
    $thread->configure(['parameters' => ['model' => 'fast', 'stream' => false]]);
    $second = $thread->enqueue('fixture-bob', 'Next turn');
    waitFor(function () use ($first) { return Agent\Run::get($first['id'])['status'] === 'running'; }, 'run starts');
    usleep(300000);
    check(Agent\Run::requestStop($first['id']) === 1, 'First Stop is graceful');
    waitFor(function () use ($second) { return Agent\Run::get($second['id'])['status'] === 'completed'; }, 'queue advances after graceful stop');
    check(Agent\Run::get($first['id'])['status'] === 'stopped', 'Graceful run finishes and releases queue');
    check(json_decode(Agent\Run::get($first['id'])['settings'], true)['config']['model'] === 'slow', 'Queued run retains its submitted settings');
    check(json_decode(Agent\Run::get($second['id'])['settings'], true)['config']['model'] === 'fast', 'Following run has its own settings');

    foreach ([2, 3] as $escalation) {
        $thread->configure(['parameters' => ['model' => 'slow', 'stream' => true]]);
        $run = $thread->enqueue('fixture-alice', 'Stop escalation test');
        waitFor(function () use ($run) { return Agent\Run::get($run['id'])['status'] === 'running'; }, 'escalation run starts');
        usleep(300000);
        for ($level = 1; $level <= $escalation; $level++) check(Agent\Run::requestStop($run['id']) === $level, 'Stop escalates to level ' . $level);
        waitFor(function () use ($run) { return Agent\Run::get($run['id'])['status'] === 'stopped'; }, 'escalated stop cleans up');
        $q = Agent\Database::connection()->prepare("SELECT COUNT(*) FROM messages WHERE run_id = ? AND role = 'assistant'");
        $q->execute([$run['id']]);
        check($q->fetchColumn() == 1, 'Interrupted partial output preserved once');
    }
} finally {
    proc_terminate($worker); proc_close($worker);
    proc_terminate($server); proc_close($server);
    $thread->delete();
    Agent\Database::connection()->prepare('DELETE FROM profiles WHERE name = ?')->execute([$name]);
}

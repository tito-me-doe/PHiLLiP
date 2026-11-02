<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';
$web = new WebFixture();
try {
    $admin = Agent\Installation::install(['auth' => 'standalone'], 'bench_admin', 'benchmark-browser-password', 'Benchmark admin');
    $engine = $web->server(__DIR__ . '/fixtures/benchmark_engine.php');
    $profile = Agent\Profiles::save('default', ['url' => $engine . '/v1/chat/completions', 'model' => 'fixture', 'stream' => true]);
    Agent\Database::execute('UPDATE profiles SET enabled=TRUE,supports_chat=TRUE,supports_streaming=TRUE WHERE id=?', [$profile['id']]);
    $web->server(dirname(__DIR__) . '/public/index.php', true);
    $web->login('bench_admin', 'benchmark-browser-password', 'admin');

    Agent\Database::execute('DELETE FROM benchmark_tests');
    $test = Agent\Benchmarks::saveTest(['name' => 'focus-suite', 'model_type' => 'chat']);
    foreach ([1, 2] as $position) {
        Agent\Benchmarks::saveQuestion($test['id'], ['name' => 'Slow fixture ' . $position, 'input' => ['prompt' => 'BENCH_SLOW return FIXTURE_PASS'], 'scoring_type' => 'matches', 'scoring_criteria' => 'FIXTURE_PASS']);
    }

    $launch = $web->launch([PHP_BINARY, dirname(__DIR__) . '/bin/phill-bench-run', '--worker']);
    check($web->request('/api/admin/benchmarks', ['action' => 'play'], 'admin', false, $web->tokens['admin'])['status'] === 200, 'Administrator can queue work');
    $until = microtime(true) + 12;
    do {
        $row = Agent\Database::row('SELECT * FROM benchmark_runs ORDER BY id DESC LIMIT 1');
        if ($row['status'] === 'completed') break;
        usleep(100000);
    } while (microtime(true) < $until);
    check($row['status'] === 'completed', 'Background worker executes HTTP-queued questions');

    $adapter = new Agent\BenchmarkFrontend($admin);
    $report = $adapter->report($test['id']);
    check(count($report['profiles']) >= 1, 'At least one profile has completed benchmark results');

    $env = $_ENV;
    $env['PYTHONPATH'] = '/home/bfoxwell/.local/share/codex-tools/agent-ui-test/python';
    $env['PLAYWRIGHT_BROWSERS_PATH'] = '/home/bfoxwell/.local/share/codex-tools/agent-ui-test/browsers';
    $code = proc_close(proc_open('python3 ' . escapeshellarg(__DIR__ . '/ui_fix_07.py') . ' ' . escapeshellarg($web->base), [['file', '/dev/null', 'r'], STDOUT, STDERR], $pipes, dirname(__DIR__), $env)) ;
    check($code === 0, 'Browser focus-preservation regression');
} catch (Throwable $e) {
    fwrite(STDERR, $web->diagnostics());
    throw $e;
} finally {
    $web->close();
}

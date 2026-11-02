<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';

/** Regression: grid/min-width containment at 390x844 viewport.
 *
 * Covers:
 *  - .page-inner and .page-content shrink below content on narrow viewport
 *  - Models cards fit inside the inner pane (no full-pane horizontal pan)
 *  - Tools cards fit inside the inner pane
 *  - Wide tables scroll inside .table-wrap, not the whole pane
 *  - Long text wraps instead of forcing pane width
 */
$web = new WebFixture();
try {
    $admin = Agent\Installation::install(['auth' => 'standalone'], 'admin', 'phillip-demo', 'Administrator');
    $alice = Agent\Identity::createUser('alice', 'phillip-demo', 'Alice');

    Agent\Identity::grant('user', $alice['id'], 'page.access');
    Agent\Identity::grant('user', $alice['id'], 'page.models');
    Agent\Identity::grant('user', $alice['id'], 'page.benchmarks');

    $engine = $web->server(__DIR__ . '/fixtures/onboarding_engine.php');
    $profile = Agent\Profiles::save('default', ['url' => $engine . '/v1/chat/completions', 'model' => 'onboarding-chat', 'stream' => true, 'max_tokens' => 1024]);
    Agent\Database::execute('UPDATE profiles SET enabled=TRUE,supports_chat=TRUE,supports_streaming=TRUE,supports_tools=TRUE WHERE id=?', [$profile['id']]);
    $web->server(dirname(__DIR__) . '/public/index.php', true);
    $web->login('alice', 'phillip-demo', 'alice');

    Agent\Database::execute('DELETE FROM benchmark_tests');
    $test = Agent\Benchmarks::saveTest(['name' => 'containment-suite', 'model_type' => 'chat']);
    Agent\Benchmarks::saveQuestion($test['id'], ['name' => 'Wide table fixture', 'input' => ['prompt' => 'BENCH_SLOW return FIXTURE_PASS'], 'scoring_type' => 'matches', 'scoring_criteria' => 'FIXTURE_PASS']);
    $web->launch([PHP_BINARY, dirname(__DIR__) . '/bin/phill-bench-run', '--worker']);
    check($web->request('/api/admin/benchmarks', ['action' => 'play'], 'alice', false, $web->tokens['alice'])['status'] === 200, 'Administrator can queue benchmark work');
    $until = microtime(true) + 12;
    do {
        $row = Agent\Database::row('SELECT * FROM benchmark_runs ORDER BY id DESC LIMIT 1');
        if ($row['status'] === 'completed') break;
        usleep(100000);
    } while (microtime(true) < $until);
    check($row['status'] === 'completed', 'Background worker executes benchmark questions');

    $env = $_ENV;
    $env['PYTHONPATH'] = '/home/bfoxwell/.local/share/codex-tools/agent-ui-test/python';
    $env['PLAYWRIGHT_BROWSERS_PATH'] = '/home/bfoxwell/.local/share/codex-tools/agent-ui-test/browsers';
    $code = proc_close(proc_open('python3 ' . escapeshellarg(__DIR__ . '/ui_fix_09.py') . ' ' . escapeshellarg($web->base), [['file', '/dev/null', 'r'], STDOUT, STDERR], $pipes, dirname(__DIR__), $env));
    check($code === 0, 'Browser grid containment regression at 390x844');
} catch (Throwable $e) {
    fwrite(STDERR, $web->diagnostics());
    throw $e;
} finally {
    $web->close();
}

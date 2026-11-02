<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';

putenv('PYTHONPATH=/home/bfoxwell/.local/share/codex-tools/agent-ui-test/python');
putenv('PLAYWRIGHT_BROWSERS_PATH=/home/bfoxwell/.local/share/codex-tools/agent-ui-test/browsers');
$web = new WebFixture();
try {
    $admin = Agent\Installation::install(['auth' => 'standalone'], 'admin', 'phillip-demo', 'Administrator');
    $engine = $web->server(__DIR__ . '/fixtures/onboarding_engine.php');
    $profile = Agent\Profiles::save('default', ['url' => $engine . '/v1/chat/completions', 'model' => 'onboarding-chat', 'stream' => true]);
    Agent\Database::execute('UPDATE profiles SET enabled=TRUE,supports_chat=TRUE,supports_streaming=TRUE WHERE id=?', [$profile['id']]);
    Agent\Thread::create($admin['id'], ['title' => 'Navigation fixture', 'settings' => ['profile' => 'default', 'tools' => []]]);
    $web->server(dirname(__DIR__) . '/public/index.php', true);
    $web->login('admin', 'phillip-demo', 'admin');
    $browser = $web->launch(['python3', __DIR__ . '/ui_fix_11.py', $web->base], true);
    check(proc_close($browser) === 0, 'Mobile navigation browser workflow');
} catch (Throwable $e) {
    fwrite(STDERR, $web->diagnostics());
    throw $e;
} finally { $web->close(); }

<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';

putenv('PYTHONPATH=/home/bfoxwell/.local/share/codex-tools/agent-ui-test/python');
putenv('PLAYWRIGHT_BROWSERS_PATH=/home/bfoxwell/.local/share/codex-tools/agent-ui-test/browsers');
putenv('FIX04_THREAD_A=');
putenv('FIX04_THREAD_B=');
$web = new WebFixture();
try {
    $admin = Agent\Installation::install(['auth' => 'standalone'], 'admin', 'phillip-demo', 'Administrator');
    $people = ['admin' => $admin];
    foreach (['alice', 'bob'] as $name) $people[$name] = Agent\Identity::createUser($name, 'phillip-demo', ucfirst($name));
    $engine = $web->server(__DIR__ . '/fixtures/onboarding_engine.php');
    $profile = Agent\Profiles::save('fix04-default', ['url' => $engine . '/v1/chat/completions', 'model' => 'onboarding-chat', 'stream' => true, 'max_tokens' => 1024]);
    Agent\Database::execute('UPDATE profiles SET enabled=TRUE,supports_chat=TRUE,supports_streaming=TRUE,supports_tools=TRUE WHERE id=?', [$profile['id']]);
    $web->server(dirname(__DIR__) . '/public/index.php', true);
    foreach (['admin', 'alice', 'bob'] as $name) $web->login($name, 'phillip-demo', $name);

    $alice = $people['alice']['id'];
    $bob = $people['bob']['id'];

    // Create two threads for alice with normal (non-manual) settings so the worker picks them up.
    $threadA = Agent\Thread::create($alice, ['title' => 'Thread A', 'settings' => ['profile' => 'fix04-default', 'tools' => [], 'manual' => false]]);
    $threadB = Agent\Thread::create($alice, ['title' => 'Thread B', 'settings' => ['profile' => 'fix04-default', 'tools' => [], 'manual' => false]]);
    putenv('FIX04_THREAD_A=' . $threadA->id);
    putenv('FIX04_THREAD_B=' . $threadB->id);

    check($web->request('/chat', null, 'alice')['status'] === 200, 'Alice can open /chat');

    // Persistent background worker so assistant messages stream for non-manual threads.
    $web->launch([PHP_BINARY, dirname(__DIR__) . '/bin/phill-worker']);

    // Launch the browser test by default (no --browser flag required).
    $browser = $web->launch(['python3', __DIR__ . '/ui_fix_04.py', $web->base], true);
    $code = proc_close($browser);
    check($code === 0, 'Per-thread composer draft browser workflow');
} catch (Throwable $e) {
    fwrite(STDERR, $web->diagnostics());
    throw $e;
} finally {
    $web->close();
}

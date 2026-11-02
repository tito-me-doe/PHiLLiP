<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';

$web = new WebFixture();
try {
    $admin = Agent\Installation::install(['auth' => 'standalone'], 'admin', 'phillip-demo', 'Administrator');

    // Start the onboarding engine fixture for a slow, deterministic stream.
    $engineAddress = WebFixture::address();
    $web->launch([PHP_BINARY, '-S', $engineAddress, __DIR__ . '/fixtures/onboarding_engine.php']);
    for ($i = 0; $i < 100; $i++) {
        $socket = @stream_socket_client('tcp://' . $engineAddress, $errno, $error, 0.02);
        if ($socket) { fclose($socket); break; }
        usleep(20000);
    }
    $engineUrl = 'http://' . $engineAddress;

    // Create a chat profile backed by the fixture engine.
    $profile = Agent\Profiles::save('fix02-default', [
        'url' => $engineUrl . '/v1/chat/completions',
        'model' => 'onboarding-chat',
        'stream' => true,
        'max_tokens' => 1024,
    ]);
    Agent\Database::execute('UPDATE profiles SET enabled=TRUE, supports_chat=TRUE, supports_streaming=TRUE, supports_tools=TRUE WHERE id=?', [$profile['id']]);

    // Mark setup as complete so the app opens chat directly.
    Agent\Database::execute("UPDATE settings SET value = 'true' WHERE key = 'setup_finished'");

    // Start the main app server.
    $web->server(dirname(__DIR__) . '/public/index.php', true);
    $base = $web->base;

    // Log in as admin via API.
    $web->login('admin', 'phillip-demo', 'admin');

    // Create two threads.
    $thread1 = $web->request('/api/threads', ['title' => 'Fix 02 thread 1', 'settings' => ['profile' => 'fix02-default', 'tools' => []]], 'admin');
    check($thread1['status'] === 200, 'Thread 1 created');
    $threadId1 = $thread1['json']['thread']['id'];

    $thread2 = $web->request('/api/threads', ['title' => 'Fix 02 thread 2', 'settings' => ['profile' => 'fix02-default', 'tools' => []]], 'admin');
    check($thread2['status'] === 200, 'Thread 2 created');
    $threadId2 = $thread2['json']['thread']['id'];

    // Start the background worker so runs are executed and streamed.
    $web->launch([PHP_BINARY, dirname(__DIR__) . '/bin/phill-worker']);

    // Launch the browser test. The browser sends the slow message after login
    // so the stream is running during the navigation failure scenario.
    putenv('FIX02_BASE=' . $base);
    putenv('FIX02_USERNAME=admin');
    putenv('FIX02_PASSWORD=phillip-demo');
    putenv('FIX02_THREAD1=' . $threadId1);
    putenv('FIX02_THREAD2=' . $threadId2);
    putenv('PYTHONPATH=/home/bfoxwell/.local/share/codex-tools/agent-ui-test/python');
    putenv('PLAYWRIGHT_BROWSERS_PATH=/home/bfoxwell/.local/share/codex-tools/agent-ui-test/browsers');

    $browser = $web->launch(['python3', __DIR__ . '/ui_fix_02.py'], true);
    $exit = proc_close($browser);
    if ($exit !== 0) {
        fwrite(STDERR, "Browser test exited with code $exit\n");
        fwrite(STDERR, $web->diagnostics() . "\n");
    }
    check($exit === 0, 'Browser test for openThread failure recovery passed');
} finally {
    $web->close();
}

<?php
require __DIR__ . '/helpers.php';
try {
    Agent\Tools\BrowseWeb::open('http://127.0.0.1:1/');
    throw new RuntimeException('Expected remote loopback navigation failure');
} catch (RuntimeException $error) {
    check(strpos($error->getMessage(), 'Remote browser open failed') !== false, 'Target navigation failure is not misreported as an unavailable Camoufox service');
    check(strpos($error->getMessage(), 'not the PHP host') !== false, 'Loopback navigation failure explains the remote browser network boundary');
    check(strlen($error->getMessage()) < 2400, 'Browser failure feedback is bounded');
}
putenv('PHP_CLI_SERVER_WORKERS');
$process = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S 0.0.0.0:18994 ' . escapeshellarg(__DIR__ . '/fixtures/engine.php'),
    [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
try {
    usleep(200000);
    $fixture = Agent\Tools\BrowseWeb::open('http://phillip.internal:18994/browser-fixture');
    try {
        check(strpos($fixture['text'], 'JavaScript ready') !== false, 'Camoufox executes page JavaScript');
        Agent\Tools\BrowseWeb::type($fixture['session'], '#name', 'Cobalt browser');
        Agent\Tools\BrowseWeb::click($fixture['session'], '#go');
        check(Agent\Tools\BrowseWeb::read($fixture['session'], '#result')['text'] === 'Cobalt browser', 'Browser type/click/read interaction');
        $shot = Agent\Tools\BrowseWeb::screenshot($fixture['session']);
        check($shot['role'] === 'tool' && $shot['content'][1]['type'] === 'image_url', 'Browser screenshot uses a native multimodal tool payload');
        check(strpos($shot['content'][1]['image_url']['url'], 'data:image/png;base64,') === 0, 'Screenshot has a valid data URL separator, not just decodable image bytes');
        $png = base64_decode(substr($shot['content'][1]['image_url']['url'], strlen('data:image/png;base64,')), true);
        check($png !== false && substr($png, 0, 8) === "\x89PNG\r\n\x1a\n" && strlen($png) <= 2097152, 'Camoufox returns an actual bounded viewport PNG');
        expectException(function () use ($fixture) {
            Agent\ToolContext::withValues(['thread_id' => 'wrong-scope'], function () use ($fixture) { Agent\Tools\BrowseWeb::screenshot($fixture['session']); });
        }, 'Unknown browser session');
    } finally { Agent\Tools\BrowseWeb::close($fixture['session']); }
    $modal = Agent\Tools\BrowseWeb::open('http://phillip.internal:18994/browser-modal');
    try {
        check(count($modal['controls']) === 3 && !in_array('csrf', array_column($modal['controls'], 'name'), true), 'Browser observations prioritize visible modal controls rather than hidden fields or background button lists');
        $input = $modal['controls'][0];
        check($input['name'] === 'name' && strpos($input['selector'], '#modal-form') === 0, 'Returned form selectors address controls without guessing IDs');
        $filled = Agent\Tools\BrowseWeb::type($modal['session'], $input['selector'], 'Test persona');
        check($filled['controls'][0]['value'] === 'Test persona', 'Browser observations make filled form state inspectable rather than requiring assumptions');
        $selected = Agent\Tools\BrowseWeb::select($modal['session'], '#choice', 'organization');
        check(Agent\Tools\BrowseWeb::read($modal['session'], '#selected')['text'] === 'organization' && $selected['controls'][1]['options'][1]['selected'], 'Native dropdown selection exposes option values and updates the page');
        $navigated = Agent\Tools\BrowseWeb::navigate($modal['session'], 'http://phillip.internal:18994/browser-cookie');
        check(strpos($navigated['text'], 'Cookie retained') !== false, 'Navigation preserves authenticated browser context instead of opening a new session');
        expectException(function () use ($modal) { Agent\Tools\BrowseWeb::navigate($modal['session'], 'file:///etc/passwd'); }, 'HTTP or HTTPS');
    } finally { Agent\Tools\BrowseWeb::close($modal['session']); }
} finally { proc_terminate($process); proc_close($process); }
$page = Agent\Tools\BrowseWeb::open('https://www.php.net/releases/8.5/en.php');
try {
    check(strpos($page['text'], 'PHP') !== false, 'Camoufox renders a real page');
    $read = Agent\Tools\BrowseWeb::read($page['session']);
    check(strpos($read['text'], 'PHP') !== false, 'Browser session persists across PHP calls');
    expectException(function () use ($page) {
        Agent\ToolContext::withValues(['thread_id' => 'another-thread'], function () use ($page) { Agent\Tools\BrowseWeb::read($page['session']); });
    }, 'Unknown browser session');
} finally { Agent\Tools\BrowseWeb::close($page['session']); }

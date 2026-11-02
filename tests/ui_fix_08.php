<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';

/** Issue08: the shared profile form works without JavaScript (native POST CRUD)
 *  and as a shared modal with JavaScript.
 *
 *  JS-off browser CRUD: New opens the native shared form, creates a disabled profile,
 *  Edit opens the same form and updates it; canonical DB result verified afterward.
 *  PHP-level: authorization and CSRF failures are preserved (valid configs, so only
 *  authorization/CSRF can cause rejection).
 *  JS-on: New/Edit open the shared #dialog #profile-form modal.
 */
$web = new WebFixture();
try {
    Agent\Installation::install(['auth' => 'standalone'], 'admin', 'phillip-demo', 'Administrator');
    $alice = Agent\Identity::createUser('alice', 'phillip-demo', 'Alice');
    $bob = Agent\Identity::createUser('bob', 'phillip-demo', 'Bob');
    Agent\Identity::grant('user', $alice['id'], 'page.models');
    // bob deliberately has no page.models grant

    $engine = $web->server(__DIR__ . '/fixtures/onboarding_engine.php');
    $web->server(dirname(__DIR__) . '/public/index.php', true);
    $web->login('alice', 'phillip-demo', 'alice');
    $web->login('bob', 'phillip-demo', 'bob');

    $modelsUrl = Agent\Pages::link('models');
    $newUrl = Agent\Pages::link('models', ['profile' => 'new']);
    $goodConfig = json_encode([
        'url' => $engine . '/v1/chat/completions',
        'model' => 'good-fixt-08',
        'stream' => true,
    ]);

    // --- Authorization: authenticated user without page.models is refused ---
    $bobPage = $web->request($modelsUrl, null, 'bob');
    check($bobPage['status'] === 403, 'Bob without models grant cannot open the models page (403)');
    $bobPost = $web->request($modelsUrl, [
        'action' => 'save-profile', 'csrf' => $web->tokens['bob'],
        'name' => 'fix08-bob', 'config' => $goodConfig, 'overrides' => '{}',
    ], 'bob', true);
    check($bobPost['status'] >= 400 && $bobPost['status'] < 500 && $bobPost['status'] !== 303,
        'Bob save-profile is rejected without the models grant');
    check(!Agent\Database::row('SELECT * FROM profiles WHERE name=?', ['fix08-bob']),
        'No fix08-bob profile row is created by the rejected request');

    // --- CSRF: a forged token is rejected even for an authorized user ---
    $csrfPost = $web->request($modelsUrl, [
        'action' => 'save-profile', 'csrf' => 'bad-token',
        'name' => 'fix08-csrf', 'config' => $goodConfig, 'overrides' => '{}',
    ], 'alice', true);
    check($csrfPost['status'] >= 400 && $csrfPost['status'] < 500 && $csrfPost['status'] !== 303,
        'save-profile rejects a bad CSRF token');
    check(!Agent\Database::row('SELECT * FROM profiles WHERE name=?', ['fix08-csrf']),
        'No fix08-csrf profile row is created by the rejected request');

    // --- Native shared form renders for New (JavaScript-disabled equivalent) ---
    $newHtml = $web->request($newUrl, null, 'alice')['text'];
    check(strpos($newHtml, 'id="profile-form"') !== false, 'New profile renders the shared profile-form');
    check(strpos($newHtml, 'action="' . $modelsUrl . '"') !== false, 'Form posts to the real models route');
    check(strpos($newHtml, 'name="csrf"') !== false && strpos($newHtml, 'name="action"') !== false,
        'Form carries CSRF and action fields');
    foreach (['name', 'config', 'enabled', 'overrides'] as $field) {
        check(strpos($newHtml, 'name="' . $field . '"') !== false, 'Shared form exposes the ' . $field . ' field');
    }

    // --- Native create: a disabled profile, then canonical DB check ---
    $create = $web->request($modelsUrl, [
        'action' => 'save-profile', 'csrf' => $web->tokens['alice'],
        'name' => 'fix08-native',
        'config' => json_encode(['url' => $engine . '/v1/chat/completions', 'model' => 'native-chat', 'stream' => true]),
        'overrides' => '{}',
    ], 'alice', true);
    check($create['status'] === 303, 'Native create of a disabled profile redirects (303)');
    $row = Agent\Database::row('SELECT * FROM profiles WHERE name=?', ['fix08-native']);
    check($row && !$row['enabled'], 'Natively created profile is disabled in the database');

    // --- Native edit: same form, prefilled read-only name, update, DB check ---
    $editUrl = Agent\Pages::link('models', ['profile' => 'edit', 'name' => 'fix08-native']);
    $editHtml = $web->request($editUrl, null, 'alice')['text'];
    check(strpos($editHtml, 'id="profile-form"') !== false, 'Edit opens the same shared profile-form');
    check(strpos($editHtml, 'name="name" value="fix08-native"') !== false && strpos($editHtml, 'readonly') !== false,
        'Edit form prefills the profile name read-only');
    $updateConfig = json_encode(['url' => $engine . '/v1/chat/completions', 'model' => 'native-updated', 'stream' => true]);
    $update = $web->request($modelsUrl, [
        'action' => 'save-profile', 'csrf' => $web->tokens['alice'],
        'name' => 'fix08-native', 'config' => $updateConfig, 'overrides' => '{}',
    ], 'alice', true);
    check($update['status'] === 303, 'Native update of the disabled profile redirects (303)');
    $row = Agent\Database::row('SELECT config FROM profiles WHERE name=?', ['fix08-native']);
    check($row && json_decode($row['config'], true)['model'] === 'native-updated',
        'Edited profile config is persisted in the database');

    // --- Browser: JS-disabled native CRUD, then JS-enabled shared modal ---
    $env = $_ENV;
    $env['PYTHONPATH'] = '/home/bfoxwell/.local/share/codex-tools/agent-ui-test/python';
    $env['PLAYWRIGHT_BROWSERS_PATH'] = '/home/bfoxwell/.local/share/codex-tools/agent-ui-test/browsers';
    $code = proc_close(proc_open('python3 ' . escapeshellarg(__DIR__ . '/ui_fix_08.py') . ' ' . escapeshellarg($web->base),
        [['file', '/dev/null', 'r'], STDOUT, STDERR], $pipes, dirname(__DIR__), $env));
    check($code === 0, 'Browser profile form regression (JS-off CRUD + JS-on modal)');

    // --- Canonical DB result after the Python-driven browser CRUD ---
    $browserRow = Agent\Database::row('SELECT * FROM profiles WHERE name=?', ['fix08-browser-disabled']);
    check($browserRow && !$browserRow['enabled']
        && json_decode($browserRow['config'], true)['model'] === 'fix-chat-v2',
        'Browser-edited disabled profile persists disabled with the updated model (canonical result)');
} catch (Throwable $e) {
    fwrite(STDERR, $web->diagnostics());
    throw $e;
} finally {
    $web->close();
}
echo "ALL PASS\n";

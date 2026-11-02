<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';

/**
 * Regression: Issue 12 — persona cards readable at 390px and bounded
 * management previews. Drives the installed app through a real browser;
 * no static HTML and no source-string assertions.
 *
 * Fixtures carried by the PHP half (so the Python half can rely on them):
 *  - alpha: allowed persona whose prompt lead is the alpha-xyz-probe- marker
 *  - beta:  disabled persona (unknown tool) carrying the beta-qrs-probe- marker
 *            and the long "Management prints full prompts..." fixture text
 *  - chi:   XSS-looking prompt that must render escaped and bounded everywhere
 */
$web = new WebFixture();
try {
    $admin = Agent\Installation::install(['auth' => 'standalone'], 'admin', 'phillip-demo', 'Administrator');
    $alice = Agent\Identity::createUser('alice', 'phillip-demo', 'Alice');
    $aliceAuth = Agent\Identity::authenticate('alice', 'phillip-demo');

    Agent\Identity::grant('user', $aliceAuth['id'], 'page.conversations');
    Agent\Identity::grant('user', $aliceAuth['id'], 'page.personas');

    // A working-enough chat profile so the picker is not disabled for missing profiles.
    $profile = Agent\Profiles::save('default', [
        'url' => 'http://127.0.0.1:1/v1/chat/completions',
        'model' => 'fixture',
        'stream' => true,
    ]);
    Agent\Database::execute('UPDATE profiles SET enabled=TRUE,supports_chat=TRUE WHERE id=?', [$profile['id']]);

    $longPrompt = str_repeat(
        'Management prints full prompts overwhelming rows. Verbose administrative instruction text that should appear '
        . 'as a bounded readable summary inside the personas table while the complete prompt remains available via the '
        . 'details disclosure. ',
        3
    );

    // Characters: the markers live in the tagline (what the picker card and the management preview show).
    $alphaPrompt = 'alpha-xyz-probe-A helpful assistant.';
    $betaPrompt = 'beta-qrs-probe-' . $longPrompt;
    $chiPrompt = '<img src=x onerror=alert(document.domain)>This tagline stays bounded and is shown as escaped text.';

    // A persona that wants a tool web chat does not know still starts: a character simply goes without it.
    Agent\Personas::save($aliceAuth['id'], ['name' => 'Helpful Assistant', 'tagline' => $alphaPrompt, 'bio' => 'Helps with anything.', 'tools' => [], 'visibility' => 'private']);
    Agent\Personas::save($aliceAuth['id'], [
        'name' => 'A Very Long Persona Name That Would Stack Into Words Without Ellipsis Handling',
        'tagline' => 'beta-qrs-probe-Management prints full prompts overwhelming rows.', 'bio' => $betaPrompt,
        'tools' => ['Agent\\Tools\\UndefinedFrontierTool'], 'visibility' => 'private',
    ]);
    Agent\Personas::save($aliceAuth['id'], ['name' => 'Safe Render Check', 'tagline' => $chiPrompt, 'bio' => 'Hostile-looking text must stay inert.', 'tools' => [], 'visibility' => 'private']);

    $web->server(dirname(__DIR__) . '/public/index.php', true);
    $base = $web->base;

    putenv('FX12_BASE=' . $base);
    putenv('FX12_USERNAME=alice');
    putenv('FX12_PASSWORD=phillip-demo');
    putenv('PYTHONPATH=/home/bfoxwell/.local/share/codex-tools/agent-ui-test/python');
    putenv('PLAYWRIGHT_BROWSERS_PATH=/home/bfoxwell/.local/share/codex-tools/agent-ui-test/browsers');

    $browser = $web->launch(['python3', __DIR__ . '/ui_fix_12.py'], true);
    $exit = proc_close($browser);
    if ($exit !== 0) {
        fwrite(STDERR, "Browser test exited with code $exit\n");
        fwrite(STDERR, $web->diagnostics() . "\n");
    }
    check($exit === 0, 'Browser persona-picker regression passed');

    // Server-side confirmation: the allowed fixture actually starts an empty thread
    // with the persona copied into the conversation settings; the disabled one cannot.
    $thread = Agent\Database::row('SELECT id FROM threads WHERE owner_id = ? ORDER BY id DESC LIMIT 1', [$aliceAuth['id']]);
    check($thread, 'Allowed persona start created an empty thread for alice');
    $system = Agent\Database::row(
        "SELECT raw->>'content' AS content FROM messages WHERE thread_id = ? AND role = 'system' AND run_id IS NULL",
        [(string)$thread['id']]
    );
    check(is_array($system) && strpos($system['content'] ?? '', $alphaPrompt) !== false, 'Started thread copied the chosen persona');
} finally {
    $web->close();
}

echo "ALL PASS\n";

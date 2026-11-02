<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';

/**
 * The companion belongs to Chat: a new chat shows only the persona cards (no composer, no companion) until someone
 * is chosen, then the companion perches on the composer; no other page has one, and row actions stay clickable.
 * Runs at 1440x1000 and 390x844 with real browser login and a dummy enabled chat profile (never called).
 */
$web = new WebFixture();
try {
    Agent\Installation::install(['auth' => 'standalone'], 'admin', 'phillip-demo', 'Administrator');
    Agent\PersonaSheet::install();

    // Dummy enabled chat profile: configured, but never called by this test.
    $profile = Agent\Profiles::save('default', [
        'url' => 'http://127.0.0.1:1/v1/chat/completions',
        'model' => 'fixture',
        'stream' => true,
    ]);
    Agent\Database::execute('UPDATE profiles SET enabled=TRUE, supports_chat=TRUE WHERE id=?', [$profile['id']]);

    // Inflate the routes table so the last row lands next to the companion rail.
    $validHandlers = array_column(Agent\Database::rows('SELECT handler FROM pages'), 'handler');
    for ($i = 1; $i <= 24; $i++) {
        $h = $validHandlers[($i - 1) % count($validHandlers)];
        Agent\Database::execute(
            'INSERT INTO pages(id,title,path,handler) VALUES (?,?,?,?)',
            ['uifix10-' . $i, 'PHASE2A filler route ' . $i,
             '/uifix10-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT),
             $h],
        );
    }

    $web->server(dirname(__DIR__) . '/public/index.php', true);
    $base = $web->base;

    putenv('FIX10_BASE=' . $base);
    putenv('FIX10_USERNAME=admin');
    putenv('FIX10_PASSWORD=phillip-demo');
    putenv('PYTHONPATH=/home/bfoxwell/.local/share/codex-tools/agent-ui-test/python');
    putenv('PLAYWRIGHT_BROWSERS_PATH=/home/bfoxwell/.local/share/codex-tools/agent-ui-test/browsers');

    $browser = $web->launch(['python3', __DIR__ . '/ui_fix_10.py'], true);
    $exit = proc_close($browser);
    if ($exit !== 0) {
        fwrite(STDERR, "Browser test exited with code $exit\n");
        fwrite(STDERR, $web->diagnostics() . "\n");
    }
    check($exit === 0, 'Browser avatar collision regression passed');
} finally {
    $web->close();
}
echo "ALL PASS\n";

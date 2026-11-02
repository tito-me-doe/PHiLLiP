<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';

/**
 * Persona cards, in a real browser: adult personas follow the installation-wide setting, an administrator can change it,
 * a new persona gets a live preview, "Yours" and an edit shortcut, Customize copies a persona, and the chat companion
 * wears the avatar and speaks in the voice of whoever the conversation is with. No provider call is made.
 */
$web = new WebFixture();
try {
    Agent\Installation::install(['auth' => 'standalone'], 'admin', 'phillip-demo', 'Administrator');
    Agent\Identity::createUser('alice', 'phillip-demo', 'Alice');
    $installed = Agent\PersonaSheet::install();
    check($installed['installed'] + $installed['updated'] === count(Agent\PersonaSheet::all()), 'The shipped cast is installed');
    check(Agent\Personas::adultEnabled() === false, 'A new installation has adult-oriented personas off');
    $profile = Agent\Profiles::save('default', ['url' => 'http://127.0.0.1:1/v1/chat/completions', 'model' => 'fixture', 'stream' => true]);
    Agent\Database::execute('UPDATE profiles SET enabled=TRUE,supports_chat=TRUE WHERE id=?', [$profile['id']]);
    Agent\Database::execute("UPDATE settings SET value = 'true' WHERE key = 'setup_finished'");

    $web->server(dirname(__DIR__) . '/public/index.php', true);
    putenv('UP_BASE=' . $web->base);
    putenv('PYTHONPATH=/home/bfoxwell/.local/share/codex-tools/agent-ui-test/python');
    putenv('PLAYWRIGHT_BROWSERS_PATH=/home/bfoxwell/.local/share/codex-tools/agent-ui-test/browsers');
    $browser = $web->launch(['python3', __DIR__ . '/ui_personas.py'], true);
    $exit = proc_close($browser);
    if ($exit !== 0) { fwrite(STDERR, "Browser test exited with code $exit\n" . $web->diagnostics() . "\n"); }
    check($exit === 0, 'Browser persona regression passed');

    // Server-side confirmation of what the browser did.
    check(Agent\Personas::adultEnabled() === true, 'The administrator\'s choice is stored for the whole installation');
    $alice = Agent\Identity::authenticate('alice', 'phillip-demo');
    $names = array_column(Agent\Personas::listFor($alice['id']), 'name');
    check(in_array('Juniper', $names, true) && in_array('Dave (my version)', $names, true), 'The new persona and the customized copy are saved for alice');
} finally {
    $web->close();
}
echo "ALL PASS\n";

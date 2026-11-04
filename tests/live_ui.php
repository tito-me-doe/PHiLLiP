<?php
/**
 * Browser regressions that need a real local model (and, for some, the real SearXNG/Camoufox services).
 *
 *     php tests/live_ui.php [--profile NAME] [script ...]      # default: every script below except ui_projects
 *
 * Each script is a Python Playwright file in tests/live/. They run against a private, disposable installation: this
 * process's throwaway database (demo accounts and personas from the template), its own web server and workers. The only
 * thing borrowed from production is a read of the enabled model profile's configuration; the live model endpoint is then
 * used like any other service. Nothing is written to production.
 */
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';

$root = dirname(__DIR__);
$profileName = 'default';
$scripts = [];
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--profile') { $profileName = $argv[++$i] ?? ''; continue; }
    $scripts[] = $argv[$i];
}
$all = ['ui_server', 'ui_submissions', 'ui_attachments', 'ui_frontend_tightening', 'ui_followups', 'ui_projects'];
if (!$scripts) $scripts = array_diff($all, ['ui_projects']); // ui_projects runs a whole project: ask for it by name
foreach ($scripts as $script) if (!in_array($script, $all, true)) { fwrite(STDERR, "Unknown script $script; known: " . implode(', ', $all) . "\n"); exit(2); }

// Borrow the enabled profile's configuration (read-only) and verify it in this process's own database.
require __DIR__ . '/fixtures/live_profile.php';
Agent\PersonaSheet::install();
borrowLiveProfile($profileName);
check(Agent\Database::row('SELECT enabled FROM profiles WHERE name = ?', [$profileName])['enabled'], "Profile $profileName is verified against the live model in the disposable database");

putenv('PHP_CLI_SERVER_WORKERS');
putenv('APP_BASE_PATH=');
$children = [];
$launch = function (array $args, $quiet = true) use (&$children, $root) {
    $process = proc_open('exec ' . implode(' ', array_map('escapeshellarg', $args)), [['file', '/dev/null', 'r'], $quiet ? ['file', '/dev/null', 'w'] : STDOUT, $quiet ? ['file', '/dev/null', 'w'] : STDERR], $pipes, $root);
    $children[] = $process;
    return $process;
};
try {
    $address = WebFixture::address();
    $launch([PHP_BINARY, '-S', $address, '-t', $root . '/public', $root . '/public/index.php']);
    for ($i = 0; $i < 200 && !@stream_socket_client('tcp://' . $address, $errno, $error, 0.02); $i++) usleep(20000);
    foreach (['phill-worker', 'phill-operations', 'phill-index-worker', 'phill-project-worker'] as $worker) $launch([PHP_BINARY, $root . '/bin/' . $worker]);
    putenv('PHILLIP_URL=http://' . $address);
    putenv('PHILLIP_PROFILE=' . $profileName);
    foreach ($scripts as $script) {
        $process = $launch(['python3', $root . '/tests/live/' . $script . '.py'], false);
        check(proc_close($process) === 0, "$script passes against the live model");
    }
    echo "ALL PASS\n";
} finally {
    foreach ($children as $process) if (is_resource($process)) { proc_terminate($process); proc_close($process); }
}

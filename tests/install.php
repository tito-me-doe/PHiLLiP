<?php
require __DIR__ . '/helpers.php';

// Exercise the real Bash installer with fake system commands. Never install packages on the test host.
$directory = sys_get_temp_dir() . '/phillip-install-' . bin2hex(random_bytes(8));
mkdir($directory, 0700); mkdir($directory . '/bin'); mkdir($directory . '/mock');
$write = function ($name, $contents, $mode = 0700) use ($directory) { file_put_contents($directory . '/' . $name, $contents); chmod($directory . '/' . $name, $mode); };
copy(dirname(__DIR__) . '/bin/install', $directory . '/bin/install');
copy(dirname(__DIR__) . '/.env.example', $directory . '/.env.example');
foreach (['bash', 'awk', 'uname', 'dirname', 'mktemp', 'od', 'tr', 'ln', 'unlink', 'sleep', 'hostname', 'touch', 'dpkg', 'cp'] as $tool) symlink('/usr/bin/' . $tool, $directory . '/mock/' . $tool);
$write('mock/id', <<<'BASH'
#!/usr/bin/env bash
case "$1" in -u) echo "${MOCK_UID:-0}";; -un) echo unix_test;; *) exit 2;; esac
BASH
);
$write('docker-template', <<<'BASH'
#!/usr/bin/env bash
printf 'docker %s\n' "$*" >> "$MOCK_LOG"
[[ -e "$MOCK_ENGINE" ]] || exit 1
if [[ "$*" == *'config --environment'* ]]; then awk '!/^#/ && /=/' .env; exit 0; fi
case "$*" in *phill-bootstrap*) echo 'Temporary password: test-output-only';; esac
exit 0
BASH
);
$write('mock/apt-get', <<<'BASH'
#!/usr/bin/env bash
printf 'apt-get %s\n' "$*" >> "$MOCK_LOG"
if [[ "$*" == *docker-* ]]; then cp "$MOCK_DOCKER_TEMPLATE" "$MOCK_BIN/docker"; touch "$MOCK_ENGINE"; fi
exit 0
BASH
);
foreach (['install', 'curl'] as $command) $write('mock/' . $command, "#!/usr/bin/env bash\nprintf '%s %s\\n' '" . $command . "' \"\$*\" >> \"\$MOCK_LOG\"\n");
$write('mock/chmod', <<<'BASH'
#!/usr/bin/env bash
[[ "$*" != */etc/apt/* ]] || exit 0
exec /usr/bin/chmod "$@"
BASH
);
$oldPath = getenv('PATH');
$oldBind = getenv('APP_BIND'); $oldPort = getenv('APP_PORT');
putenv('APP_BIND=127.0.0.1'); putenv('APP_PORT=8088');
putenv('PATH=' . $directory . '/mock');
putenv('MOCK_LOG=' . $directory . '/commands'); putenv('MOCK_ENGINE=' . $directory . '/engine');
putenv('MOCK_BIN=' . $directory . '/mock'); putenv('MOCK_DOCKER_TEMPLATE=' . $directory . '/docker-template');
putenv('PHILLIP_OS_RELEASE=' . $directory . '/os-release');
$run = function () use ($directory) {
    $log = tmpfile(); $process = proc_open('exec bash ' . escapeshellarg($directory . '/bin/install') . ' --yes', [['file', '/dev/null', 'r'], $log, $log], $pipes, $directory);
    if (!is_resource($process)) throw new RuntimeException('Cannot run installer fixture');
    $code = proc_close($process); rewind($log); $out = stream_get_contents($log); fclose($log);
    check($code === 0, 'Installer completes against stubbed Docker and package manager: ' . ($code ? $out : 'success'));
    return $out;
};
try {
    foreach (['ubuntu' => 'noble', 'debian' => 'bookworm'] as $os => $release) {
        foreach (['.env', 'engine', 'commands', 'mock/docker'] as $name) if (is_file($directory . '/' . $name)) unlink($directory . '/' . $name);
        $write('os-release', "ID=$os\nVERSION_CODENAME=$release\n", 0600);
        putenv('MOCK_UID=0');
        $out = $run(); $commands = file_get_contents($directory . '/commands');
        check(strpos($commands, 'https://download.docker.com/linux/' . $os . '/gpg') !== false && strpos($commands, 'apt-get install -y docker-ce docker-ce-cli') !== false, $os . ' uses the signed Docker repository and explicit packages');
        check(strpos($commands, '--username admin') !== false, $os . ' root installer defaults to admin');
        check(strpos($commands, 'usermod') === false && strpos($commands, 'curl |') === false, 'Installer never changes Docker-group membership or executes downloaded shell');
        $env = file_get_contents($directory . '/.env');
        check(preg_match('/^DB_PASSWORD=[a-f0-9]{48}$/m', $env) === 1 && preg_match('/^BROWSER_TOKEN=[a-f0-9]{48}$/m', $env) === 1 && (fileperms($directory . '/.env') & 0777) === 0600, 'Fresh environment has random secrets and private permissions');
        check(strpos($out, 'http://127.0.0.1:8088/') !== false, 'Installer prints the usable browser URL');
        file_put_contents($directory . '/.env', "INERT=\$(touch " . $directory . "/executed)\n", FILE_APPEND);
        $saved = file_get_contents($directory . '/.env'); $run();
        check(file_get_contents($directory . '/.env') === $saved && !file_exists($directory . '/executed'), 'Rerun preserves configuration without evaluating it as shell');
    }
    putenv('MOCK_UID=1002'); $run();
    check(strpos(file_get_contents($directory . '/commands'), '--username unix_test') !== false, 'Unprivileged installer defaults to the actual Unix username');
} finally {
    putenv('PATH=' . $oldPath); foreach (['MOCK_LOG', 'MOCK_ENGINE', 'MOCK_UID', 'MOCK_BIN', 'MOCK_DOCKER_TEMPLATE', 'PHILLIP_OS_RELEASE'] as $name) putenv($name);
    if ($oldBind === false) putenv('APP_BIND'); else putenv('APP_BIND=' . $oldBind);
    if ($oldPort === false) putenv('APP_PORT'); else putenv('APP_PORT=' . $oldPort);
    foreach (['bin', 'mock'] as $folder) { foreach (glob($directory . '/' . $folder . '/*') as $file) unlink($file); rmdir($directory . '/' . $folder); }
    foreach (glob($directory . '/*') as $file) if (is_file($file)) unlink($file);
    foreach (glob($directory . '/.*') as $file) if (is_file($file)) unlink($file);
    rmdir($directory);
}

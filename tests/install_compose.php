<?php
require __DIR__ . '/helpers.php';
if (!in_array('--run', $argv, true)) { echo "Usage: php tests/install_compose.php --run\nBuilds a disposable Docker installation, exercises install/login/model setup/chat, then removes only its own containers and volume.\n"; exit(0); }
$root = dirname(__DIR__);
$directory = sys_get_temp_dir() . '/phillip-fresh-install-' . bin2hex(random_bytes(6));
$project = 'phillip-onboarding-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$log = tmpfile(); $engine = null;
$oldProject = getenv('COMPOSE_PROJECT_NAME');
$oldEnvironment = [];
$reserve = function () { $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error); if (!$socket) throw new RuntimeException($error); $port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1); fclose($socket); return $port; };
$run = function (array $arguments, $check = true) use ($directory, $log) {
    $process = proc_open('exec ' . implode(' ', array_map('escapeshellarg', $arguments)), [['file', '/dev/null', 'r'], $log, $log], $pipes, $directory);
    if (!is_resource($process)) throw new RuntimeException('Cannot start installation command');
    $code = proc_close($process);
    if ($check && $code !== 0) { rewind($log); $tail = preg_replace('/Temporary password: [^\r\n]+/', 'Temporary password: [redacted]', substr(stream_get_contents($log), -12000)); throw new RuntimeException('Fresh-install command failed: ' . implode(' ', $arguments) . "\n" . $tail); }
    return $code;
};
$compose = ['docker', 'compose', '--project-directory', $directory, '-p', $project];
$cookie = tmpfile();
$request = function ($base, $path, $body = null, $csrf = '') use ($cookie) {
    $curl = curl_init($base . $path); $cookiePath = stream_get_meta_data($cookie)['uri'];
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookiePath, CURLOPT_COOKIEJAR => $cookiePath, CURLOPT_TIMEOUT => 15]);
    if ($body !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]]);
    $text = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($text === false) throw new RuntimeException(curl_error($curl));
    $data = json_decode($text, true);
    if ($status !== 200) throw new RuntimeException('Fresh-install HTTP ' . $status . ' at ' . $path . ': ' . $text);
    return $data;
};
try {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if ($file->isLink() || preg_match('~^(?:\.git|node_modules|vendor|storage)/|^\.env$|^config/.*\.local\.json$~', $relative)) continue;
        $target = $directory . '/' . $relative;
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
        copy($file->getPathname(), $target); chmod($target, $file->getPerms() & 0777);
    }
    $ports = ['APP_PORT' => $reserve(), 'DB_PORT' => $reserve(), 'BROWSER_PORT' => $reserve(), 'SEARCH_PORT' => $reserve()];
    $values = $ports + ['APP_BIND' => '127.0.0.1', 'DB_PASSWORD' => bin2hex(random_bytes(24)) . ' private$literal=', 'BROWSER_TOKEN' => bin2hex(random_bytes(24)), 'SCAN_CIDRS' => '127.0.0.1/32'];
    $env = file_get_contents($root . '/.env.example');
    foreach ($values as $key => $value) {
        $rendered = $key === 'DB_PASSWORD' ? "'" . $value . "'" : $value;
        $env = preg_replace_callback('/^' . preg_quote($key, '/') . '=.*/m', function () use ($key, $rendered) { return $key . '=' . $rendered; }, $env);
    }
    foreach ($values as $key => $value) { $oldEnvironment[$key] = getenv($key); putenv($key . '=' . $value); }
    file_put_contents($directory . '/.env', $env); chmod($directory . '/.env', 0600);
    putenv('COMPOSE_PROJECT_NAME=' . $project);
    echo 'Running the real installer in ' . $directory . " (isolated project and ports)\n";
    $run(['bash', $directory . '/bin/install', '--yes']);
    rewind($log); $output = stream_get_contents($log);
    if (!preg_match('/Administrator: ([^\r\n]+)\RTemporary password: ([a-f0-9]+)/', $output, $credentials)) throw new RuntimeException('Installer did not return first-run credentials');
    check(strpos($output, 'http://127.0.0.1:' . $ports['APP_PORT'] . '/') !== false, 'Real installer prints the configured URL and temporary administrator credentials');
    $base = 'http://127.0.0.1:' . $ports['APP_PORT'];
    $state = $request($base, '/api/state');
    $state = $request($base, '/api/login', ['username' => $credentials[1], 'password' => $credentials[2]], $state['csrf']);
    $password = bin2hex(random_bytes(12));
    $request($base, '/api/setup/password', ['password' => $password, 'confirmation' => $password], $state['csrf']);
    $state = $request($base, '/api/state'); $csrf = $state['csrf'];
    $request($base, '/api/setup', ['configuration' => ['auth' => 'standalone']], $csrf);
    $enginePort = $reserve();
    $engine = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg('0.0.0.0:' . $enginePort) . ' ' . escapeshellarg($root . '/tests/fixtures/onboarding_engine.php'), [['file', '/dev/null', 'r'], $log, $log], $pipes, $root);
    if (!is_resource($engine)) throw new RuntimeException('Cannot start model fixture');
    usleep(200000);
    $request($base, '/api/setup/endpoint', ['url' => 'http://host.docker.internal:' . $enginePort], $csrf);
    $status = $request($base, '/api/setup/status');
    $request($base, '/api/setup/model', ['profile_id' => $status['profiles'][0]['id']], $csrf);
    $until = microtime(true) + 90;
    do { $status = $request($base, '/api/setup/status'); if ($status['ready']) break; usleep(200000); } while (microtime(true) < $until);
    check($status['ready'], 'Fresh Docker worker verifies a host model without host PHP dependencies in the installer');
    $request($base, '/api/setup/finish', [], $csrf);
    $thread = $request($base, '/api/threads', ['title' => 'Fresh clone conversation', 'settings' => ['profile' => 'default', 'tools' => []]], $csrf)['thread'];
    for ($i = 0; $i < 3; $i++) {
        $runRow = $request($base, '/api/threads/' . $thread['id'] . '/messages', ['content' => 'Fresh clone follow-up ' . $i, 'submission_key' => bin2hex(random_bytes(16))], $csrf)['run'];
        $until = microtime(true) + 30;
        do { $snapshot = $request($base, '/api/threads/' . $thread['id']); $runs = array_column($snapshot['runs'], null, 'id'); if ($runs[$runRow['id']]['status'] === 'completed') break; usleep(150000); } while (microtime(true) < $until);
        check($runs[$runRow['id']]['status'] === 'completed', 'Real installed worker completes follow-up ' . ($i + 1));
    }
    $run(['bash', $directory . '/bin/install', '--yes']);
    $state = $request($base, '/api/state');
    $request($base, '/api/login', ['username' => $credentials[1], 'password' => $password], $state['csrf']);
    check(true, 'Real installer rerun preserves the changed administrator password and existing chat');
} finally {
    if (is_resource($engine)) { proc_terminate($engine); proc_close($engine); }
    if (is_file($directory . '/compose.yaml')) $run(array_merge($compose, ['down', '--volumes', '--remove-orphans']), false);
    if ($oldProject === false) putenv('COMPOSE_PROJECT_NAME'); else putenv('COMPOSE_PROJECT_NAME=' . $oldProject);
    foreach ($oldEnvironment as $key => $value) { if ($value === false) putenv($key); else putenv($key . '=' . $value); }
    fclose($cookie);
    echo 'Removed only the isolated test containers/database. Private fixture checkout retained at ' . $directory . "\n";
}

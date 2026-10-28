<?php
require __DIR__ . '/helpers.php';

$db = Agent\Database::connection();
$schema = 'boundary_' . bin2hex(random_bytes(6));
$oldOptions = getenv('PGOPTIONS');
try {
    $db->exec('CREATE SCHEMA ' . $schema);
    $db->exec('SET search_path TO ' . $schema . ', public');
    putenv('PGOPTIONS=-c search_path=' . $schema . ',public');
    foreach (glob(dirname(__DIR__) . '/database/*.sql') as $migration) $db->exec(file_get_contents($migration));
    $db->prepare("INSERT INTO settings (key, value) VALUES ('installation', ?::jsonb)")->execute([json_encode(['auth' => 'standalone'])]);
    Agent\Identity::createUser('boundary_user', 'Isolated boundary password 123!', 'Boundary fixture');

$root = dirname(__DIR__);
$assets = [];
$types = ['css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml'];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/public', FilesystemIterator::SKIP_DOTS)) as $file) {
    $relative = substr($file->getPathname(), strlen($root . '/public/'));
    check(!$file->isLink(), 'Public tree contains no symbolic links: ' . $relative);
    if ($relative === 'index.php') continue;
    $privateName = preg_match('~(?:^|/)\\.|\\.(?:php[0-9]*|phtml|phar|inc|bak|old|orig|backup|swp|save|tmp)(?:\\.|/|$)~i', $relative);
    check(!$privateName && strpos($relative, 'assets/') === 0 && isset($types[$file->getExtension()]), 'Public tree contains only browser assets: ' . $relative);
    $assets['/' . $relative] = ['body' => file_get_contents($file->getPathname()), 'type' => $types[$file->getExtension()]];
}
check(count(glob($root . '/public/*.php')) === 1 && is_file($root . '/views/document.php') && is_file($root . '/router.php'), 'Only the front controller is public PHP; routing and templates are outside public');

function boundaryRequest($origin, $path, $cookiePath, $body = null, $csrf = '', $head = false)
{
    $headers = [];
    $curl = curl_init($origin . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookiePath, CURLOPT_COOKIEJAR => $cookiePath,
        CURLOPT_TIMEOUT => 15, constant('CURLOPT_PATH_AS_IS') => true, CURLOPT_NOBODY => $head,
        CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headers) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            return strlen($line);
        }]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]);
    }
    $text = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); $error = curl_error($curl);
    if (is_resource($curl)) curl_close($curl);
    if ($text === false) throw new RuntimeException('Boundary HTTP transport failed: ' . $error);
    return ['status' => $status, 'body' => $text, 'headers' => $headers, 'json' => json_decode($text, true)];
}

function boundaryServer($directory, $routed, $base, $callback)
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException($error);
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $log = tmpfile(); $cookie = tmpfile();
    $cookiePath = stream_get_meta_data($cookie)['uri'];
    putenv('PHP_CLI_SERVER_WORKERS');
    $command = 'exec env APP_BASE_PATH=' . escapeshellarg($base) . ' ' . escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($address) . ' -t ' . escapeshellarg($directory . '/public');
    if ($routed) $command .= ' ' . escapeshellarg($directory . '/public/index.php');
    $process = proc_open($command, [['file', '/dev/null', 'r'], $log, $log], $pipes, $directory);
    if (!is_resource($process)) throw new RuntimeException('Cannot start isolated HTTP server');
    try {
        $ready = false;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
            if ($probe) { fclose($probe); $ready = true; break; }
            usleep(20000);
        }
        check($ready, 'Isolated boundary server starts');
        $callback('http://' . $address, $cookiePath);
    } finally {
        proc_terminate($process); proc_close($process); fclose($log); fclose($cookie);
    }
}

$denied = ['/config/profiles.json', '/config/profiles.local.json', '/classes/Agent/Config.php', '/vendor/autoload.php',
    '/storage/', '/views/page.php', '/views/document.php', '/bootstrap.php', '/router.php', '/.env', '/.git/config',
    '/index.php.bak', '/assets/app.js.map', '/assets/app.js.bak', '/assets/private.php', '/assets/.env',
    '/../.env', '/%2e%2e/.env', '/assets/%2e%2e/%2e%2e/.env', '/assets/%252e%252e/%252e%252e/.env',
    '/assets/%2ehidden.css', '/assets/avatars/%2e%2e/%2e%2e/index.php', '/assets%2f..%2f..%2f.env',
    '/assets/%5c..%5c.env', '/assets/app.js%00.css'];

foreach ([false, true] as $routed) {
    boundaryServer($root, $routed, '', function ($origin, $cookie) use ($assets, $denied, $routed) {
        $mode = $routed ? 'front-controller server' : 'plain public document root';
        $page = boundaryRequest($origin, '/?new=1', $cookie);
        check($page['status'] === 200 && strpos($page['body'], '<!doctype html>') !== false, $mode . ': PHP page renders');
        check(boundaryRequest($origin, '/index.php?new=1', $cookie)['status'] === 200, $mode . ': direct index uses the same front controller');
        $state = boundaryRequest($origin, '/api/state', $cookie);
        check($state['status'] === 200 && !empty($state['json']['csrf']), $mode . ': API routes work');
        $login = boundaryRequest($origin, '/api/login', $cookie, ['username' => 'boundary_user', 'password' => 'Isolated boundary password 123!'], $state['json']['csrf']);
        check($login['status'] === 200 && !empty($login['json']['user']), $mode . ': authentication works');
        $fragment = boundaryRequest($origin, '/fragments/threads?tab=private', $cookie);
        check($fragment['status'] === 200 && strpos($fragment['headers']['content-type'], 'text/html') === 0, $mode . ': authorized PHP fragments render');
        foreach ($assets as $path => $expected) {
            $response = boundaryRequest($origin, $path . '?v=boundary', $cookie);
            $mime = $response['headers']['content-type'];
            $validMime = strpos($mime, $expected['type']) === 0 || ($expected['type'] === 'text/javascript' && strpos($mime, 'application/javascript') === 0);
            check($response['status'] === 200 && $response['body'] === $expected['body'] && $validMime,
                $mode . ': browser asset and MIME ' . $path);
        }
        $head = boundaryRequest($origin, '/assets/app.js?v=head', $cookie, null, '', true);
        check($head['status'] === 200 && $head['body'] === '', $mode . ': asset HEAD sends no response body');
        foreach ($denied as $path) {
            $response = boundaryRequest($origin, $path, $cookie);
            check(in_array($response['status'], [400, 404], true) && strpos($response['body'], '<?php') === false, $mode . ': private path denied ' . $path);
        }
        if ($routed) check(boundaryRequest($origin, '/assets/app.js/extra', $cookie)['status'] === 404, 'Front-controller server rejects suffixes after an asset filename');
    });
}

boundaryServer($root, true, '/erp/phillip', function ($origin, $cookie) {
    $page = boundaryRequest($origin, '/erp/phillip/?new=1', $cookie);
    check($page['status'] === 200 && strpos($page['body'], 'src="/erp/phillip/assets/app.js"') !== false, 'ERP base path preserves generated browser URLs and query strings');
    check(boundaryRequest($origin, '/erp/phillip/api/state?boundary=1', $cookie)['status'] === 200, 'ERP base path dispatches API routes');
    check(boundaryRequest($origin, '/erp/phillip/assets/avatar.css?v=1', $cookie)['status'] === 200, 'ERP base path serves nested CSS imports');
    check(boundaryRequest($origin, '/api/state', $cookie)['status'] === 404, 'ERP base path does not expose routes outside its mount');
    check(boundaryRequest($origin, '/erp/phillip/assets/%2e%2e/%2e%2e/.env', $cookie)['status'] === 404, 'ERP base path rejects encoded traversal');
});

// Adversarial files exist only in this disposable app tree, never in live public/.
$temporary = sys_get_temp_dir() . '/phillip-boundary-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700); mkdir($temporary . '/public', 0700); mkdir($temporary . '/public/assets', 0700);
$fixtureFiles = [];
try {
    foreach (['router.php', 'public/index.php'] as $path) { copy($root . '/' . $path, $temporary . '/' . $path); $fixtureFiles[] = $temporary . '/' . $path; }
    symlink($root . '/bootstrap.php', $temporary . '/bootstrap.php'); $fixtureFiles[] = $temporary . '/bootstrap.php';
    mkdir($temporary . '/public/assets-sibling', 0700);
    $malicious = ['public/assets/private.php' => '<?php echo "EXECUTABLE_BOUNDARY_SECRET";',
        'public/assets/private.php.js' => 'SOURCE_BOUNDARY_SECRET', 'public/assets/app.js.bak' => 'BACKUP_BOUNDARY_SECRET',
        'public/assets/app.old.css' => 'BACKUP_BOUNDARY_SECRET', 'public/assets/.hidden.css' => 'HIDDEN_BOUNDARY_SECRET',
        'public/assets/README.txt' => 'PRIVATE_BOUNDARY_SECRET', 'public/assets-sibling/escape.css' => 'PREFIX_BOUNDARY_SECRET',
        'secret.css' => 'ESCAPE_BOUNDARY_SECRET'];
    foreach ($malicious as $path => $bytes) { file_put_contents($temporary . '/' . $path, $bytes); $fixtureFiles[] = $temporary . '/' . $path; }
    foreach (['escape.css' => $temporary . '/secret.css', 'prefix.css' => $temporary . '/public/assets-sibling/escape.css', 'hidden.css' => $temporary . '/public/assets/.hidden.css'] as $name => $target) {
        symlink($target, $temporary . '/public/assets/' . $name); $fixtureFiles[] = $temporary . '/public/assets/' . $name;
    }
    boundaryServer($temporary, true, '', function ($origin, $cookie) use ($malicious) {
        $paths = array_keys($malicious);
        foreach (['public/assets/escape.css', 'public/assets/prefix.css', 'public/assets/hidden.css'] as $path) $paths[] = $path;
        foreach ($paths as $path) {
            $response = boundaryRequest($origin, '/' . preg_replace('~^public/~', '', $path), $cookie);
            check($response['status'] === 404 && strpos($response['body'], 'BOUNDARY_SECRET') === false, 'Routed adversarial asset denied: ' . $path);
        }
    });
} finally {
    foreach (array_reverse($fixtureFiles) as $path) unlink($path);
    rmdir($temporary . '/public/assets-sibling'); rmdir($temporary . '/public/assets'); rmdir($temporary . '/public'); rmdir($temporary);
}
} finally {
    $db->exec('SET search_path TO public');
    $db->exec('DROP SCHEMA IF EXISTS ' . $schema . ' CASCADE');
    putenv($oldOptions === false ? 'PGOPTIONS' : 'PGOPTIONS=' . $oldOptions);
}

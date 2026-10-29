<?php
require __DIR__ . '/bootstrap.php';

// Always handle the response, including assets: never delegate unchecked paths
// back to the development server's static-file/PHP dispatcher.
$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
if (!is_string($path)) { http_response_code(404); echo 'Not found'; return; }
$path = rawurldecode($path);
if (preg_match('/[\\x00-\\x1f\\x7f]/', $path) || strpos($path, '\\') !== false || strpos($path, '%') !== false) {
    http_response_code(404); echo 'Not found'; return;
}
foreach (explode('/', $path) as $segment) {
    if ($segment !== '' && $segment[0] === '.') { http_response_code(404); echo 'Not found'; return; }
}
$base = rtrim(getenv('APP_BASE_PATH') ?: '', '/');
if ($base && strpos($path, $base . '/') === 0) $path = substr($path, strlen($base));
elseif ($base && $path === $base) $path = '/';
elseif ($base) { http_response_code(404); echo 'Not found'; return; }
if ($path === '/index.php') $path = '/';
if (strpos($path, '/api/') === 0) { \Agent\Controller::handle($path); return; }
if (strpos($path, '/fragments/') === 0) { \Agent\Views::fragment($path); return; }
if (strpos($path, '/assets/') === 0) {
    $assetRoot = realpath(__DIR__ . '/public/assets');
    $file = realpath(__DIR__ . '/public' . $path);
    if ($assetRoot && $file && strpos($file, $assetRoot . DIRECTORY_SEPARATOR) === 0 && is_file($file)) {
        $types = ['js' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'svg' => 'image/svg+xml',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon',
            'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav'];
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $sourceName = preg_match('/\\.(?:php[0-9]*|phtml|phar|inc|bak|old|orig|backup|swp|save|tmp)(?:\\.|$)/i', $path . '/' . basename($file));
        foreach (explode(DIRECTORY_SEPARATOR, substr($file, strlen($assetRoot) + 1)) as $segment) {
            if ($segment !== '' && $segment[0] === '.') $sourceName = true;
        }
        if (!$sourceName && isset($types[$extension])) {
            header('Content-Type: ' . $types[$extension]); header('X-Content-Type-Options: nosniff');
            if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'HEAD') readfile($file);
            return;
        }
    }
    http_response_code(404); return;
}
if ($path === '/health') {
    header('Content-Type: application/json');
    try {
        $version = \Agent\Database::connection()->query('SELECT version()')->fetchColumn();
        echo json_encode(['status' => 'ok', 'php' => PHP_VERSION, 'database' => $version]);
    } catch (\Exception $e) {
        http_response_code(503);
        \Agent\Log::trace('Health check failed', $e); $message = \Agent\Runtime::publicMessage($e); \Agent\Log::writeError($message);
        echo json_encode(['status' => 'unavailable', 'error' => $message]);
    }
    return;
}
$route = $path === '/' ? null : \Agent\Database::row('SELECT id FROM pages WHERE path=?', [$path]);
if ($path === '/' || $route) {
    if ($route) $_GET['view'] = $route['id'];
    $pageError = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $state = \Agent\Views::state();
            if (\Agent\Onboarding::pending() && isset($_POST['action']) && strpos($_POST['action'], 'project-') === 0) throw new \RuntimeException('Finish setup before using projects');
            if (isset($_POST['action']) && strpos($_POST['action'], 'manage-') === 0) {
                if (\Agent\Onboarding::pending()) throw new \RuntimeException('Finish setup first');
                \Agent\Administration::form($state);
            }
            elseif (isset($_POST['action']) && strpos($_POST['action'], 'benchmark-') === 0) { if (\Agent\Onboarding::pending()) throw new \RuntimeException('Finish setup first'); \Agent\BenchmarkFrontend::form($state); }
            elseif (isset($_POST['action']) && strpos($_POST['action'], 'project-') === 0) \Agent\ProjectFrontend::form($state);
            else \Agent\PageActions::handle($state);
            return;
        }
        catch (\Throwable $e) { http_response_code(\Agent\Runtime::isExpected($e) ? 400 : 500); $pageError = \Agent\Runtime::publicMessage($e); }
    }
    require __DIR__ . '/views/document.php'; return;
}
http_response_code(404); echo 'Not found';

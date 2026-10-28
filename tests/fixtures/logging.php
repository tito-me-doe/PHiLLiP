<?php
require dirname(__DIR__, 2) . '/bootstrap.php';
if (getenv('PHILLIP_TEST_SESSION_PATH')) session_save_path(getenv('PHILLIP_TEST_SESSION_PATH'));
Agent\Log::configure(['file' => Agent\Log::OFF]);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (in_array($path, ['/assets/messages.js', '/assets/messages.css'], true)) {
    header('Content-Type: ' . (substr($path, -3) === '.js' ? 'text/javascript' : 'text/css'));
    readfile(dirname(__DIR__, 2) . '/public' . $path); return;
}
if ($path === '/login') {
    header('Content-Type: application/json'); $body = json_decode(file_get_contents('php://input'), true);
    echo json_encode(Agent\Auth::login($body['username'], $body['password'])); return;
}
// No explicit Auth::current(): the common runtime must lazily resolve this request's credentials.
Agent\Log::writeDebug('SECRET_DEBUG_FIXTURE');
Agent\Log::writeInfo('Admin information fixture');
if ($path === '/json') {
    header('Content-Type: application/json'); Agent\Log::writeWarning('API warning fixture');
    echo '{"ok":true}'; return;
}
Agent\Log::writeError('<img src=x onerror=alert(1)> Early error fixture');
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><link rel="stylesheet" href="/assets/messages.css"><script defer src="/assets/messages.js"></script>' . Agent\Log::headerHtml();
Agent\Log::writeSuccess('Late success fixture');
Agent\Log::with(['console' => Agent\Log::VERBOSE], function () { Agent\Log::writeVerbos('SCOPED_VERBOSE_FIXTURE'); });
Agent\Log::writeWarning('%s %d', ['Late malformed format']);
echo '<main>Content fixture</main>' . Agent\Log::footerHtml();

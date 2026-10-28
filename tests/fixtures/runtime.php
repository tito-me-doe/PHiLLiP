<?php
require dirname(__DIR__, 2) . '/bootstrap.php';
Agent\Log::configure(['path' => getenv('PHILLIP_TEST_LOG_FILE'), 'file' => Agent\Log::DEBUG, 'database' => Agent\Log::OFF,
    'console' => Agent\Log::DEBUG, 'user' => Agent\Log::WARNING, 'admin' => Agent\Log::INFO]);
$mode = PHP_SAPI === 'cli' ? $argv[1] : (isset($_GET['mode']) ? $_GET['mode'] : 'warnings');
if (PHP_SAPI !== 'cli') {
    $audience = isset($_GET['audience']) ? $_GET['audience'] : 'user';
    putenv('APP_DEVELOPER_IDS=fixture-developer');
    Agent\Runtime::bindIdentity(['id' => 'fixture-' . $audience, 'is_admin' => $audience === 'admin']);
}
if ($mode === 'warnings') {
    trigger_error('WARNING_SECRET', E_USER_WARNING);
    trigger_error('NOTICE_SECRET', E_USER_NOTICE);
    trigger_error('DEPRECATED_SECRET', E_USER_DEPRECATED);
    @trigger_error('SUPPRESSED_SECRET', E_USER_WARNING);
    $saved = error_reporting(E_ERROR);
    trigger_error('MASKED_SECRET', E_USER_WARNING);
    error_reporting($saved);
} elseif ($mode === 'caught') {
    try { trigger_error('CAUGHT_SECRET', E_USER_ERROR); }
    catch (ErrorException $error) { echo json_encode(['caught' => $error->getSeverity() === E_USER_ERROR]); return; }
} elseif ($mode === 'userfatal') {
    trigger_error('USER_FATAL_SECRET', E_USER_ERROR);
} elseif ($mode === 'fatal') {
    require __DIR__ . '/runtime_fatal.txt';
} elseif ($mode === 'parse') {
    require __DIR__ . '/runtime_parse.txt';
} elseif ($mode === 'error') {
    runtime_missing_function();
} elseif ($mode === 'oom') {
    ini_set('memory_limit', '24M');
    $chunks = [];
    while (true) $chunks[] = str_repeat('x', 1048576);
} elseif ($mode === 'shutdown') {
    register_shutdown_function(function () { trigger_error('SHUTDOWN_SECRET', E_USER_WARNING); });
} elseif ($mode === 'exception' || $mode === 'json_type' || $mode === 'sent' || $mode === 'stream') {
    if (PHP_SAPI === 'cli') throw new RuntimeException('EXCEPTION_SECRET');
    if ($mode === 'json_type') header('Content-Type: application/problem+json');
    if ($mode === 'sent') {
        echo 'ALREADY_SENT';
        while (ob_get_level()) ob_end_flush();
        flush();
    } elseif ($mode !== 'stream') {
        header('Content-Length: 1'); header('Content-Disposition: attachment; filename=wrong');
        header('Location: /wrong'); header('Content-Encoding: identity');
        echo 'PARTIAL_PAYLOAD';
    }
    throw new RuntimeException('EXCEPTION_SECRET');
}
header('Content-Type: application/json');
echo '{"ok":true}';

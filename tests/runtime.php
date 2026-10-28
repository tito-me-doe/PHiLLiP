<?php
require __DIR__ . '/helpers.php';
$log = tmpfile(); $logPath = stream_get_meta_data($log)['uri'];
$serverLog = tmpfile(); $serverPath = stream_get_meta_data($serverLog)['uri'];
$fixture = __DIR__ . '/fixtures/runtime.php';
$command = 'exec env PHILLIP_TEST_LOG_FILE=' . escapeshellarg($logPath) . ' ' . escapeshellarg(PHP_BINARY);
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$listener) throw new RuntimeException($error);
$address = stream_socket_get_name($listener, false); fclose($listener);
putenv('PHP_CLI_SERVER_WORKERS');
$server = proc_open($command . ' -S ' . escapeshellarg($address) . ' ' . escapeshellarg($fixture),
    [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', $serverPath, 'a']], $pipes);
function runtimeRecords($path)
{
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return array_map(function ($line) { return json_decode($line, true); }, $lines);
}
try {
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        if (!is_resource($server) || !proc_get_status($server)['running']) throw new RuntimeException('Runtime fixture failed to start');
        $socket = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
        if ($socket) { fclose($socket); $ready = true; break; }
        usleep(20000);
    }
    check($ready, 'Runtime HTTP fixture starts on an isolated loopback port');
    foreach (['warnings', 'caught', 'userfatal', 'fatal', 'parse', 'error', 'exception', 'oom', 'shutdown'] as $mode) {
        ftruncate($log, 0);
        $child = proc_open($command . ' ' . escapeshellarg($fixture) . ' ' . escapeshellarg($mode),
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($child);
        $records = runtimeRecords($logPath);
        if (in_array($mode, ['warnings', 'caught', 'shutdown'], true)) {
            check($exit === 0 && json_decode($out, true) === ($mode === 'caught' ? ['caught' => true] : ['ok' => true]), 'CLI ' . $mode . ' continues without corrupting stdout');
        } else {
            $fatals = array_values(array_filter($records, function ($record) { return $record['priority'] === Agent\Log::FATAL; }));
            check($exit !== 0 && $out === '' && count($fatals) === 1 && substr_count($err, '[Fatal]') === 1, 'CLI ' . $mode . ' exits nonzero with exactly one logged fatal on stderr');
            check(strpos($fatals[0]['message'], '"file"') !== false && strpos($fatals[0]['message'], '"line"') !== false, 'CLI ' . $mode . ' preserves its original diagnostic location');
        }
        if ($mode === 'warnings') {
            check(array_column($records, 'priority') === [Agent\Log::WARNING, Agent\Log::DEBUG, Agent\Log::DEBUG], 'Warnings, notices and deprecations map to shared priorities');
            check(strpos($err, 'SUPPRESSED_SECRET') === false && strpos($err, 'MASKED_SECRET') === false && count($records) === 3, 'Suppression and error_reporting masks are respected');
        }
        if ($mode === 'caught') check(strpos(json_encode($records), 'CAUGHT_SECRET') === false, 'Caught user errors are not double-reported as global fatals');
    }
    foreach ([['warnings', 'json', 'user'], ['shutdown', 'json', 'user'], ['exception', 'json', 'user'], ['exception', 'html', 'user'],
        ['exception', 'json', 'admin'], ['exception', 'json', 'developer'], ['json_type', 'html', 'user'], ['error', 'json', 'user'],
        ['fatal', 'json', 'user'], ['parse', 'json', 'user'], ['userfatal', 'json', 'user'], ['oom', 'json', 'user'],
        ['stream', 'stream', 'user'], ['sent', 'json', 'user']] as $case) {
        list($mode, $format, $audience) = $case; ftruncate($log, 0);
        $headers = [];
        $curl = curl_init('http://' . $address . '/?mode=' . $mode . '&audience=' . $audience);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Accept: ' . ($format === 'json' ? 'application/json' : ($format === 'stream' ? 'text/event-stream' : 'text/html'))],
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headers) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                return strlen($line);
            }]);
        $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); $curlError = curl_error($curl);
        if (is_resource($curl)) curl_close($curl);
        check(is_string($body) && $curlError === '', 'HTTP ' . $mode . '/' . $format . '/' . $audience . ' is a complete response');
        $messages = isset($headers['x-phillip-messages']) ? json_decode(base64_decode($headers['x-phillip-messages']), true) : [];
        if ($mode === 'sent') {
            check($body === 'ALREADY_SENT' && count(runtimeRecords($logPath)) === 2, 'Already-sent responses are not corrupted by an appended error document'); continue;
        }
        $fatal = !in_array($mode, ['warnings', 'shutdown'], true);
        check($status === ($fatal ? 500 : 200), 'HTTP ' . $mode . ' has the expected lifecycle status');
        if ($format === 'stream') check(strpos($body, "event: error\ndata: {") === 0 && strpos($body, '<html') === false, 'Failed event streams use an SSE error frame, never HTML');
        elseif ($format === 'json' || $mode === 'json_type') check(is_array(json_decode($body, true)) && strpos($body, 'PARTIAL_PAYLOAD') === false, 'HTTP ' . $mode . ' emits one clean JSON body, including response-selected JSON');
        else check(strpos($body, '<!doctype html>') === 0 && strpos($body, 'log-fatal') !== false, 'HTML fatal uses the shared styled message container');
        check(strpos($body, '_SECRET') === false, 'HTTP ' . $mode . ' body never exposes raw engine diagnostics');
        if ($audience === 'user') check(strpos(json_encode($messages), '_SECRET') === false, 'Ordinary users do not receive hidden diagnostics in HTTP metadata');
        else {
            $raw = array_values(array_filter($messages, function ($message) { return strpos($message['message'], 'EXCEPTION_SECRET') !== false; }));
            check(count($raw) === 1 && $raw[0]['console'] && $raw[0]['visible'] === ($audience === 'admin'), 'Detailed diagnostics retain administrator/developer audience routing');
        }
        if ($fatal) check(!array_intersect_key($headers, array_flip(['content-length', 'location', 'content-disposition', 'content-encoding'])), 'Fatal response discards stale download and redirect headers');
        $records = runtimeRecords($logPath);
        check($records && strpos($records[0]['message'], '"file"') !== false && strpos($records[0]['message'], '"line"') !== false, 'HTTP ' . $mode . ' preserves diagnostic source in the server log');
    }
    check(strpos(file_get_contents($serverPath), 'PHP Fatal error') === false, 'Native fatal output does not duplicate the logger');
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    fclose($log); fclose($serverLog);
}

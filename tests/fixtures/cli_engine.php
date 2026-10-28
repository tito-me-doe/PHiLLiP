<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ($path === '/v1/models') { echo '{"data":[{"id":"inspect"}]}'; return; }
$body = json_decode(file_get_contents('php://input'), true);
if ($body['model'] === 'error') { http_response_code(400); echo '{"error":"Fixture rejection"}'; return; }
if ($body['model'] === 'slow') { for ($i = 0; $i < 30; $i++) { echo ' '; if (ob_get_level()) ob_flush(); flush(); usleep(100000); } }
$message = ['role' => 'assistant', 'content' => json_encode($body)];
if (isset($body['response_format'])) $message['content'] = $body['model'] === 'invalid-contract' ? '{"ready":"wrong"}' : '{"ready":true}';
if ($body['model'] === 'memory-auth' && (!isset($_SERVER['HTTP_AUTHORIZATION']) || $_SERVER['HTTP_AUTHORIZATION'] !== 'Bearer CLI_TEST_AUTH_SECRET')) { http_response_code(401); echo '{"error":"Missing fixture authorization"}'; return; }
if (in_array($body['model'], ['memory','memory-auth'], true)) {
    $last = end($body['messages']);
    if ($last['role'] === 'tool') $message['content'] = $last['content'];
    else {
        $message['content'] = '';
        $message['tool_calls'] = [['id' => 'fixture-memory-call', 'type' => 'function', 'function' => ['name' => 'Agent_Tools_Memory_recall', 'arguments' => '{"query":"CLI_SCOPE_FIXTURE","limit":25}']]];
    }
}
echo json_encode(['choices' => [['message' => $message, 'finish_reason' => isset($message['tool_calls']) ? 'tool_calls' : 'stop']]]);

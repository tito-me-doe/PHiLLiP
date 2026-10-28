<?php
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (strpos($path, '/secured/') === 0) {
    if (!isset($_SERVER['HTTP_AUTHORIZATION']) || $_SERVER['HTTP_AUTHORIZATION'] !== 'Bearer fixture-secret') { http_response_code(401); echo '{}'; return; }
    $path = substr($path, strlen('/secured'));
}
if ($path === '/v1/models') { echo '{"data":[{"id":"onboarding-chat","owned_by":"vllm","max_model_len":32768}]}'; return; }
if ($path === '/health') { echo '{}'; return; }
if ($path === '/sample') { echo json_encode(['status' => 'ready', 'item_count' => 2, 'items' => [['name' => 'First source', 'verified' => true], ['name' => 'Second source', 'verified' => false]]]); return; }
if ($path !== '/v1/chat/completions') { http_response_code(404); echo '{}'; return; }
$body = json_decode(file_get_contents('php://input'), true);
$last = end($body['messages']);
$text = isset($last['content']) && is_string($last['content']) ? $last['content'] : '';
$content = 'Hello from your local model.';
foreach (['PROFILE_READY', 'STREAM_READY'] as $token) if (strpos($text, $token) !== false) $content = $token;
$message = ['role' => 'assistant', 'content' => $content];
if (is_string($body['messages'][0]['content']) && strpos($body['messages'][0]['content'], 'Write a short, specific conversation title') === 0) {
    if (!empty($body['tools']) || !empty($body['response_format']) || !empty($body['stream'])) { http_response_code(400); echo '{"error":"Title requests must be ephemeral and tool-free"}'; return; }
    $message['content'] = 'A little local conversation';
} elseif (isset($body['response_format'])) $message['content'] = '{"ready":true}';
elseif (!empty($body['tools'])) {
    if ($last['role'] === 'tool') $message['content'] = 'The resource is ready.';
    else {
        $name = $body['tools'][0]['function']['name'];
        $arguments = stripos($name, 'Fetch_fetch') !== false ? json_encode(['url' => 'http://' . $_SERVER['HTTP_HOST'] . '/sample']) : '{}';
        $message = ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'onboarding-tool', 'type' => 'function', 'function' => ['name' => $name, 'arguments' => $arguments]]]];
    }
} elseif (is_array($last['content'])) $message['content'] = 'red';
if (!empty($body['stream'])) {
    header('Content-Type: text/event-stream');
    if (strpos($text, 'AVATAR_STREAM') === 0) {
        // A real, deliberately slow SSE response: browser assertions must observe motion,
        // not merely an animation class on an already completed response.
        header('X-Accel-Buffering: no');
        for ($i = 0; $i < 24; $i++) {
            echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'watermelon '], 'finish_reason' => null]]]) . "\n\n";
            if (ob_get_level()) ob_flush(); flush(); usleep(200000);
            if (strpos($text, 'FAIL') !== false && $i === 5) { echo "data: {\"error\":\"Avatar fixture stream failed\"}\n\n"; flush(); return; }
        }
        echo "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}]}\n\ndata: [DONE]\n\n";
        return;
    }
    $delta = isset($message['tool_calls']) ? ['tool_calls' => [['index' => 0] + $message['tool_calls'][0]]] : ['content' => $message['content']];
    echo 'data: ' . json_encode(['choices' => [['delta' => $delta, 'finish_reason' => null]]]) . "\n\n";
    echo 'data: ' . json_encode(['choices' => [['delta' => new stdClass(), 'finish_reason' => isset($message['tool_calls']) ? 'tool_calls' : 'stop']]]) . "\n\ndata: [DONE]\n\n";
} else echo json_encode(['choices' => [['message' => $message, 'finish_reason' => isset($message['tool_calls']) ? 'tool_calls' : 'stop']]]);

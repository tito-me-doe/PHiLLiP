<?php
$body = json_decode(file_get_contents('php://input'), true);
$prompt = json_encode(isset($body['messages']) ? $body['messages'] : []);
if (strpos($prompt, 'BENCH_FAIL') !== false) { http_response_code(503); header('Content-Type: application/json'); echo '{"error":"fixture unavailable"}'; return; }
if (strpos($prompt, 'BENCH_SLOW') !== false) usleep(800000);
if (!empty($body['stream'])) {
    header('Content-Type: text/event-stream');
    echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'FIXTURE_PASS'], 'finish_reason' => null]]]) . "\n\n";
    echo 'data: ' . json_encode(['choices' => [['delta' => new stdClass(), 'finish_reason' => 'stop']], 'usage' => ['completion_tokens' => 3]]) . "\n\n";
    echo "data: [DONE]\n\n";
} else {
    header('Content-Type: application/json');
    echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => 'FIXTURE_PASS'], 'finish_reason' => 'stop']], 'usage' => ['completion_tokens' => 3]]);
}

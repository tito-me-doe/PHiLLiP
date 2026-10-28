<?php
header('Content-Type: application/json');
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/v1/models') { echo '{"data":[{"id":"agents-inspect"}]}'; return; }
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) { http_response_code(400); echo '{"error":"JSON required"}'; return; }
if ($body['model'] === 'agents-error') { http_response_code(400); echo '{"error":"Agent fixture rejection"}'; return; }
if ($body['model'] === 'agents-slow') usleep(2000000);
if ($body['model'] === 'agents-stall') usleep(12000000);
echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => json_encode($body)], 'finish_reason' => 'stop']]]);

<?php
header('Content-Type: application/json');
$body = json_decode(file_get_contents('php://input'), true);
$native = false; $invoice = false;
foreach ($body['messages'] as $message) {
    if (is_array($message['content'])) foreach ($message['content'] as $part) if (isset($part['file']) || isset($part['image_url'])) $native = true;
    if (strpos(json_encode($message['content']), '8127') !== false) $invoice = true;
}
if ($native && $body['model'] === 'reject') { http_response_code(400); echo '{"error":"Native PDF/image input not supported"}'; return; }
if ($body['model'] === 'fail') { http_response_code(400); echo '{"error":"Bad fixture configuration"}'; return; }
$answer = $body['model'] === 'parser' ? 'Configured parser: invoice 8127' : ($native ? 'native accepted' : ($invoice ? 'text invoice 8127' : 'no invoice'));
echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => $answer], 'finish_reason' => 'stop']]]);

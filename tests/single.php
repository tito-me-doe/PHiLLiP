<?php
require __DIR__ . '/helpers.php';
$request = new Agent\Request(['preset' => 'default', 'temperature' => 0, 'max_tokens' => 256]);
$request->addMessage(['role' => 'user', 'content' => 'Reply with exactly PHILLIP_READY and no other text.']);
$answer = $request->send();
check(trim($answer) === 'PHILLIP_READY', 'Single-message response from discovered local model');
check($request->response['status'] === 200, 'Raw HTTP status retained');
check(isset($request->response['parsed']['usage']), 'Provider metadata retained');
check(count($request->request['body']['messages']) === 2, 'Assistant response appended to in-memory conversation');
echo 'Answer: ' . $answer . "\n";

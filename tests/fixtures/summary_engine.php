<?php
/**
 * Summary compaction fixture engine.
 * Validates that summary sub-requests do not carry nested thinking=true or two competing token fields,
 * and returns a deliberately concise summary so a tiny generation budget suffices.
 */
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/v1/chat/completions') {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    return;
}
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$ctk = $body['chat_template_kwargs'] ?? [];
// Thinking must be explicitly disabled, not merely left off.
if (!(isset($ctk['enable_thinking']) && $ctk['enable_thinking'] === false)) {
    http_response_code(400);
    echo json_encode(['error' => 'summary request must explicitly disable enable_thinking']);
    return;
}
// Exactly one competing token field, holding an adequate generation budget.
$tokens = 0;
$tokenValue = null;
foreach (['max_tokens', 'max_completion_tokens'] as $field) {
    if (isset($body[$field])) { $tokens++; $tokenValue = $body[$field]; }
}
if ($tokens !== 1 || !is_int($tokenValue) || $tokenValue < 512) {
    http_response_code(400);
    echo json_encode(['error' => 'summary request must carry exactly one adequate token field']);
    return;
}
// Summaries must not ship tool selections.
if (!empty($body['tools'])) {
    http_response_code(400);
    echo json_encode(['error' => 'summary request must not carry tools']);
    return;
}
// Every sub-request must declare the retained numeric length target in a message.
$targetSeen = false;
foreach ($body['messages'] ?? [] as $message) {
    if (is_string($message['content'] ?? null) && preg_match('/must not exceed\s*\d+\s*characters/', $message['content'])) {
        $targetSeen = true;
        break;
    }
}
if (!$targetSeen) {
    http_response_code(400);
    echo json_encode(['error' => 'summary system prompt omits the retained numeric length target']);
    return;
}

// Produce a bounded, compact summary regardless of input length.
echo json_encode([
    'choices' => [[
        'message' => ['role' => 'assistant', 'content' => 'compact factual summary'],
        'finish_reason' => 'stop',
    ]],
]);

<?php
require __DIR__ . '/helpers.php';
$request = new Agent\Request(['preset' => 'default', 'temperature' => 0, 'max_tokens' => 512]);
$request->addMessages([
    ['role' => 'system', 'content' => 'Answer concisely. Remember the information supplied during this conversation.'],
    ['role' => 'user', 'content' => 'Our project code is LANTERN-482. Acknowledge with OK.']
]);
$request->send();
$request->addMessage('What is our project code? Reply only with the code.');
$answer = $request->send();
check(strpos($answer, 'LANTERN-482') !== false, 'Ephemeral conversation recalls previous user message');
check(count($request->request['body']['messages']) === 5, 'Multiple turns retained in order');
$request->addMessage('What is the numeric part of that code? Reply only with the digits.');
check(strpos($request->send(), '482') !== false, 'Third turn uses preceding conversation');
check(count($request->request['body']['messages']) === 7, 'Three-turn conversation complete');

// A standalone application can use JSON configuration even without PostgreSQL.
$dsn = getenv('DB_DSN');
putenv('DB_DSN=pgsql:host=127.0.0.1;port=1;dbname=missing;connect_timeout=1');
try {
    $standalone = new Agent\Request('default');
    check($standalone->request['body']['model'] === 'nvidia/nemotron-3-super', 'JSON-configured request requires no database');
} finally { putenv('DB_DSN=' . $dsn); }

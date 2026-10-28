<?php
require __DIR__ . '/helpers.php';
$messages = [['role' => 'system', 'content' => 'Keep original instructions'], ['role' => 'user', 'content' => 'Keep original opening']];
for ($i = 0; $i < 8; $i++) $messages[] = ['role' => $i % 2 ? 'assistant' : 'user', 'content' => str_repeat('Middle discussion ', 70), 'reasoning' => str_repeat('Old reasoning ', 40), '_id' => 'e:' . ($i + 2)];
$messages = array_merge($messages, [['role' => 'user', 'content' => 'Recent question'], ['role' => 'assistant', 'content' => 'Recent answer'], ['role' => 'user', 'content' => 'Current question'], ['role' => 'assistant', 'content' => 'Current answer']]);
$before = $messages;
$prepared = Agent\Context::prepare($messages, 1000, function ($middle, $budget) {
    check(count($middle) === 8, 'Summarizer receives only the middle range');
    return 'Middle discussion established the Lantern project.';
});
check($prepared['stages'] === ['reasoning', 'tool_results', 'summary'], 'Context reduction follows agreed tier order');
check($prepared['messages'][0] === $messages[0] && $prepared['messages'][1] === $messages[1], 'Original opening preserved');
check(array_slice($prepared['messages'], -4) === array_slice($messages, -4), 'Recent tail preserved');
check($messages === $before, 'Compaction leaves source history unchanged');

$request = new Agent\Request(['preset' => 'default', 'context_window' => 4096, 'context_reserve' => 1024, 'max_tokens' => 512, 'temperature' => 0]);
$request->addMessages([['role' => 'system', 'content' => 'Answer concisely.'], ['role' => 'user', 'content' => 'Our starting project is Lantern.']]);
for ($i = 0; $i < 8; $i++) $request->addMessage(['role' => $i % 2 ? 'assistant' : 'user', 'content' => str_repeat('The project uses PHP and PostgreSQL. ', 50)]);
$request->addMessages([['role' => 'user', 'content' => 'We are still discussing Lantern.'], ['role' => 'assistant', 'content' => 'Understood.'], ['role' => 'user', 'content' => 'Answer briefly.'], ['role' => 'assistant', 'content' => 'I will.'], ['role' => 'user', 'content' => 'What programming language have we been discussing?']]);
check(stripos($request->send(), 'PHP') !== false, 'Real model continues after ephemeral range summarization');
check(count($request->request['body']['messages']) === 16, 'Full original conversation retained after summary');
check(count(array_filter($request->response['events'], function ($event) { return $event['type'] === 'context.pruned'; })) > 0, 'Pruning emits inspectable context events');
check(count(array_filter($request->request['body']['tools'], function ($tool) { return strpos($tool['function']['name'], 'History_') !== false; })) === 2, 'History tools become available after pruning');

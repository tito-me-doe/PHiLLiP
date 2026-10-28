<?php
require __DIR__ . '/helpers.php';
$user = Agent\Identity::authenticate('alice', 'phillip-demo');
$thread = Agent\Thread::create($user['id'], 'Attachment model fixture', ['manual' => true, 'parameters' => ['temperature' => 0, 'max_tokens' => 1024]]);
try {
    $file = Agent\Attachments::store($user['id'], 'delivery.txt', 'The delivery code is COPPER-482. The destination is Boise.');
    $first = $thread->enqueue($user['id'], ['content' => 'Read the attached file. Reply with the delivery code and destination.', 'attachments' => [$file]]);
    $answer = Agent\Run::execute(Agent\Run::claim($thread->id)['id']);
    check(strpos($answer, 'COPPER-482') !== false && stripos($answer, 'Boise') !== false, 'Real local model reads uploaded text');
    $thread->enqueue($user['id'], 'What was the destination in that attachment?');
    check(stripos(Agent\Run::execute(Agent\Run::claim($thread->id)['id']), 'Boise') !== false, 'Follow-up retains attachment access after database reload');
    $thread->enqueue($user['id'], 'Use the attachment read tool to read the original file, then state its delivery code.');
    check(strpos(Agent\Run::execute(Agent\Run::claim($thread->id)['id']), 'COPPER-482') !== false, 'Agent can retrieve the original file through a reflected tool');
    $events = Agent\WorkflowState::feed($thread->id, 0)['events'];
    check(count(array_filter($events, function ($event) { return $event['type'] === 'tool.result'; })) > 0, 'Attachment tool activity persists through shared events');
} finally { $thread->delete(); }

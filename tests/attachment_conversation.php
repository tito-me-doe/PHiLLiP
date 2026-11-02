<?php
require __DIR__ . '/helpers.php';
// Save the default profile (from config/profiles.local.json, the real LAN model)
// into the isolated test database so Attachments::capabilities() can find it and
// report supports_tools, enabling attachment tool registration and tool.result events.
$defaultConfig = Agent\Config::profile('default');
Agent\Profiles::save('default', $defaultConfig, false);
$db = Agent\Database::connection();
$db->prepare("UPDATE profiles SET supports_tools = 'true', supports_chat = 'true', enabled = 'true' WHERE name = 'default'")->execute();
$user = Agent\Identity::authenticate('alice', 'phillip-demo');
$thread = Agent\Thread::create($user['id'], 'Attachment model fixture', ['manual' => true, 'profile' => 'default', 'parameters' => ['temperature' => 0, 'max_tokens' => 1024]]);
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

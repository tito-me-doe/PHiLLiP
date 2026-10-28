<?php
require __DIR__ . '/helpers.php';

// Domain boundaries must inspect cleanup failures rather than sending partially
// normalized content on to the model or weakening authorization checks.
$metadata = new DateTime('2026-01-01T00:00:00Z');
$source = (object)[
    'role' => ' USER ',
    'content' => [(object)['type' => 'text', 'text' => '  {"untouched":"JSON text"}  ']],
    'tool_calls' => [(object)['id' => 'call-1', 'type' => 'function', 'function' => (object)[
        'name' => 'lookup', 'arguments' => ' {"id":"001"} '
    ]]],
    'metadata' => $metadata,
];
$message = Agent\Message::normalize($source);
check($message['content'] === [['type' => 'text', 'text' => '  {"untouched":"JSON text"}  ']], 'Message cleanup normalizes protocol objects without trimming or decoding content text');
check($message['tool_calls'][0]['function']['arguments'] === ' {"id":"001"} ', 'Shared protocol cleanup preserves serialized tool arguments byte for byte');
check($message['metadata'] === $metadata && $source->content[0] instanceof stdClass, 'Message cleanup neither traverses unknown metadata nor mutates the supplied object');
$image = 'data:image/png;base64,' . str_repeat('A', 1048577);
check(Agent\Message::normalize([['type' => 'image_url', 'image_url' => ['url' => $image]]])['content'][0]['image_url']['url'] === $image, 'Native image content larger than generic cleanup defaults remains valid at the Message boundary');
$request = new Agent\Request(['url' => 'http://127.0.0.1:1/v1/chat/completions', 'model' => 'fixture']);
$request->addMessage($source);
check($request->request['body']['messages'][0]['content'] === $message['content'], 'Request and standalone Message share protocol cleanup without transport-specific conversion');

Agent\Log::with(['user' => Agent\Log::OFF, 'admin' => Agent\Log::OFF, 'console' => Agent\Log::OFF, 'file' => Agent\Log::OFF], function () {
    $resource = fopen('php://memory', 'r+');
    try {
        foreach ([$resource, new DateTime(), INF, NAN, "bad\xffutf8"] as $invalid) {
            expectException(function () use ($invalid) {
                Agent\Message::normalize(['role' => 'assistant', 'tool_calls' => [['function' => ['arguments' => $invalid]]]]);
            }, 'JSON-compatible');
        }
    } finally { fclose($resource); }
    $cycle = new stdClass(); $cycle->self = $cycle;
    expectException(function () use ($cycle) { Agent\Message::normalize(['content' => $cycle]); }, 'circular or too deeply nested');
    $deep = 'leaf';
    for ($i = 0; $i < 40; ++$i) $deep = ['nested' => $deep];
    expectException(function () use ($deep) { Agent\Message::normalize(['tool_calls' => $deep]); }, 'circular or too deeply nested');
});

$alice = Agent\Identity::user(['username' => 'alice']);
$file = Agent\Attachments::store($alice['id'], 'cleanup-contract.txt', 'CLEANUP_ATTACHMENT_CONTENT');
try {
    foreach (['  ' . $file['id'] . "\n", ['id' => ' ' . $file['id'] . ' ', 'name' => 'FORGED'], (object)['id' => "\t" . $file['id']]] as $shape) {
        check(Agent\Attachments::normalize($shape) === [$file], 'Attachment normalization delegates dirty reference cleanup to its shared identifier boundary');
        check(strpos(Agent\Attachments::messageText(['attachments' => $shape]), 'CLEANUP_ATTACHMENT_CONTENT') !== false, 'Attachment text lookup accepts the same raw reference without caller-side trimming');
    }
    foreach ([true, -1, '01', '1e2', '1.0', '1junk', '999999999999999999999999999'] as $invalid) {
        expectException(function () use ($invalid) { new Agent\Thread($invalid); }, 'positive integer id');
    }
    expectException(function () use ($file) { Agent\Attachments::get(['id' => $file['id'] . '../']); }, 'Invalid attachment identifier');
} finally {
    Agent\Database::connection()->prepare('DELETE FROM attachments WHERE id = ?')->execute([$file['id']]);
}

$thread = Agent\Thread::create($alice['id'], 'Cleanup logging integration', ['manual'=>true]);
try {
    $run = $thread->enqueue($alice['id'], 'A background operation will normalize its input.');
    $dirty = ['enabled'=>'false','attempts'=>'PRIVATE_RAW_CLEANUP_INPUT'];
    $value = Agent\ToolContext::withValues(['thread_id'=>$thread->id,'run_id'=>$run['id']], function () use ($dirty) {
        return Agent\Utilities::cleanup($dirty, ['type'=>'object','properties'=>['enabled'=>'boolean','attempts'=>'integer']]);
    });
    check($value === ['enabled'=>false,'attempts'=>'PRIVATE_RAW_CLEANUP_INPUT'], 'Cleanup failures return partial input to the caller without throwing inside a background operation');
    $feed = Agent\WorkflowState::feed($thread->id, 0, $alice['id']);
    check(strpos(json_encode($feed), 'Cleanup $.attempts') !== false, 'Cleanup writeError uses the shared durable frontend message delivery');
    check(strpos(json_encode($feed), 'PRIVATE_RAW_CLEANUP_INPUT') === false, 'Cleanup diagnostics never copy the invalid raw field into shared events');
} finally { $thread->delete(); }

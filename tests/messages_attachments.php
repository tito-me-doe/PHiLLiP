<?php
require __DIR__ . '/helpers.php';
use Agent\Message;

$files = [];
try {
    $first = Agent\Attachments::store('message-contract-fixture', 'message-one.txt', 'MESSAGE-ATTACHMENT-ONE'); $files[] = $first['id'];
    $second = Agent\Attachments::store('message-contract-fixture', 'message-two.txt', 'MESSAGE-ATTACHMENT-TWO'); $files[] = $second['id'];
    $shapes = [$first['id'], $first, (object)$first, json_encode($first), json_encode([$first]), [$first['id']]];
    foreach ($shapes as $shape) {
        $message = Message::normalize(['content' => 'Attached', 'attachments' => $shape]);
        check($message['attachments'] === [$first], 'Message delegates a supported single attachment shape to the shared attachment contract');
        check(strpos(Message::text(['content' => 'Attached', 'attachments' => $shape]), 'MESSAGE-ATTACHMENT-ONE') !== false, 'Text extraction accepts the same attachment shape without caller-side conversion');
    }
    $message = Message::normalize(['content' => 'Both', 'attachments' => $first['id'] . ',' . $second['id']]);
    check($message['attachments'] === [$first, $second], 'CSV attachment references normalize to one canonical list');
    check(strpos(Message::text(['content' => 'Both', 'attachments' => $first['id'] . ',' . $second['id']]), 'MESSAGE-ATTACHMENT-TWO') !== false, 'CSV attachments are text-readable without an intermediate metadata lookup');
    check(Message::normalize(['attachments' => null])['attachments'] === [], 'Explicit null attachments use the attachment contract empty list');
    $forged = $first; $forged['name'] = 'forged';
    check(Message::normalize(['attachments' => $forged])['attachments'] === [$first], 'Caller-supplied attachment metadata never overrides stored metadata');
    $request = new Agent\Request(['url' => 'http://127.0.0.1:1/v1/chat/completions', 'model' => 'fixture']);
    $request->addMessages(json_encode([['content' => 'Attached', 'attachments' => $first['id']]]));
    check($request->request['body']['messages'][0]['attachments'] === [$first], 'Request message lists accept single attachment identifiers through the shared contract');
    expectException(function () { Message::normalize(['attachments' => '{bad']); }, 'Invalid attachment JSON');
    expectException(function () { Message::normalize(['attachments' => true]); }, 'Attachments must');
    expectException(function () { Message::normalize(['attachments' => ['name' => 'missing identifier']]); }, 'Attachments must');
    $raw = Message::fromRow(['id' => '42', 'role' => 'user', 'raw' => ['content' => 'Stored', 'attachments' => [$first]]]);
    Agent\Database::connection()->prepare('DELETE FROM attachments WHERE id = ?')->execute([$first['id']]);
    check(Message::fromRow(['id' => '42', 'role' => 'user', 'raw' => $raw])['attachments'] === [$first], 'Stored-row adaptation does not rehydrate or depend on attachment availability');
    check(Message::forModel($raw) === ['content' => 'Stored', 'role' => 'user'], 'Model projection stays pure and excludes attachment metadata');
} finally {
    foreach ($files as $id) Agent\Database::connection()->prepare('DELETE FROM attachments WHERE id = ?')->execute([$id]);
}

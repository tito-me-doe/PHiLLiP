<?php
require __DIR__ . '/helpers.php';

$calls = [];
$transport = function ($url, $body, $headers, $timeout) use (&$calls) {
    $calls[] = compact('url', 'body', 'headers', 'timeout');
    return ['status' => 200, 'json' => ['data' => [['id' => 'org/model']]], 'errno' => 0];
};
$studio = new Agent\UnslothStudio('http://127.0.0.1:8888', 'test-studio-key', $transport);
$models = $studio->status();
check($models['data'][0]['id'] === 'org/model' && $calls[0]['url'] === 'http://127.0.0.1:8888/v1/models' && $calls[0]['body'] === null,
    'Status uses the documented authenticated model-list endpoint and never loads a model');
check($calls[0]['headers']['Authorization'] === 'Bearer test-studio-key' && $calls[0]['timeout'] === 30,
    'The Studio API key is applied to read-only model discovery');
check(Agent\UnslothStudio::validateModelId('org/model') === 'org/model', 'Explicit model IDs pass validation');
expectException(function () { Agent\UnslothStudio::validateModelId(''); }, 'model ID');
expectException(function () { Agent\UnslothStudio::validateModelId("bad\nmodel"); }, 'control characters');
expectException(function () use ($studio) { $studio->load('org/model'); }, 'no documented REST load operation');
expectException(function () use ($studio) { $studio->unload('org/model'); }, 'no documented REST unload operation');
expectException(function () use ($transport) { new Agent\UnslothStudio('file:///etc/passwd', '', $transport); }, 'http(s) origin');
$failing = new Agent\UnslothStudio('http://127.0.0.1:8888', '', function () { return ['status' => 401, 'json' => ['detail' => 'private'], 'errno' => 0]; });
try { $failing->status(); check(false, 'Unauthorized Studio access fails clearly'); }
catch (RuntimeException $e) { check(strpos($e->getMessage(), 'HTTP 401') !== false && strpos($e->getMessage(), 'private') === false, 'Studio failures omit raw response bodies'); }

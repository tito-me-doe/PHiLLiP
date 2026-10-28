<?php
require __DIR__ . '/helpers.php';
putenv('PHP_CLI_SERVER_WORKERS');
$counter = tmpfile();
$counterPath = stream_get_meta_data($counter)['uri'];
$process = proc_open('exec env FIXTURE_COUNTER=' . escapeshellarg($counterPath) . ' ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:18993 ' . escapeshellarg(__DIR__ . '/fixtures/engine.php'),
    [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
try {
    usleep(200000);
    $config = ['url' => 'http://127.0.0.1:18993/v1/chat/completions', 'model' => 'fallback', 'max_contract_corrections' => 1];
    $contract = ['response' => ['type' => 'string'], 'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1]];
    $request = new Agent\Request($config);
    $request->registerContract($contract)->addMessage('Say hello.');
    check(strpos($request->send(1), '0.8') !== false, 'Native rejection falls back, then corrects invalid output within one agent round');
    check(count(array_filter($request->response['events'], function ($event) { return $event['type'] === 'contract.correction'; })) === 1, 'Validation failure fed back exactly once');
    $request = new Agent\Request(array_replace($config, ['model' => 'invalid']));
    $request->registerContract($contract)->addMessage('Say hello.');
    expectException(function () use ($request) { $request->send(); }, 'Contract correction limit exceeded');
    check(count($request->response['validation_errors']) > 0, 'Validation failure retained after exception');
    $request = new Agent\Request(array_replace($config, ['model' => 'retry', 'max_retries' => 2]));
    $request->addMessage('Say ready');
    check($request->send(1) === 'ready' && count($request->response['attempts']) === 3, 'Transport retries independent of agent rounds');
    file_put_contents($counterPath, '0');
    $request = new Agent\Request(array_replace($config, ['model' => 'retry', 'max_retries' => 0]));
    $request->addMessage('Say ready');
    expectException(function () use ($request) { $request->send(); }, 'HTTP error (503)');
    foreach (['length-empty', 'length-partial'] as $model) {
        $request = new Agent\Request(array_replace($config, ['model' => $model]));
        $request->registerContract($contract)->addMessage('Return the required JSON.');
        expectException(function () use ($request) { $request->send(); }, 'Model output token limit reached');
        check(count($request->response['rounds']) === 1 && !array_filter($request->response['events'], function ($event) { return $event['type'] === 'contract.correction'; }), 'Output exhaustion stops immediately instead of retrying contract corrections');
        check($request->response['messages'][0]['_truncated'] && isset($request->response['messages'][0]['reasoning_content']), 'Truncated assistant content and reasoning remain available in raw history');
    }
    $calls = 0;
    $request = new Agent\Request(array_replace($config, ['model' => 'contract-tools']));
    $request->registerTool(/** Return the required fixture evidence. */ function () use (&$calls) { $calls++; return 'Verified'; });
    $request->registerContract($contract)->addMessage('Use the tool, then return the contracted answer.');
    check(strpos($request->send(), '0.8') !== false && $calls === 1 && count($request->response['rounds']) === 2, 'Tools remain callable with a response contract instead of being blocked by native JSON grammar');
    check($request->response['validated']['confidence'] === 0.8 && !array_filter($request->response['events'], function ($event) { return $event['type'] === 'contract.fallback'; }), 'Tool-enabled answers are still validated and do not pretend an unsent native format was rejected');
    $request = new Agent\Request(array_replace($config, ['model' => 'contract-tools', 'max_rounds' => 1]));
    $request->registerTool(/** Return fixture evidence for testing round limits. */ function () { return 'Verified'; });
    $request->registerContract($contract)->addMessage('Use the tool, then return the contracted answer.');
    expectException(function () use ($request) { $request->send(); }, 'Maximum agent rounds exceeded');
    check(count($request->response['rounds']) === 1 && !isset($request->request['body']['max_rounds']), 'Profile-configured round limits are enforced locally, not forwarded to the model engine');
    check(strpos($request->send(2), '0.8') !== false, 'An explicit send limit can override the profile limit');
    $failing = /** Fail only for the unchanged bad fixture input. @param int $value Input to validate */ function (int $value) { if ($value === 1) throw new RuntimeException('Unchanged invalid input'); return 'verified'; };
    $request = new Agent\Request(array_replace($config, ['model' => 'tool-failure-loop', 'max_tool_repeats' => 3]));
    $request->registerTool($failing)->addMessage('Try the tool.');
    expectException(function () use ($request) { $request->send(); }, 'Repeated identical tool failure');
    $errors = array_filter($request->response['messages'], function ($message) { return !empty($message['_tool_error']); });
    check(count($request->response['rounds']) === 3 && count($errors) === 3, 'Identical failures stop at their independent limit while preserving every tool error');
    check(!isset(Agent\Message::forModel(end($errors))['_tool_error']), 'Internal tool error markers never leak into engine message contracts');
    $request = new Agent\Request(array_replace($config, ['model' => 'tool-failure-recover']));
    $request->registerTool($failing)->addMessage('Correct the tool input after feedback.');
    check($request->send() === 'recovered', 'Changed inputs can recover from a tool failure without a false loop error');
} finally { proc_terminate($process); proc_close($process); fclose($counter); }

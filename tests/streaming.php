<?php
require __DIR__ . '/helpers.php';
$events = [];
$request = new Agent\Request(['preset' => 'default', 'stream' => true, 'temperature' => 0, 'max_tokens' => 256,
    'on_event' => function ($event) use (&$events) { $events[] = $event; }]);
$request->addMessage('Reply exactly STREAM_READY');
check(strpos($request->send(), 'STREAM_READY') !== false, 'Streaming model output assembled into final response');
$text = '';
foreach ($events as $event) if ($event['type'] === 'text.delta') $text .= $event['data']['content'];
check($text === $request->response['content'], 'Text events reconstruct final response');
check(count($events) > 1, 'Streaming delivers incremental events');

$decoder = new Agent\StreamDecoder();
$sse = "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"call-1\",\"function\":{\"name\":\"lookup\",\"arguments\":\"{\\\"id\\\":\"}}]}}]}\r\n\r\n"
     . "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"arguments\":\"42}\"}}]},\"finish_reason\":\"tool_calls\"}]}\n\n"
     . "data: [DONE]\n\n";
foreach (str_split($sse, 7) as $chunk) $decoder->push($chunk);
check($decoder->finish()['choices'][0]['message']['tool_calls'][0]['function']['arguments'] === '{"id":42}', 'Fragmented SSE tool arguments reassembled');

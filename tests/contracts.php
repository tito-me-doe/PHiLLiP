<?php
require __DIR__ . '/helpers.php';
$contract = ['response' => ['type' => 'string'], 'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1]];
check(count(Agent\Schema::errors('{"response":"ok","confidence":2}', Agent\Schema::normalize($contract))) > 0, 'Contract bounds checked locally');
check(count(Agent\Schema::errors('{"response":"ok","confidence":0.8,"extra":1}', Agent\Schema::normalize($contract))) > 0, 'Contract rejects additional properties');
foreach ([true, false] as $native) {
    $request = new Agent\Request(['preset' => 'default', 'native_contract' => $native, 'temperature' => 0, 'max_tokens' => 1024]);
    $request->registerContract($contract);
    $request->addMessage('Give a short greeting and confidence 0.8.');
    $answer = $request->send();
    check(Agent\Schema::errors($answer, Agent\Schema::normalize($contract)) === [], 'Real-model contract output: ' . ($native ? 'native' : 'feedback fallback'));
    check(isset($request->response['validated']['response']), 'Validated data retained in response');
}

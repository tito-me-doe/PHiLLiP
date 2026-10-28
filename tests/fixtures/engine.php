<?php
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$engine = getenv('FIXTURE_ENGINE') ?: 'vllm';
if ($path === '/browser-fixture') {
    header('Content-Type: text/html');
    echo '<!doctype html><title>Browser fixture</title><h1>Dynamic page</h1><input id="name"><button id="go" onclick="document.getElementById(\'result\').textContent=document.getElementById(\'name\').value">Go</button><p id="result">Waiting</p><script>document.getElementById("result").textContent="JavaScript ready";</script>';
}
elseif ($path === '/browser-modal') {
    header('Content-Type: text/html');
    echo '<!doctype html><title>Modal controls</title><script>document.cookie="phillip_browser_fixture=retained;path=/"</script>' . str_repeat('<button>Background</button>', 45) . '<dialog open><form id="modal-form"><input type="hidden" name="csrf"><label>Persona name<input name="name"></label><select id="choice" onchange="document.getElementById(\'selected\').textContent=this.value"><option value="private">Private</option><option value="organization">Organization</option></select><p id="selected"></p><button>Save</button></form></dialog>';
}
elseif ($path === '/browser-cookie') {
    header('Content-Type: text/html');
    echo '<!doctype html><title>Cookie result</title><p>' . (isset($_COOKIE['phillip_browser_fixture']) && $_COOKIE['phillip_browser_fixture'] === 'retained' ? 'Cookie retained' : 'Cookie missing') . '</p>';
}
elseif ($path === '/v1/chat/completions') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (in_array($body['model'], ['tool-failure-loop', 'tool-failure-recover'], true)) {
        $used = 0; foreach ($body['messages'] as $message) if ($message['role'] === 'tool') $used++;
        $value = $body['model'] === 'tool-failure-recover' && $used ? 2 : 1;
        $message = ['role' => 'assistant', 'content' => 'recovered'];
        if ($body['model'] === 'tool-failure-loop' || $used < 2) $message = ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'failure-' . $used, 'type' => 'function', 'function' => ['name' => $body['tools'][0]['function']['name'], 'arguments' => json_encode(['value' => $value])]]]];
        echo json_encode(['choices' => [['message' => $message, 'finish_reason' => isset($message['tool_calls']) ? 'tool_calls' : 'stop']]]); return;
    }
    if ($body['model'] === 'contract-tools') {
        $used = false;
        foreach ($body['messages'] as $message) if ($message['role'] === 'tool') $used = true;
        $message = ['role' => 'assistant', 'content' => json_encode(['response' => 'hello', 'confidence' => $used ? 0.8 : 2])];
        if (!$used && !isset($body['response_format'])) $message = ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'contract-tool', 'type' => 'function', 'function' => ['name' => $body['tools'][0]['function']['name'], 'arguments' => '{}']]]];
        echo json_encode(['choices' => [['message' => $message, 'finish_reason' => isset($message['tool_calls']) ? 'tool_calls' : 'stop']]]);
        return;
    }
    if (in_array($body['model'], ['length-empty', 'length-partial'], true)) {
        echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => $body['model'] === 'length-empty' ? '' : '{"response":"partial', 'reasoning_content' => 'Output budget was consumed'], 'finish_reason' => 'length']]]);
        return;
    }
    if ($body['model'] === 'slow') {
        header('Content-Type: text/event-stream');
        for ($i = 0; $i < 30; $i++) {
            echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'chunk '], 'finish_reason' => null]]]) . "\n\n";
            if (ob_get_level()) ob_flush();
            flush();
            usleep(100000);
        }
        echo 'data: ' . json_encode(['choices' => [['delta' => new stdClass(), 'finish_reason' => 'stop']]]) . "\n\ndata: [DONE]\n\n";
        return;
    }
    if ($body['model'] === 'retry') {
        $file = getenv('FIXTURE_COUNTER');
        $attempt = (int)file_get_contents($file) + 1;
        file_put_contents($file, (string)$attempt);
        if ($attempt <= 2) { http_response_code(503); echo '{"error":"temporarily unavailable"}'; return; }
    }
    if (isset($body['response_format'])) { http_response_code(400); echo '{"error":"response_format json_schema not supported"}'; return; }
    $corrected = false;
    foreach ($body['messages'] as $message) if (strpos($message['content'], 'failed validation') !== false) $corrected = true;
    $content = $body['model'] === 'retry' ? 'ready' : json_encode(['response' => 'hello', 'confidence' => $corrected && $body['model'] !== 'invalid' ? 0.8 : 2]);
    echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']]]);
}
elseif ($engine === 'ollama' && $path === '/api/tags') echo '{"models":[{"name":"fixture-model"}]}';
elseif ($engine === 'ollama' && $path === '/api/ps') echo '{"models":[]}';
elseif ($engine === 'ollama' && $path === '/api/show') echo '{"capabilities":["completion","tools"],"details":{"parameter_size":"1B"}}';
elseif ($engine === 'llama.cpp' && $path === '/props') echo '{"model_path":"fixture.gguf","default_generation_settings":{},"modalities":{"vision":true}}';
elseif ($engine === 'auth' && $path === '/v1/models') { http_response_code(401); echo '{"error":{"message":"API key required"}}'; }
elseif ($path === '/v1/models') echo json_encode(['data' => [['id' => 'fixture-model', 'owned_by' => $engine, 'max_model_len' => 4096]]]);
elseif ($path === '/version') echo '{"version":"fixture-1"}';
elseif ($path === '/health') echo '{}';
else { http_response_code(404); echo '{"error":"not found"}'; }

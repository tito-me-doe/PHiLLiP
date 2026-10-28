<?php
require __DIR__ . '/helpers.php';
putenv('PHP_CLI_SERVER_WORKERS');
check(iterator_to_array(Agent\Network::hosts(['192.168.1.0/30']), false) === ['192.168.1.1', '192.168.1.2'], 'CIDR excludes network/broadcast');
check(count(iterator_to_array(Agent\Network::hosts(['127.0.0.1/32']))) === 1, 'Single-host scan');
expectException(function () { iterator_to_array(Agent\Network::hosts(['127.0.0.1/33'])); }, 'Invalid IPv4');
$process = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:18991 ' . escapeshellarg(__DIR__ . '/fixtures/engine.php'),
    [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
try {
    usleep(200000);
    $ports = Agent\Network::openPorts(['127.0.0.1'], [18991, 18992], 2, 0.2);
    check(count($ports) === 1 && $ports[0]['port'] === 18991, 'Open/closed sockets distinguished');
    $result = (new Agent\Scanner())->inspect('http://127.0.0.1:18991');
    check($result['endpoint']['engine'] === 'vllm', 'Engine detected from metadata');
    check($result['models'][0]['model'] === 'fixture-model', 'Model discovered');
    check($result['models'][0]['supports_tools'] === null, 'Unknown capability remains unknown');
    check($result['endpoint']['metadata']['/version']['version'] === 'fixture-1', 'Raw metadata retained');
} finally { proc_terminate($process); proc_close($process); }
foreach (['ollama', 'llama.cpp', 'auth'] as $engine) {
    $process = proc_open('exec env FIXTURE_ENGINE=' . escapeshellarg($engine) . ' ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:18991 ' . escapeshellarg(__DIR__ . '/fixtures/engine.php'),
        [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
    try {
        usleep(200000);
        $result = (new Agent\Scanner())->inspect('http://127.0.0.1:18991');
        if ($engine === 'auth') check($result['endpoint']['status'] === 'authentication-required', 'Authenticated endpoint reported without guessing credentials');
        else check($result['endpoint']['engine'] === $engine, 'Engine detected: ' . $engine);
        if ($engine === 'ollama') {
            check($result['models'][0]['status'] === 'available', 'Ollama available distinguished from loaded');
            check($result['models'][0]['supports_tools'] === true, 'Ollama reported capabilities retained');
        }
        if ($engine === 'llama.cpp') check($result['models'][0]['supports_vision'] === true, 'llama.cpp reported vision retained');
    } finally { proc_terminate($process); proc_close($process); }
}

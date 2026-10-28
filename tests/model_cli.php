<?php
require __DIR__ . '/helpers.php';

function modelCli(array $arguments, $stdin = '')
{
    $command = 'exec env LOG_MIN_FILE=3 LOG_MIN_DATABASE=3 NO_COLOR=1 ' . escapeshellarg(dirname(__DIR__) . '/bin/phill-models');
    foreach ($arguments as $argument) $command .= ' ' . escapeshellarg($argument);
    $capture = tmpfile(); $path = stream_get_meta_data($capture)['uri'];
    $pipes = [];
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['file', $path, 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start phill-models');
    fwrite($pipes[0], $stdin); fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]); $status = proc_close($process);
    rewind($capture); $stderr = stream_get_contents($capture); fclose($capture);
    return ['status' => $status, 'out' => $stdout, 'err' => $stderr];
}

$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$listener) throw new RuntimeException('Could not allocate fixture port');
$address = stream_socket_get_name($listener, false); fclose($listener);
$server = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($address) . ' ' . escapeshellarg(__DIR__ . '/fixtures/cli_engine.php'),
    [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $serverPipes);
$db = Agent\Database::connection(); $names = []; $files = [];
try {
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $socket = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
        if ($socket) { fclose($socket); $ready = true; break; }
        usleep(20000);
    }
    check($ready, 'Admin CLI uses an isolated, reachable fixture endpoint');
    $config = ['url' => 'http://' . $address . '/v1/chat/completions', 'model' => 'inspect', 'headers' => ['Authorization' => 'Bearer MODEL_CLI_PRIVATE_SECRET'], 'max_retries' => 0, 'timeout' => 5];
    foreach (['inline', 'file', 'stdin'] as $input) {
        $name = 'model-cli-' . $input . '-' . bin2hex(random_bytes(4)); $names[] = $name;
        $payload = json_encode(['name' => $name, 'config' => $config, 'enabled' => false]);
        $value = $payload;
        if ($input === 'file') { $file = tmpfile(); $files[] = $file; fwrite($file, $payload); $value = stream_get_meta_data($file)['uri']; }
        elseif ($input === 'stdin') $value = '-';
        $result = modelCli(['--debug', '--save', $value, '--json'], $input === 'stdin' ? $payload : '');
        $saved = json_decode($result['out'], true); $row = (new Agent\Registry())->profile($name);
        check($result['status'] === 0 && $saved['name'] === $name && !$saved['enabled'] && $row['config'] !== null, 'Profile save supports ' . $input . ' through the shared profile contract');
        check(!isset($saved['config']) && strpos($result['out'], 'MODEL_CLI_PRIVATE_SECRET') === false && strpos($result['out'], '[Debug]') === false
            && strpos($result['err'], '[Success] Model profile saved') !== false, 'Profile save keeps private config out of stdout and success messages on stderr');
    }
    $row = (new Agent\Registry())->profile($names[0]);
    foreach ([$names[0], (string)$row['id']] as $reference) {
        $before = (new Agent\Registry())->profile($names[0]);
        $result = modelCli(['--verbose', '--test', $reference, '--json']); $tested = json_decode($result['out'], true);
        check($result['status'] === 0 && $tested['capabilities']['supports_chat'] === true && isset($tested['tests']['supports_chat']['seconds']), 'Read-only capability tests resolve a profile by name or ID');
        check((new Agent\Registry())->profile($names[0]) === $before, 'Testing does not enable or modify the stored profile');
        check(strpos($result['out'], 'MODEL_CLI_PRIVATE_SECRET') === false && !isset($tested['tests']['supports_chat']['error'])
            && strpos($result['out'], '[Verbose]') === false, 'Capability output omits credentials, raw config, engine error bodies, and diagnostic logs');
    }
    $result = modelCli(['--save', '-', '--json'], json_encode(['name' => $names[0], 'config' => $config, 'enabled' => true]));
    $saved = json_decode($result['out'], true);
    check($result['status'] === 0 && $saved['enabled'] && $saved['capabilities']['supports_chat'] && $saved['tested_at'] !== null, 'Saving enabled:true runs actual capability probes before enabling');
    $result = modelCli(['--profiles']);
    check($result['status'] === 0 && strpos($result['out'], $names[0] . ' [enabled]') !== false, 'Existing human profile listing remains available');
    $result = modelCli(['--json']);
    check($result['status'] === 0 && is_array(json_decode($result['out'], true)), 'Existing JSON model listing remains available');
    foreach ([['--unknown'], ['unexpected-position'], ['--save'], ['--test'], ['--save', '{}', '--test', $names[0]],
        ['--profiles', '--save', '{}'], ['--save', '{}', '--save', '{}'], ['--json=true'], ['--save', '{bad'], ['--save', '[]']] as $arguments) {
        $result = modelCli($arguments);
        check($result['status'] !== 0 && $result['out'] === '' && strpos($result['err'], '[Error]') !== false, 'Invalid admin CLI arguments fail with stderr diagnostics and no result');
    }
    $large = str_repeat(' ', 1048577);
    $result = modelCli(['--save', '-'], $large);
    check($result['status'] !== 0 && $result['out'] === '' && strpos($result['err'], '1 MiB') !== false, 'Profile stdin is bounded to 1 MiB');
    $file = tmpfile(); $files[] = $file; fwrite($file, $large);
    $result = modelCli(['--save', stream_get_meta_data($file)['uri']]);
    check($result['status'] !== 0 && $result['out'] === '' && strpos($result['err'], '1 MiB') !== false, 'Profile files are bounded to 1 MiB');
} finally {
    foreach ($names as $name) $db->prepare('DELETE FROM profiles WHERE name = ?')->execute([$name]);
    foreach ($files as $file) fclose($file);
    proc_terminate($server); proc_close($server);
}

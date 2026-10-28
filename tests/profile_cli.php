<?php
if (PHP_SAPI === 'cli-server') {
    $counter = getenv('PROFILE_CLI_COUNTER');
    file_put_contents($counter, "request\n", FILE_APPEND);
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path === '/v1/embeddings') { header('Content-Type: application/json'); echo '{"data":[{"embedding":[1,2,3]}]}'; return; }
    if ($path === '/v1/rerank') { header('Content-Type: application/json'); echo '{"results":[{"relevance_score":1}]}'; return; }
    $body = json_decode(file_get_contents('php://input'), true);
    $prompt = json_encode(isset($body['messages']) ? $body['messages'] : []);
    if (strpos($prompt, 'STREAM_READY') !== false) {
        header('Content-Type: text/event-stream');
        echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'STREAM_READY'], 'finish_reason' => null]]]) . "\n\ndata: [DONE]\n\n";
        return;
    }
    header('Content-Type: application/json');
    $content = strpos($prompt, 'PROFILE_READY') !== false ? 'PROFILE_READY' : (strpos($prompt, 'dominant color') !== false ? 'red' : '{"ready":true}');
    if (isset($body['tools'])) {
        $toolResult = null;
        foreach ($body['messages'] as $message) if ($message['role'] === 'tool') $toolResult = $message['content'];
        $message = $toolResult === null ? ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'verify-profile-cli', 'type' => 'function', 'function' => ['name' => $body['tools'][0]['function']['name'], 'arguments' => '{}']]]] : ['role' => 'assistant', 'content' => $toolResult];
    } else $message = ['role' => 'assistant', 'content' => $content];
    echo json_encode(['choices' => [['message' => $message, 'finish_reason' => isset($message['tool_calls']) ? 'tool_calls' : 'stop']]]);
    return;
}

require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/cli_process.php';

function profileCommand(array $args, $expected = 0, array $environment = [], $input = '', $unprivileged = false)
{
    $response = cliProcess(array_merge(['profiles'], $args), $input, $unprivileged, $environment);
    check($response['status'] === $expected, 'Profiles CLI ' . (isset($args[0]) ? $args[0] : 'help') . ' exits ' . $expected . ($response['status'] === $expected ? '' : ': ' . $response['out'] . $response['err']));
    if (!$args || in_array($args[0], ['help', '--help', '-h'], true) && $expected === 0) {
        check(strpos($response['out'], 'Usage: ok-phill profiles') === 0, 'Profile help returns before model configuration');
        return $response;
    }
    $value = json_decode($response['out'], true);
    check(is_array($value) && json_last_error() === JSON_ERROR_NONE && strpos($response['out'], '[Success]') === false, 'Profile stdout is one uncontaminated JSON value');
    $safe = true;
    foreach (['HEADER_SECRET', 'API_KEY_SECRET', 'PASSWORD_SECRET', 'QUERY_SECRET', 'FRAGMENT_SECRET', 'PASSWORD_FIELD_SECRET', 'REFERENCE_SECRET'] as $secret) if (strpos($response['out'] . $response['err'], $secret) !== false) $safe = false;
    check($safe, 'Profile output excludes headers, keys, passwords, URL credentials and private references');
    if ($expected === 0) check(strpos($response['err'], 'completed') !== false, 'Profile management emits a success diagnostic');
    return $value;
}

$marker = 'profile-cli-' . bin2hex(random_bytes(5));
$directory = sys_get_temp_dir() . '/' . $marker;
if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create profile fixture directory');
$file = $directory . '/profiles.json'; $counter = $directory . '/calls.txt';
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException('Cannot allocate profile fixture port');
$address = stream_socket_get_name($socket, false); fclose($socket);
$server = proc_open('exec env ' . escapeshellarg('PROFILE_CLI_COUNTER=' . $counter) . ' ' . escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($address) . ' ' . escapeshellarg(__FILE__), [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
$db = Agent\Database::connection(); $savedRows = [];
$secretConfig = ['url' => 'https://url-user:PASSWORD_SECRET@example.invalid:8443/v1/chat/completions?api_key=QUERY_SECRET#FRAGMENT_SECRET', 'model' => 'db-fixture-model', 'headers' => ['Authorization' => 'Bearer HEADER_SECRET'], 'api_key' => 'API_KEY_SECRET', 'password' => 'PASSWORD_FIELD_SECRET', 'credentials_ref' => 'REFERENCE_SECRET', 'max_rounds' => 24, 'temperature' => 0.2, 'body' => ['top_p' => 0.9, 'private_provider_parameter' => 'HEADER_SECRET']];
$probeConfig = ['url' => 'http://' . $address . '/v1/chat/completions', 'model' => 'profile-fixture-model', 'max_retries' => 0, 'context_window' => 262144];
$fallback = ['profiles' => [
    $marker . '-db' => ['url' => 'https://shadow.invalid/v1/chat/completions', 'model' => 'must-not-shadow-database'],
    $marker . '-json' => ['preset' => $marker . '-base', 'body' => ['model' => 'json-child-model'], 'max_rounds' => 24],
    'default' => ['url' => 'https://fallback.invalid/v1/chat/completions', 'model' => 'json-default-model']
], 'presets' => [$marker . '-base' => ['url' => $secretConfig['url'], 'body' => ['model' => 'json-base-model', 'max_tokens' => 123, 'top_p' => 0.8], 'headers' => $secretConfig['headers'], 'capabilities' => ['supports_tools' => true, 'supports_vision' => false]]]];
file_put_contents($file, json_encode($fallback));
$environment = ['PROFILE_JSON' => $file];
$offline = array_replace($environment, ['DB_DSN' => 'pgsql:host=127.0.0.1;port=9;dbname=profile_cli_unavailable;connect_timeout=1']);
$initialDefault = (new Agent\Registry())->profile(['name' => 'default']);
try {
    usleep(200000);
    $row = Agent\Profiles::save($marker . '-db', $secretConfig);
    $savedRows[] = $row['id'];
    Agent\Database::execute('UPDATE profiles SET enabled = TRUE, supports_tools = TRUE, supports_vision = FALSE WHERE id = ?', [$row['id']]);
    profileCommand([], 0, $environment);
    profileCommand(['help'], 0, $offline);
    foreach ([['unknown'], ['show'], ['list', 'extra'], ['show', 'x', 'extra'], ['list', '--compact'], ['help', 'extra'], ['save'], ['save', 'x', '{}', '--unknown'], ['unknown', '-p', json_encode($probeConfig)], ['list', '-p', json_encode($probeConfig)], ['show', json_encode($probeConfig)]] as $invalid) profileCommand($invalid, 2, $environment);
    $list = profileCommand(['list'], 0, $environment);
    $selected = array_values(array_filter($list, function ($value) use ($marker) { return $value['name'] === $marker . '-db'; }));
    check(count($selected) === 1 && $selected[0]['source'] === 'database' && $selected[0]['model'] === 'db-fixture-model' && $selected[0]['enabled'] === true, 'Registry rows override fallback names and retain frontend enabled status');
    $shown = profileCommand(['show', (string)$row['id']], 0, $environment);
    check($shown === profileCommand(['show', $marker . '-db'], 0, $environment), 'Profile IDs and names share Registry lookup semantics');
    check($shown['endpoint'] === 'https://example.invalid:8443/v1/chat/completions' && $shown['capabilities']['supports_tools'] === true && $shown['capabilities']['supports_vision'] === false && $shown['parameters']['max_rounds'] === 24 && $shown['parameters']['top_p'] === 0.9 && !isset($shown['parameters']['private_provider_parameter']), 'Profile inspection sanitizes URL credentials and exposes only allowlisted merged scalar parameters');
    $json = profileCommand(['show', $marker . '-json'], 0, $environment);
    check($json['source'] === 'json' && $json['id'] === null && $json['enabled'] === null && $json['model'] === 'json-child-model' && $json['parameters']['max_rounds'] === 24 && $json['parameters']['max_tokens'] === 123 && $json['capabilities']['supports_tools'] === true, 'JSON fallback resolves inherited model, capabilities and parameters through shared Config');
    $default = profileCommand(['show', 'default'], 0, $offline);
    check($default['default'] === true && $default['model'] === 'json-default-model', 'The named default remains discoverable when the database is unavailable');
    $offlineList = profileCommand(['list'], 0, $offline);
    check(count($offlineList) === 4 && count(array_filter($offlineList, function ($entry) { return $entry['default']; })) === 1, 'Database-free list includes JSON profiles and presets with one default indicator');
    profileCommand(['show', $marker . '-missing'], 1, $environment);
    check(!file_exists($counter), 'All read and malformed commands complete without any model HTTP request');

    $newName = $marker . '-saved';
    $saved = profileCommand(['save', $newName, json_encode($secretConfig)], 0, $environment);
    $savedRows[] = $saved['id'];
    check($saved['enabled'] === false && $saved['parameters']['max_rounds'] === 24 && Agent\Config::profile($newName)['headers']['Authorization'] === 'Bearer HEADER_SECRET' && !file_exists($counter), 'Authorized save persists full configuration but returns a disabled redacted summary without probing');
    Agent\Database::execute('UPDATE profiles SET enabled = TRUE WHERE id = ?', [$saved['id']]);
    $updated = profileCommand(['save', $newName, '-'], 0, $environment, json_encode(['preset' => $marker . '-db', 'max_rounds' => 32]));
    check($updated['id'] === $saved['id'] && !$updated['enabled'] && $updated['parameters']['max_rounds'] === 32, 'Stdin updates reuse the same profile and disable a previously enabled changed configuration');
    foreach (['[]', '{bad', 'profiles.json', '"default"', '{}'] as $bad) profileCommand(['save', $marker . '-bad', $bad], 2, $environment);
    profileCommand(['save', $marker . '-oversize', '-'], 2, $environment, '{"padding":"' . str_repeat('x', 1048576) . '"}');
    check(!(int)Agent\Database::row('SELECT COUNT(*) AS count FROM profiles WHERE name IN (?, ?)', [$marker . '-bad', $marker . '-oversize'])['count'], 'Malformed and oversized save inputs do not create profile records');
    foreach ([['frontend' => true], ['run_id' => 1], ['agent_id' => 1]] as $context) Agent\ToolContext::withValues($context, function () use ($marker, $probeConfig) { expectException(function () use ($marker, $probeConfig) { Agent\ProfileCli::save($marker . '-context-denied', json_encode($probeConfig)); }, 'local operator'); });
    if (posix_geteuid() === 0) {
        $denied = profileCommand(['save', $marker . '-uid-denied', json_encode($probeConfig)], 1, [], '', true);
        check(strpos($denied['error'], 'source owner Unix UID') !== false, 'A different actual unprivileged Unix identity cannot change global model profiles');
    }
    check(!(int)Agent\Database::row('SELECT COUNT(*) AS count FROM profiles WHERE name IN (?, ?)', [$marker . '-context-denied', $marker . '-uid-denied'])['count'], 'Rejected contexts and Unix users leave no saved profiles');
    $enabled = profileCommand(['save', $marker . '-enabled', json_encode($probeConfig), '--enable'], 0, $environment);
    $savedRows[] = $enabled['id'];
    check($enabled['enabled'] === true && $enabled['capabilities']['supports_chat'] === true && file_exists($counter), 'Only explicit --enable performs real shared capability probes and enables the verified profile');
    check((new Agent\Registry())->profile(['name' => 'default']) === $initialDefault, 'Fixture saves preserve the existing default model profile');
} finally {
    foreach ($savedRows as $id) Agent\Database::execute('DELETE FROM profiles WHERE id = ?', [$id]);
    // Include failed probe fixtures, which the shared save API intentionally persists as disabled.
    Agent\Database::execute('DELETE FROM profiles WHERE name LIKE ?', [$marker . '%']);
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach ([$file, $counter] as $path) if (is_file($path)) unlink($path);
    rmdir($directory);
}

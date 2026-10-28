<?php
require __DIR__ . '/helpers.php';

$db = Agent\Database::connection();
$db->beginTransaction();
try {
    foreach ([true, 1, 'true', ' TRUE ', 'yes', ' ON ', '1'] as $value) check(Agent\Config::boolean($value) === true, 'Explicit true settings normalize without truthiness');
    foreach ([false, 0, 'false', ' FALSE ', 'no', ' OFF ', '0'] as $value) check(Agent\Config::boolean($value) === false, 'Explicit false settings normalize without truthiness');
    foreach ([null, 2, -1, 0.0, 1.0, '', ' ', 'perhaps', [], (object)[]] as $value) expectException(function () use ($value) { Agent\Config::boolean($value); }, 'explicit boolean');
    $registry = new Agent\Registry();
    $config = ['url' => 'http://127.0.0.1:65431/v1/chat/completions', 'model' => 'input-contract-fixture', 'temperature' => 0.4];
    $name = 'input-contract-' . bin2hex(random_bytes(5));
    $row = Agent\Profiles::save($name, json_encode($config), false, '{}');
    check(!Agent\Profiles::save(['name' => $name, 'config' => $config, 'enabled' => ' false '])['enabled'], 'String false does not enable a profile or perform capability probes');
    check(!Agent\Profiles::save($name, $config, 'off')['enabled'], 'Positional profile enabled uses the same explicit boolean contract');
    expectException(function () use ($name, $config) { Agent\Profiles::save(['name' => $name, 'config' => array_replace($config, ['temperature' => 1]), 'enabled' => 'perhaps']); }, 'explicit boolean');
    check(Agent\Config::profile($name) === $config, 'Malformed enabled values reject the profile before any writes');
    foreach ([$name, (int)$row['id'], (string)$row['id'], ['id' => $row['id']], ['name' => $name], $row, (object)$row, json_encode($row), json_encode($name), $config, json_decode(json_encode($config)), json_encode($config)] as $input) {
        check(Agent\Config::profile($input) === $config, 'Profiles normalize reference, row, or inline configuration: ' . (is_object($input) ? get_class($input) : gettype($input)));
    }
    check(Agent\Config::profile(['config' => json_decode(json_encode($config))]) === $config, 'Projected profile rows normalize decoded config objects');
    $forged = $row; $forged['enabled'] = true; $forged['config'] = '{}';
    check($registry->profile($forged)['enabled'] === false && $registry->profile((object)['name' => $name])['id'] === $row['id'], 'Registry row references requery authoritative permissions and settings');
    check($registry->profile(['name' => (string)$row['id']]) === false, 'An explicit numeric name never silently becomes a primary-key lookup');
    $numericName = Agent\Profiles::save((string)$row['id'], $config);
    check($registry->profile((int)$row['id'])['id'] === $row['id'] && $registry->profile((string)$row['id'])['id'] === $numericName['id'], 'Explicit integer IDs and numeric profile names remain distinguishable');
    check($registry->profile(['id' => $row['id']])['id'] === $row['id'] && Agent\Config::profile(['id' => $row['id']]) === $config, 'Primary-key projections never resolve to a conflicting numeric name');
    expectException(function () use ($registry) { $registry->profile(['id' => 'not-an-id']); }, 'Profile ID');
    check(Agent\Config::profile(' ' . $name . "\n") === $config && Agent\Config::profile(['id' => ' ' . $row['id'] . ' ']) === $config, 'Profile references trim incidental surrounding whitespace');
    $clean = Agent\Profiles::save(' ' . $name . '-new ' , $config);
    check($clean['name'] === $name . '-new', 'New profile names omit incidental surrounding whitespace');
    $db->prepare('INSERT INTO profiles (name, config) VALUES (?, ?::jsonb)')->execute([' ' . $name . ' ', json_encode($config)]);
    $legacy = Agent\Profiles::save(' ' . $name . ' ', $config);
    check($legacy['name'] === ' ' . $name . ' ' && $legacy['id'] !== $row['id'], 'Exact legacy whitespace names are preserved instead of overwriting the trimmed name');
    $nested = json_decode('{"url":"http://localhost/v1/chat/completions","body":{"model":"nested","temperature":0}}');
    check(Agent\Config::profile($nested)['body']['model'] === 'nested', 'Nested decoded JSON containers become canonical arrays');
    $callback = function () { return 0; };
    check(Agent\Config::profile(['control' => $callback])['control'] === $callback, 'Array configurations preserve callable values without JSON serialization');
    $updated = Agent\Profiles::save(['id' => (string)$row['id'], 'config' => json_encode($config + ['stream' => false]), 'overrides' => '{}']);
    check(Agent\Config::profile($updated)['stream'] === false, 'Single profile payload accepts an ID, raw config JSON, and raw overrides JSON');
    check(Agent\Profiles::save(json_encode(['name' => $name, 'config' => $config]))['id'] === $row['id'], 'JSON save payload updates the same unique profile');
    expectException(function () use ($config) { Agent\Config::profile(['config' => $config, 'model' => 'ambiguous']); }, 'Ambiguous profile');
    expectException(function () { Agent\Config::profile(['config' => 'null']); }, 'row config');
    expectException(function () { Agent\Config::profile(['not', 'a', 'config']); }, 'named keys');
    expectException(function () { Agent\Config::profile(-1); }, 'Profile ID');
    $recursive = []; $recursive['self'] = &$recursive;
    expectException(function () use ($recursive) { Agent\Config::object($recursive); }, 'recursive');
    expectException(function () use ($name, $config) { Agent\Profiles::save($name, $config, false, '{"supports_chat":"yes"}'); }, 'boolean or null');
    expectException(function () use ($name, $config) { Agent\Profiles::save($name, $config, false, '{"unknown":true}'); }, 'Unknown capability');

    $contract = ['ready' => ['type' => 'boolean']];
    $normalized = Agent\Schema::normalize($contract);
    foreach ([$contract, json_encode($contract), json_decode(json_encode($contract))] as $input) {
        check(Agent\Schema::normalize($input) == $normalized && Agent\Schema::errors('{"ready":true}', $input) === [], 'Schema normalization and validation accept the same supported inputs');
    }
    $file = __DIR__ . '/fixtures/input-contract.json';
    check(Agent\Schema::normalize($file) == $normalized, 'Schema normalization accepts contract paths directly');
    expectException(function () { Agent\Schema::normalize('[1,2]'); }, 'object');
    expectException(function () { Agent\Schema::normalize(['list']); }, 'named keys');

    $services = ['searxng' => ['url' => 'http://searxng:8080', 'headers' => ['X-Fixture' => 'value']], 'camoufox' => ['url' => 'http://browser:8000']];
    foreach ([$services, json_encode($services), json_decode(json_encode($services))] as $input) {
        check(Agent\Config::configureServices($input) === $services && Agent\Config::service('searxng') === $services['searxng'], 'Service settings share array, JSON, and decoded-object contracts');
    }
    expectException(function () { Agent\Config::configureServices('{"unknown":{"url":"http://localhost"}}'); }, 'Unknown service');
    expectException(function () { Agent\Config::configureServices(['searxng' => ['url' => 'file:///etc/passwd']]); }, 'HTTP(S) URL');
    expectException(function () { Agent\Config::configureServices(['camoufox' => ['url' => 'http://localhost', 'headers' => ['Authorization' => "value\r\nInjected: true"]]]); }, 'single-line');
    expectException(function () { Agent\Config::configureServices('[]'); }, 'JSON object');
    expectException(function () { Agent\Config::configureServices(['searxng' => ['url' => 'http://not-saved'], 'camoufox' => ['url' => 'invalid']]); }, 'HTTP(S) URL');
    check(Agent\Config::service('searxng') === $services['searxng'], 'Service map validation completes before any writes');
    $serviceEnvironment = getenv('PHILLIP_TEST_SERVICE_URL');
    try {
        $transport = ['url' => 'http://configured.invalid:8080', 'url_env' => 'PHILLIP_TEST_SERVICE_URL', 'headers' => ['X-Fixture' => 'retained']];
        Agent\Config::configureServices(['searxng' => $transport]);
        putenv('PHILLIP_TEST_SERVICE_URL=http://127.0.0.1:18083');
        check(Agent\Config::service('searxng') == array_replace($transport, ['url' => 'http://127.0.0.1:18083']), 'Transport-aware service settings resolve their URL from the current runtime without losing other settings');
        putenv('PHILLIP_TEST_SERVICE_URL');
        check(Agent\Config::service('searxng') == $transport, 'An unavailable URL environment override preserves the configured service');
        putenv('PHILLIP_TEST_SERVICE_URL=file:///etc/passwd');
        expectException(function () { Agent\Config::service('searxng'); }, 'HTTP(S) URL');
        expectException(function () { Agent\Config::configureServices(['searxng' => ['url' => 'http://configured.invalid', 'url_env' => 'BAD NAME']]); }, 'environment variable');
        Agent\Config::configureServices($services);
        check(Agent\Config::service('searxng') === $services['searxng'], 'Custom services without url_env remain authoritative despite process-wide transport variables');
    } finally { putenv($serviceEnvironment === false ? 'PHILLIP_TEST_SERVICE_URL' : 'PHILLIP_TEST_SERVICE_URL=' . $serviceEnvironment); }
} finally { $db->rollBack(); }

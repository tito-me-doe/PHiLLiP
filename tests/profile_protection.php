<?php
require __DIR__ . '/helpers.php';
putenv('PHP_CLI_SERVER_WORKERS');
$server = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:19012 ' . escapeshellarg(__DIR__ . '/fixtures/profile_header_engine.php'),
    [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
$db = Agent\Database::connection();
$db->beginTransaction();
try {
    for ($i = 0; $i < 40; $i++) {
        $socket = @stream_socket_client('tcp://127.0.0.1:19012', $errno, $error, 0.05);
        if ($socket) { fclose($socket); break; }
        usleep(50000);
    }
    $config = ['url' => 'http://127.0.0.1:19012/v1/chat/completions', 'model' => 'auth-fixture', 'headers' => ['Authorization' => 'Bearer PROFILE_CONTRACT_SECRET'], 'stream' => false, 'max_retries' => 0];
    $name = 'profile-protection-' . bin2hex(random_bytes(5));
    $row = Agent\Profiles::save($name, $config);
    $inputs = [$config, $row, (object)$row, json_encode($config), json_encode($row), $name, (int)$row['id']];
    $configured = Agent\Thread::create('fixture-profile-contracts', 'Configure profile protection', ['manual' => true]);
    foreach ($inputs as $index => $profile) {
        foreach (['create', 'configure'] as $mode) {
            if ($mode === 'create') $thread = Agent\Thread::create('fixture-profile-contracts', 'Profile protection fixture', ['manual' => true, 'profile' => $profile]);
            else { $thread = $configured; $thread->configure(['profile' => $profile]); }
            $saved = $thread->get();
            check(strpos(json_encode($saved), 'PROFILE_CONTRACT_SECRET') === false, 'Thread ' . $mode . ' never exposes authentication from profile input ' . $index);
            if (is_scalar($profile) && substr(ltrim((string)$profile), 0, 1) !== '{') check($saved['settings']['profile'] === $profile, 'Named and ID profiles remain references');
            $run = $thread->enqueue('fixture-profile-contracts', 'Check profile authentication');
            check(strpos(json_encode($run), 'PROFILE_CONTRACT_SECRET') === false && strpos(json_encode($thread->messages()), 'PROFILE_CONTRACT_SECRET') === false, 'Queued snapshots and user messages contain only private credential references');
            $db->prepare("UPDATE runs SET status = 'running', started_at = NOW() WHERE id = ?")->execute([$run['id']]);
            check(Agent\Run::execute($run['id']) === 'PROFILE_AUTH_READY', 'Run resolves private headers for profile input ' . $index . ' after ' . $mode);
            check(strpos(json_encode(Agent\Run::get($run['id'])), 'PROFILE_CONTRACT_SECRET') === false && strpos(json_encode($thread->messages()), 'PROFILE_CONTRACT_SECRET') === false, 'Completed runs and messages still omit private header values');
        }
    }

    $headers = $config['headers'];
    $wrongHeaders = ['Authorization' => 'Bearer PROFILE_BASE_SECRET'];
    foreach (['headers', 'head'] as $baseAlias) {
        $base = $config; unset($base['headers']); $base[$baseAlias] = $wrongHeaders;
        $baseName = $name . '-' . $baseAlias;
        Agent\Profiles::save($baseName, Agent\Config::protect($base));
        foreach (['headers', 'head'] as $parameterAlias) {
            foreach (['create', 'configure'] as $mode) {
                $settings = ['manual' => true, 'profile' => ['preset' => $baseName], 'parameters' => [$parameterAlias => $headers]];
                if ($mode === 'create') $thread = Agent\Thread::create('fixture-profile-contracts', 'Parameter credentials', $settings);
                else { $thread = $configured; $thread->configure($settings); }
                $saved = $thread->get();
                check(strpos(json_encode($saved), 'PROFILE_CONTRACT_SECRET') === false && strpos(json_encode($saved), 'PROFILE_BASE_SECRET') === false, 'Thread ' . $mode . ' protects ' . $parameterAlias . ' parameters over inherited ' . $baseAlias);
                $parameterRef = $saved['settings']['parameters']['credentials_ref'];
                $thread->configure(['parameters' => $saved['settings']['parameters']]);
                check($thread->get()['settings']['parameters']['credentials_ref'] === $parameterRef, 'Reconfiguring existing parameter references preserves authentication');
                $run = $thread->enqueue('fixture-profile-contracts', 'Check parameter override');
                $request = new Agent\Request(json_decode($run['settings'], true)['config']);
                check($request->request['head'] === $headers && !isset($request->request['body']['credentials_ref']), 'Parameters replace inherited credentials without sending private references as model inputs');
                $db->prepare("UPDATE runs SET status = 'running', started_at = NOW() WHERE id = ?")->execute([$run['id']]);
                check(Agent\Run::execute($run['id']) === 'PROFILE_AUTH_READY', 'Engine receives the ' . $parameterAlias . ' credential override after ' . $mode);
                check(strpos(json_encode(Agent\Run::get($run['id'])), 'PROFILE_CONTRACT_SECRET') === false && strpos(json_encode($thread->messages()), 'PROFILE_CONTRACT_SECRET') === false, 'Parameter credentials stay private in queued and completed message snapshots');
            }
        }
    }
    foreach (['headers', 'head'] as $alias) {
        $protected = Agent\Config::protect($config);
        $resolved = Agent\Config::resolve(array_replace($protected, [$alias => $wrongHeaders]));
        check($resolved['headers'] === $wrongHeaders && !isset($resolved['credentials_ref']) && !isset($resolved['head']), 'Explicit ' . $alias . ' takes precedence over a preexisting credential reference');
        $thread = Agent\Thread::create('fixture-profile-contracts', 'Clear inherited authentication', ['manual' => true, 'profile' => $protected, 'parameters' => [$alias => []]]);
        $run = $thread->enqueue('fixture-profile-contracts', 'Authentication must be absent');
        check(Agent\Config::resolve(json_decode($run['settings'], true)['config'])['headers'] === [], 'Explicit empty ' . $alias . ' clears inherited authentication');
        $db->prepare("UPDATE runs SET status = 'running', started_at = NOW() WHERE id = ?")->execute([$run['id']]);
        expectException(function () use ($run) { Agent\Run::execute($run['id']); }, 'HTTP error (401)');
    }
    foreach ([['headers' => $wrongHeaders, 'head' => $headers], ['head' => $headers, 'headers' => $wrongHeaders]] as $aliases) {
        check(Agent\Config::resolve(array_replace($protected, $aliases))['headers'] === $headers, 'Header alias precedence does not depend on insertion order');
    }
    $creationKey = bin2hex(random_bytes(12));
    $settings = ['manual' => true, 'profile' => $name, 'parameters' => ['headers' => $headers]];
    $thread = Agent\Thread::create('fixture-profile-contracts', 'Credential retry', $settings, 'private', null, $creationKey);
    check(Agent\Thread::create('fixture-profile-contracts', 'Credential retry', $settings, 'private', null, $creationKey)->id === $thread->id, 'Protecting parameter credentials preserves thread creation idempotency');
} finally {
    if ($db->inTransaction()) $db->rollBack();
    proc_terminate($server); proc_close($server);
}

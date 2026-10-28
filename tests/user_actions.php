<?php
if (PHP_SAPI === 'cli-server') {
    require dirname(__DIR__) . '/bootstrap.php';
    Agent\Log::configure(['user' => Agent\Log::VERBOSE, 'admin' => Agent\Log::VERBOSE, 'console' => Agent\Log::VERBOSE,
        'file' => Agent\Log::VERBOSE, 'path' => getenv('PHILLIP_ACTION_TEST_LOG')]);
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path === '/form') {
        try { Agent\PageActions::handle(Agent\Views::state()); }
        catch (Throwable $e) { http_response_code(400); echo Agent\Runtime::publicMessage($e); }
        return;
    }
    if ($path === '/page') { Agent\Views::state(); echo Agent\Log::headerHtml(); return; }
    Agent\Controller::handle($path); return;
}
require __DIR__ . '/helpers.php';
putenv('PHP_CLI_SERVER_WORKERS');
$log = tmpfile(); $logPath = stream_get_meta_data($log)['uri'];
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$listener) throw new RuntimeException('Cannot reserve action fixture port: ' . $error);
$address = stream_socket_get_name($listener, false); fclose($listener);
$server = proc_open('exec env PHILLIP_ACTION_TEST_LOG=' . escapeshellarg($logPath) . ' ' . escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($address) . ' ' . escapeshellarg(__FILE__),
    [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
$cookies = []; $threadIds = []; $profileName = 'action-trace-' . bin2hex(random_bytes(8));
$fixtureUser = null; $fixtureUsername = 'action-user-' . bin2hex(random_bytes(8));
$request = function ($path, $body = null, $csrf = '', $session = 'user', $form = false) use ($address, &$cookies) {
    if (!isset($cookies[$session])) $cookies[$session] = tmpfile();
    $cookiePath = stream_get_meta_data($cookies[$session])['uri']; $messages = [];
    $curl = curl_init('http://' . $address . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookiePath, CURLOPT_COOKIEJAR => $cookiePath, CURLOPT_TIMEOUT => 15,
        CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$messages) {
            if (stripos($line, 'X-PHiLLiP-Messages:') === 0) $messages = json_decode(base64_decode(trim(substr($line, strlen('X-PHiLLiP-Messages:')))), true) ?: [];
            return strlen($line);
        }]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $form ? http_build_query($body) : json_encode($body));
        curl_setopt($curl, CURLOPT_HTTPHEADER, [$form ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]);
    }
    $text = curl_exec($curl);
    if ($text === false) throw new RuntimeException(curl_error($curl));
    return ['status' => curl_getinfo($curl, CURLINFO_HTTP_CODE), 'json' => json_decode($text, true), 'text' => $text, 'messages' => $messages];
};
$outcomes = function (array $response, $priority) {
    return array_values(array_filter($response['messages'], function ($record) use ($priority) { return $record['priority'] === $priority && $record['visible']; }));
};
try {
    $fixtureUser = Agent\Identity::createUser(['username' => $fixtureUsername, 'password' => 'ACTION_LOGIN_SECRET']);
    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        if (!is_resource($server) || !proc_get_status($server)['running']) throw new RuntimeException('Action fixture failed to start');
        $socket = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
        if ($socket) { fclose($socket); $ready = true; break; }
        usleep(50000);
    }
    check($ready && proc_get_status($server)['running'], 'Action fixture runs on its own available loopback port');
    $anonymous = $request('/api/threads');
    check($anonymous['status'] === 401 && count($outcomes($anonymous, Agent\Log::ERROR)) === 1, 'Unauthenticated API actions end with a visible error');
    $state = $request('/api/state'); $csrf = $state['json']['csrf'];
    $login = $request('/api/login', ['username' => $fixtureUsername, 'password' => 'ACTION_LOGIN_SECRET'], $csrf);
    check($login['status'] === 200 && count($outcomes($login, Agent\Log::SUCCESS)) === 1, 'Successful sign-in has exactly one visible success outcome');
    $csrf = $login['json']['csrf'];
    $created = $request('/api/threads', ['title' => 'ACTION_RAW_TRACE_MARKER', 'settings' => ['manual' => true]], $csrf);
    check($created['status'] === 200 && count($outcomes($created, Agent\Log::SUCCESS)) === 1, 'Conversation creation has exactly one visible success outcome');
    $id = $created['json']['thread']['id']; $threadIds[] = $id;
    check(strpos(json_encode($created['messages']), 'ACTION_RAW_TRACE_MARKER') === false && strpos(json_encode($created['messages']), 'API action input') === false,
        'Ordinary users never receive raw action traces even with user Verbose enabled');
    $message = ['content' => 'ACTION_MESSAGE_TRACE_MARKER', 'submission_key' => bin2hex(random_bytes(12))];
    $sent = $request('/api/threads/' . $id . '/messages', $message, $csrf);
    $accepted = $outcomes($sent, Agent\Log::SUCCESS);
    check($sent['status'] === 200 && count($accepted) === 1 && $accepted[0]['message'] === 'Message accepted for processing' && $sent['json']['run']['status'] === 'queued',
        'Submission acknowledgment describes queue acceptance without claiming execution completed');
    $replayed = $request('/api/threads/' . $id . '/messages', $message, $csrf);
    check($replayed['json']['run']['id'] === $sent['json']['run']['id'] && count($outcomes($replayed, Agent\Log::SUCCESS)) === 1, 'Submission retries retain one run and one outcome per action');
    $failed = $request('/api/threads/' . $id . '/messages', ['content' => 'Missing key'], $csrf);
    check($failed['status'] === 400 && count($outcomes($failed, Agent\Log::ERROR)) === 1 && !$outcomes($failed, Agent\Log::SUCCESS), 'Rejected submissions end with an error and never a success');
    foreach (['/api/state', '/api/threads', '/api/threads/' . $id . '/events'] as $path) {
        check(!$outcomes($request($path), Agent\Log::SUCCESS), 'Polling and read responses do not emit success messages: ' . $path);
    }
    $saved = $request('/api/threads/' . $id . '/settings', ['settings' => ['parameters' => ['temperature' => 0.5]]], $csrf);
    check($saved['status'] === 200 && count($outcomes($saved, Agent\Log::SUCCESS)) === 1, 'Settings mutation reports one success outcome');
    $unknown = $request('/api/unknown-action', [], $csrf);
    check($unknown['status'] === 404 && count($outcomes($unknown, Agent\Log::ERROR)) === 1, 'Unknown action routes end with a visible error');

    $state = $request('/api/state', null, '', 'admin');
    $admin = $request('/api/login', ['username' => 'admin', 'password' => 'phillip-demo'], $state['json']['csrf'], 'admin');
    $config = ['url' => 'http://trace-user:ACTION_URL_PASSWORD@127.0.0.1:65431/v1/chat/completions?api_key=ACTION_QUERY_SECRET',
        'model' => 'ACTION_PROFILE_TRACE_MARKER', 'headers' => ['Authorization: Bearer ACTION_HEADER_SECRET']];
    $profile = $request('/api/admin/profile', ['name' => $profileName, 'config' => json_encode($config), 'enabled' => false], $admin['json']['csrf'], 'admin');
    check($profile['status'] === 200 && count($outcomes($profile, Agent\Log::SUCCESS)) === 1, 'Administrator profile changes have one success outcome');
    $traces = array_values(array_filter($profile['messages'], function ($record) { return $record['priority'] === Agent\Log::VERBOSE; }));
    check($traces && !$traces[0]['visible'] && $traces[0]['console'], 'Privileged raw traces are console diagnostics rather than visible user messages');
    $records = file_get_contents($logPath);
    check(strpos($records, 'ACTION_RAW_TRACE_MARKER') !== false && strpos($records, 'ACTION_PROFILE_TRACE_MARKER') !== false && strpos($records, 'elapsed_ms') !== false,
        'Verbose files retain raw boundary data while Debug records include action timing');
    foreach (['ACTION_URL_PASSWORD', 'ACTION_QUERY_SECRET', 'ACTION_HEADER_SECRET', 'ACTION_LOGIN_SECRET', 'phillip-demo'] as $secret) check(strpos($records, $secret) === false, 'Action traces redact credential value: ' . $secret);

    $state = $request('/api/state', null, '', 'forms');
    $form = $request('/form', ['action' => 'login', 'csrf' => $state['json']['csrf'], 'username' => $fixtureUsername, 'password' => 'ACTION_LOGIN_SECRET'], '', 'forms', true);
    check($form['status'] === 303 && count($outcomes($form, Agent\Log::SUCCESS)) === 1, 'Ordinary form sign-in logs one success before redirect');
    $page = $request('/page', null, '', 'forms');
    check(strpos($page['text'], 'Signed in') !== false && count($outcomes($page, Agent\Log::SUCCESS)) === 1, 'Form success remains visible after the redirect');
    check(!$outcomes($request('/page', null, '', 'forms'), Agent\Log::SUCCESS), 'Redirect success flash is consumed once');
    $formFailure = $request('/form', ['action' => 'send', 'csrf' => 'invalid'], '', 'forms', true);
    check($formFailure['status'] === 400 && count($outcomes($formFailure, Agent\Log::ERROR)) === 1, 'Rejected ordinary form actions log a visible error');
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach ($threadIds as $id) Agent\Database::execute('DELETE FROM threads WHERE id = ?', [$id]);
    Agent\Database::execute('DELETE FROM profiles WHERE name = ?', [$profileName]);
    if ($fixtureUser) Agent\Mappings::delete('users', ['id' => $fixtureUser['id']]);
    foreach ($cookies as $cookie) fclose($cookie);
    fclose($log);
}

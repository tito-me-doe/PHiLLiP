<?php
require __DIR__ . '/helpers.php';

// All database writes, including child workers, stay in this disposable schema.
$schema = 'onboarding_' . bin2hex(random_bytes(6));
$db = Agent\Database::connection();
$oldOptions = getenv('PGOPTIONS');
$processes = []; $cookies = []; $log = tmpfile();
$reserve = function () { $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error); if (!$socket) throw new RuntimeException($error); $address = stream_socket_get_name($socket, false); fclose($socket); return $address; };
$launch = function (array $args) use (&$processes, $log) {
    $process = proc_open('exec ' . implode(' ', array_map('escapeshellarg', $args)), [['file', '/dev/null', 'r'], $log, $log], $pipes, dirname(__DIR__));
    if (!is_resource($process)) throw new RuntimeException('Cannot start test process');
    $processes[] = $process; return $process;
};
$request = function ($url, $body = null, $csrf = '', $client = 'admin', $form = false) use (&$cookies) {
    if (!isset($cookies[$client])) $cookies[$client] = tmpfile();
    $path = stream_get_meta_data($cookies[$client])['uri'];
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $path, CURLOPT_COOKIEJAR => $path, CURLOPT_TIMEOUT => 15]);
    if ($body !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $form ? http_build_query($body) : json_encode($body), CURLOPT_HTTPHEADER => ['Content-Type: ' . ($form ? 'application/x-www-form-urlencoded' : 'application/json'), 'X-CSRF-Token: ' . $csrf]]);
    $text = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); if ($text === false) throw new RuntimeException(curl_error($curl));
    return ['status' => $status, 'text' => $text, 'json' => json_decode($text, true)];
};
try {
    $db->exec('CREATE SCHEMA ' . $schema);
    $db->exec('SET search_path TO ' . $schema . ', public');
    putenv('PGOPTIONS=-c search_path=' . $schema . ',public');
    foreach (glob(dirname(__DIR__) . '/database/*.sql') as $file) $db->exec(file_get_contents($file));
    $credentials = Agent\Onboarding::bootstrap('first_admin');
    check($credentials['created'] && strlen($credentials['password']) >= 24, 'Bootstrap creates a strong temporary password');
    check(Agent\Onboarding::bootstrap('different_admin') === ['created' => false] && count(Agent\Identity::users()) === 1, 'Rerunning bootstrap preserves credentials and creates no extra users');
    $user = Agent\Identity::authenticate('first_admin', $credentials['password']);
    expectException(function () use ($user) { Agent\Onboarding::scan($user, '127.0.0.1'); }, 'Change your temporary password');
    $address = $reserve(); $engine = $reserve();
    putenv('PHP_CLI_SERVER_WORKERS'); putenv('APP_BASE_PATH='); putenv('SCAN_CIDRS=127.0.0.1/32'); putenv('SCAN_PORTS=' . explode(':', $engine)[1]);
    $launch([PHP_BINARY, '-S', $engine, __DIR__ . '/fixtures/onboarding_engine.php']);
    $launch([PHP_BINARY, '-S', $address, '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php']);
    for ($i = 0; $i < 100; $i++) { $socket = @stream_socket_client('tcp://' . $address, $errno, $error, .05); if ($socket) { fclose($socket); break; } usleep(20000); }
    $base = 'http://' . $address;
    $state = $request($base . '/api/state')['json'];
    check(!$state['user'] && $state['onboarding'], 'Fresh browser must sign in rather than claim the administrator');
    check($request($base . '/api/setup/scan', ['cidr' => '127.0.0.1'], $state['csrf'])['status'] === 403, 'Unauthenticated discovery is forbidden');
    $login = $request($base . '/api/login', ['username' => 'first_admin', 'password' => $credentials['password']], $state['csrf']);
    $csrf = $login['json']['csrf'];
    $other = $request($base . '/api/state', null, '', 'old')['json'];
    $request($base . '/api/login', ['username' => 'first_admin', 'password' => $credentials['password']], $other['csrf'], 'old');
    check($request($base . '/api/setup/scan', ['cidr' => '127.0.0.1'], $csrf)['status'] === 403, 'Temporary-password session cannot bypass the first screen');
    check($request($base . '/api/threads', [], $csrf)['status'] === 403, 'Temporary-password session cannot create conversations');
    check($request($base . '/fragments/threads')['status'] === 403, 'Temporary-password session cannot load conversation fragments');
    check($request($base . '/', ['action' => 'project-create', 'csrf' => $csrf, 'title' => 'Bypass attempt'], '', 'admin', true)['status'] === 400, 'Ordinary project forms cannot bypass onboarding');
    $newPassword = 'A fresh local password 123!';
    check($request($base . '/api/setup/password', ['password' => $newPassword, 'confirmation' => $newPassword], 'bad')['status'] === 403, 'Password replacement requires CSRF protection');
    check($request($base . '/api/setup/password', ['password' => 'short', 'confirmation' => 'short'], $csrf)['status'] === 400, 'Weak replacement passwords are rejected');
    $change = $request($base . '/api/setup/password', ['password' => $newPassword, 'confirmation' => $newPassword], $csrf);
    check($change['status'] === 200, 'First administrator replaces the temporary password');
    expectException(function () use ($credentials) { Agent\Identity::authenticate('first_admin', $credentials['password']); }, 'Invalid username or password');
    check(!$request($base . '/api/state', null, '', 'old')['json']['user'], 'Other temporary-password sessions are invalidated');
    $state = $request($base . '/api/state')['json']; $csrf = $state['csrf'];
    check($request($base . '/api/setup', ['configuration' => ['auth' => 'standalone']], $csrf)['status'] === 200, 'One-user standalone preferences require no teams or additional accounts');
    check($request($base . '/api/setup/finish', [], $csrf)['status'] === 403, 'Setup cannot finish without a verified default chat profile');
    check($request($base . '/api/setup/scan', ['cidr' => '10.0.0.0/8'], $csrf)['status'] === 400, 'Accidental huge network scans are rejected');
    $scan = $request($base . '/api/setup/scan', ['cidr' => '127.0.0.1/32'], $csrf);
    check($scan['json']['id'] === $request($base . '/api/setup/scan', ['cidr' => '127.0.0.1/32'], $csrf)['json']['id'], 'Repeated discovery submissions share one durable job');
    $launch([PHP_BINARY, dirname(__DIR__) . '/bin/phill-operations']);
    $until = microtime(true) + 15;
    do { $status = $request($base . '/api/setup/status')['json']; if ($status['profiles']) break; usleep(100000); } while (microtime(true) < $until);
    check(count($status['profiles']) === 1, 'Background discovery creates a selectable model profile');
    check($request($base . '/api/setup/endpoint', ['url' => 'file:///etc/passwd'], $csrf)['status'] === 400, 'Custom endpoints cannot use local-file protocols');
    check($request($base . '/api/setup/endpoint', ['url' => 'http://' . $engine . '/secured'], $csrf)['status'] === 403, 'Protected engines require their API key');
    check($request($base . '/api/setup/endpoint', ['url' => 'http://' . $engine . '/secured', 'api_key' => 'fixture-secret'], $csrf)['status'] === 200, 'Custom protected engines can be discovered with a server-side API key');
    check(strpos($request($base . '/api/setup/status')['text'], 'fixture-secret') === false, 'Discovery status never returns saved API keys');
    $protected = Agent\Database::row("SELECT id FROM profiles WHERE config->>'url' LIKE '%/secured/v1/chat/completions'");
    $selected = $request($base . '/api/setup/model', ['profile_id' => $protected['id']], $csrf);
    check($selected['status'] === 200, 'Model verification is durably queued');
    $until = microtime(true) + 20;
    do { $status = $request($base . '/api/setup/status')['json']; if ($status['ready']) break; usleep(100000); } while (microtime(true) < $until);
    check($status['ready'], 'Worker verifies and enables the default chat profile');
    check($request($base . '/api/setup/finish', [], $csrf)['status'] === 200, 'One administrator can finish all standalone setup');
    $page = $request($base . '/')['text'];
    check(strpos($page, 'id="message-form"') !== false && strpos($page, '?tab=team') === false, 'Finished setup opens functional chat without a team tab');
    $team = 'team-fixture'; Agent\Mappings::insert('teams', ['id' => $team, 'name' => 'A growing team']); Agent\Mappings::insert('memberships', ['user_id' => $user['id'], 'team_id' => $team]);
    check(Agent\Identity::sharedTeams($user['id']) === [] && Agent\Access::inTeam($user['id'], $team), 'Singleton team is hidden without revoking membership or access');
    $second = Agent\Identity::createUser('second_person', 'Another private password', 'Second person');
    Agent\Mappings::insert('memberships', ['user_id' => $second['id'], 'team_id' => $team]);
    check(count(Agent\Identity::sharedTeams($user['id'])) === 1 && strpos($request($base . '/')['text'], '?tab=team') !== false, 'Team navigation appears when another active member joins');
    Agent\Database::execute('UPDATE users SET active=FALSE WHERE id=?', [$second['id']]);
    check(Agent\Identity::sharedTeams($user['id']) === [], 'Team navigation disappears when its other member becomes inactive');
    $thread = $request($base . '/api/threads', ['title' => 'First conversation', 'settings' => ['profile' => 'default', 'tools' => []]], $csrf)['json']['thread'];
    for ($i = 0; $i < 3; $i++) {
        $run = $request($base . '/api/threads/' . $thread['id'] . '/messages', ['content' => 'Follow-up ' . $i, 'submission_key' => bin2hex(random_bytes(16))], $csrf)['json']['run'];
        Agent\Run::claim($thread['id']); Agent\Run::execute($run['id']);
        check(Agent\Run::get($run['id'])['status'] === 'completed', 'Fresh installation completes follow-up ' . ($i + 1));
    }
    $untitled = $request($base . '/api/threads', ['title' => '', 'settings' => ['profile' => 'default', 'tools' => []]], $csrf)['json']['thread'];
    $run = $request($base . '/api/threads/' . $untitled['id'] . '/messages', ['content' => 'A first untitled message', 'submission_key' => bin2hex(random_bytes(16))], $csrf)['json']['run'];
    Agent\Run::claim($untitled['id']); Agent\Run::execute($run['id']);
    $until = microtime(true) + 10;
    do { $titled = (new Agent\Thread($untitled['id']))->get(); if ($titled['title'] !== '') break; usleep(100000); } while (microtime(true) < $until);
    check($titled['title'] === 'A little local conversation', 'First completed answer automatically generates a title with the same protected model');
    check(count((new Agent\Thread($untitled['id']))->messages()) === 3, 'Ephemeral title generation never adds conversation messages');
    $renamed = $request($base . '/api/threads/' . $untitled['id'] . '/title', ['title' => '  My own title  '], $csrf);
    check($renamed['json']['thread']['title'] === 'My own title', 'Owner can rename the conversation with normalized text');
    (new Agent\Thread($untitled['id']))->generateTitle($run['id'], '');
    check((new Agent\Thread($untitled['id']))->get()['title'] === 'My own title', 'A delayed automatic title never replaces a manual title');
    check($request($base . '/api/threads/' . $untitled['id'] . '/title', ['title' => '  '], $csrf)['status'] === 400, 'An empty manual title is rejected');
    Agent\Database::execute('UPDATE users SET active=TRUE WHERE id=?', [$second['id']]);
    $foreign = new Agent\Frontend($second['id']);
    expectException(function () use ($foreign, $untitled) { $foreign->rename($untitled['id'], 'Not mine'); }, 'Thread access denied');
    expectException(function () use ($foreign, $untitled) { $foreign->delete($untitled['id']); }, 'Thread access denied');
    $before = Agent\Database::row('SELECT COUNT(*) AS count FROM messages WHERE thread_id = ?', [$untitled['id']])['count'];
    check($request($base . '/api/threads/' . $untitled['id'] . '/delete', [], $csrf)['status'] === 200, 'Owner can soft-delete a conversation');
    $deleted = (new Agent\Thread($untitled['id']))->get();
    check($deleted['deletedon'] !== null && Agent\Database::row('SELECT COUNT(*) AS count FROM messages WHERE thread_id = ?', [$untitled['id']])['count'] === $before, 'Soft deletion retains all original messages and marks deletedon');
    check(!in_array($untitled['id'], array_column((new Agent\Frontend($user['id']))->threads(), 'id'), true), 'Deleted conversations are suppressed from the shared conversation list');
    expectException(function () use ($untitled, $user) { (new Agent\Thread($untitled['id']))->enqueue($user['id'], 'Cannot restart deleted work'); }, 'conversation was deleted');
    $html = Agent\Views::toolValue(['result_count' => 2, 'verified' => true, 'unsafe' => '<script>alert(1)</script>', 'nested' => [['label' => 'A source']]]);
    check(strpos($html, 'Result count') !== false && strpos($html, '>Yes<') !== false && strpos($html, '<script>') === false, 'Tool results have human labels, boolean values, nested fields and safe escaping');
    check(Agent\ToolRegistry::label('Agent_Tools_SearchWeb_search') === 'Search Web search', 'Provider tool names become human-readable labels');
    check(Agent\ToolRegistry::label('Agent\\Tools\\Fetch::fetch') === 'Fetch', 'Qualified tool methods have concise human labels');
    check(Agent\ToolRegistry::policies('Fetch::fetch, Fetch') === ['Agent\\Tools\\Fetch'], 'Method-scoped defaults share class-scoped UI and permission policy');
    $cardState = ['csrf' => 'fixture', 'user' => $user, 'tools' => ['Agent\\Tools\\Fetch'], 'profiles' => [['name' => 'default']], 'personas' => []];
    foreach (['Blocked A' => ['Source'], 'Allowed Z' => ['Fetch::fetch'], 'Blocked B' => ['Workspace'], 'Allowed Y' => []] as $name => $tools) $cardState['personas'][] = ['id' => count($cardState['personas']) + 1, 'name' => $name, 'prompt' => 'A test persona.', 'tools' => $tools];
    $cards = new DOMDocument(); @$cards->loadHTML(Agent\Views::render('messages', ['state' => $cardState, 'current' => null]));
    $buttons = (new DOMXPath($cards))->query('//button'); $names = []; $disabled = [];
    foreach ($buttons as $button) { $names[] = $button->getElementsByTagName('strong')->item(0)->textContent; $disabled[] = $button->hasAttribute('disabled'); }
    check($names === ['Allowed Z', 'Allowed Y', 'Blocked A', 'Blocked B'] && $disabled === [false, false, true, true], 'Persona cards put usable choices first while preserving order within both groups');
    if (in_array('--browser', $argv, true)) {
        // Repeat from a clean state, without touching public tables or this installation's data.
        $tables = ['users', 'settings', 'teams', 'memberships', 'tool_permissions', 'threads', 'profiles', 'models', 'endpoints', 'operations'];
        $db->exec('TRUNCATE ' . implode(',', array_map(function ($name) use ($schema) { return $schema . '.' . $name; }, $tables)) . ' CASCADE');
        $credentials = Agent\Onboarding::bootstrap('browser_admin');
        $browserAdmin = Agent\Identity::authenticate('browser_admin', $credentials['password']);
        Agent\Personas::save($browserAdmin['id'], ['name' => 'Thoughtful teammate', 'prompt' => 'You are a kind and thoughtful teammate.', 'visibility' => 'organization']);
        Agent\Personas::save($browserAdmin['id'], ['name' => 'Fixture researcher', 'prompt' => 'You research carefully and cite your sources.', 'tools' => ['Fetch::fetch'], 'visibility' => 'organization']);
        Agent\Personas::save($browserAdmin['id'], ['name' => 'AAA unavailable persona', 'prompt' => 'Requires a tool unavailable in web chat.', 'tools' => ['Source'], 'visibility' => 'organization']);
        putenv('ONBOARDING_URL=' . $base); putenv('ONBOARDING_ENGINE=http://' . $engine);
        putenv('ONBOARDING_PASSWORD=' . $credentials['password']);
        $launch([PHP_BINARY, dirname(__DIR__) . '/bin/phill-worker']);
        $browser = $launch(['python3', __DIR__ . '/ui_onboarding.py']);
        $exit = proc_close($browser); array_pop($processes);
        if ($exit !== 0) { rewind($log); fwrite(STDERR, stream_get_contents($log)); }
        check($exit === 0, 'Browser repeats standalone setup, help animation, streaming follow-ups, mobile and no-JS login');
    }
} finally {
    foreach (array_reverse($processes) as $process) { proc_terminate($process); proc_close($process); }
    foreach ($cookies as $cookie) fclose($cookie);
    $db->exec('SET search_path TO public'); $db->exec('DROP SCHEMA IF EXISTS ' . $schema . ' CASCADE');
    if ($oldOptions === false) putenv('PGOPTIONS'); else putenv('PGOPTIONS=' . $oldOptions);
}

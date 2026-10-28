<?php
require __DIR__ . '/helpers.php';

$cookie = tmpfile();
$cookiePath = stream_get_meta_data($cookie)['uri'];
$request = function ($path, $method = 'GET', $body = null, $csrf = '') use ($cookiePath) {
    $curl = curl_init('http://127.0.0.1:8080' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookiePath, CURLOPT_COOKIEJAR => $cookiePath, CURLOPT_TIMEOUT => 15, CURLOPT_CUSTOMREQUEST => $method]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]);
    }
    $text = curl_exec($curl);
    if ($text === false) throw new RuntimeException(curl_error($curl));
    return ['status' => curl_getinfo($curl, CURLINFO_HTTP_CODE), 'json' => json_decode($text, true)];
};
$csrf = $request('/api/state')['json']['csrf'];
$login = $request('/api/login', 'POST', ['username' => 'alice', 'password' => 'phillip-demo'], $csrf);
check($login['status'] === 200, 'Frontend tightening fixture signs in');
$fixtureTitle = 'Rejected frontend fixture ' . bin2hex(random_bytes(8));
foreach (['PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
    $response = $request('/api/threads', $method, ['title' => $fixtureTitle]);
    check($response['status'] === 403 && $response['json']['error'] === 'Method not allowed' && !isset($response['json']['thread']), $method . ' cannot create a thread outside the CSRF-protected POST path');
}
check($request('/api/threads', 'POST', ['title' => $fixtureTitle], 'invalid')['status'] === 403, 'Thread creation still requires a valid POST CSRF token');
$q = Agent\Database::connection()->prepare('SELECT count(*) FROM threads WHERE title = ?'); $q->execute([$fixtureTitle]);
check((int)$q->fetchColumn() === 0, 'Rejected requests do not create their uniquely named fixture');

$alice = $login['json']['user'];
$thread = Agent\Thread::create($alice['id'], 'Author parity fixture', ['manual' => true]);
try {
    $run = $thread->enqueue($alice['id'], 'Author name fixture');
    $thread->append($run['id'], ['role' => 'assistant', 'content' => 'Assistant name fixture']);
    $api = $request('/api/threads/' . $thread->id);
    $rendered = Agent\Views::thread(['user' => $alice], $thread->id);
    check($api['status'] === 200 && $api['json']['messages'] === $rendered['messages'], 'API and PHP fragments expose identical decorated messages');
    check($rendered['messages'][1]['author_name'] === $alice['display_name'] && $rendered['messages'][2]['author_name'] === 'PHiLLiP', 'Shared author decoration preserves user and assistant labels');
    $missing = Agent\Views::messageAuthors([['author_id' => 'nonexistent-author-fixture']]);
    check($missing[0]['author_name'] === 'Former user', 'Missing authors retain the former-user label');
} finally {
    $thread->delete();
}

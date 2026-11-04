<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';
$web = new WebFixture();
try {
    $admin = Agent\Installation::install(['auth'=>'standalone'],'admin','phillip-demo','Administrator');
    $profile = Agent\Profiles::save('default', ['url' => 'http://127.0.0.1:1/v1/chat/completions', 'model' => 'fixture', 'stream' => true]);
    Agent\Database::execute('UPDATE profiles SET enabled=TRUE, supports_chat=TRUE WHERE id=?', [$profile['id']]);
    $people = ['admin'=>$admin];
    foreach (['alice','bob','carol','visitor'] as $name) $people[$name] = Agent\Identity::createUser($name,'phillip-demo',ucfirst($name));
    $web->server(dirname(__DIR__).'/public/index.php', true);
    foreach (['admin','alice'] as $name) $web->login($name,'phillip-demo',$name);
    $fixtureTitle = 'Rejected frontend fixture ' . bin2hex(random_bytes(8));
    $raw = function ($path, $method, $body = null) use ($web) {
        $curl = curl_init($web->base . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_COOKIEFILE => $web->cookieFile('admin'),
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $text = curl_exec($curl);
        if ($text === false) throw new RuntimeException(curl_error($curl));
        return ['status' => curl_getinfo($curl, CURLINFO_HTTP_CODE), 'json' => json_decode($text, true)];
    };
    foreach (['PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
        $response = $raw('/api/threads', $method, ['title' => $fixtureTitle]);
        check($response['status'] === 403 && $response['json']['error'] === 'Method not allowed' && !isset($response['json']['thread']), $method . ' cannot create a thread outside the CSRF-protected POST path');
    }
    check($web->request('/api/threads', ['title' => $fixtureTitle], 'alice', false, 'invalid')['status'] === 403, 'Thread creation still requires a valid POST CSRF token');
    $q = Agent\Database::connection()->prepare('SELECT count(*) FROM threads WHERE title = ?'); $q->execute([$fixtureTitle]);
    check((int)$q->fetchColumn() === 0, 'Rejected requests do not create their uniquely named fixture');

    $alice = $people['alice'];
    $thread = Agent\Thread::create($alice['id'], 'Author parity fixture', ['manual' => true]);
    try {
        $run = $thread->enqueue($alice['id'], 'Author name fixture');
        $thread->append($run['id'], ['role' => 'assistant', 'content' => 'Assistant name fixture']);
        $api = $web->request('/api/threads/' . $thread->id, null, 'alice');
        $rendered = Agent\Views::thread(['user' => $alice], $thread->id);
        check($api['status'] === 200 && $api['json']['messages'] === $rendered['messages'], 'API and PHP fragments expose identical decorated messages');
        check($rendered['messages'][1]['author_name'] === $alice['display_name'] && $rendered['messages'][2]['author_name'] === 'PHiLLiP', 'Shared author decoration preserves user and assistant labels');
        $missing = Agent\Views::messageAuthors([['author_id' => 'nonexistent-author-fixture']]);
        check($missing[0]['author_name'] === 'Former user', 'Missing authors retain the former-user label');
    } finally {
        $thread->delete();
    }
} finally { $web->close(); }

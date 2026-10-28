<?php
require __DIR__ . '/helpers.php';
class HttpSession
{
    private $cookie;
    private $path;
    public $csrf;
    public function __construct($username)
    {
        $this->cookie = tmpfile(); $this->path = stream_get_meta_data($this->cookie)['uri'];
        $this->csrf = $this->call('/api/state')['json']['csrf'];
        $response = $this->call('/api/login', ['username' => $username, 'password' => 'phillip-demo']);
        check($response['status'] === 200 && $response['json']['user']['username'] === $username, 'HTTP login: ' . $username);
        $this->csrf = $response['json']['csrf'];
    }
    public function call($path, $body = null, $csrf = true)
    {
        $curl = curl_init('http://127.0.0.1:8080' . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $this->path, CURLOPT_COOKIEJAR => $this->path, CURLOPT_TIMEOUT => 180]);
        if ($body !== null) {
            if (substr($path, -9) === '/messages' && !isset($body['submission_key'])) $body['submission_key'] = bin2hex(random_bytes(16));
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-CSRF-Token: ' . ($csrf ? $this->csrf : 'invalid')]);
        }
        $text = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if (is_resource($curl)) curl_close($curl);
        return ['status' => $status, 'json' => json_decode($text, true), 'body' => $text];
    }
}
$admin = new HttpSession('admin'); $alice = new HttpSession('alice'); $bob = new HttpSession('bob');
$profileName = 'fixture-http-' . bin2hex(random_bytes(3));
$threadId = null;
try {
    check($alice->call('/api/admin/registry')['status'] === 403, 'Registry administration rejects ordinary users');
    $result = $admin->call('/api/admin/profile', ['name' => $profileName, 'config' => Agent\Config::profile('default'), 'enabled' => true]);
    check($result['status'] === 200 && $result['json']['profile']['enabled'], 'Admin Save tests and enables a profile over HTTP');
    $created = $alice->call('/api/threads', ['title' => 'HTTP privacy fixture', 'settings' => ['profile' => $profileName, 'manual' => true, 'tools' => [], 'parameters' => []]]);
    check($created['status'] === 200, 'User creates a private thread through HTTP');
    $threadId = $created['json']['thread']['id'];
    check($bob->call('/api/threads/' . $threadId)['status'] === 403, 'Another user cannot read private HTTP thread');
    check($admin->call('/api/threads/' . $threadId)['status'] === 403, 'Administrator cannot read another users private thread');
    check($alice->call('/api/threads/' . $threadId . '/share', ['visibility' => 'team', 'team_id' => 'engineering'])['status'] === 200, 'Owner shares with exactly one team');
    check($bob->call('/api/threads/' . $threadId)['status'] === 200, 'Team member reads shared thread');
    check($bob->call('/api/threads/' . $threadId . '/settings', ['settings' => ['profile' => $profileName]])['status'] === 403, 'Participant cannot change owner chat settings');
    $alice->call('/api/threads/' . $threadId . '/messages', ['content' => 'Alice first']);
    $bob->call('/api/threads/' . $threadId . '/messages', ['content' => 'Bob next']);
    $messages = $alice->call('/api/threads/' . $threadId)['json']['messages'];
    check($alice->call('/api/messages/' . $messages[2]['id'] . '/edit', ['content' => 'Forbidden'])['status'] === 403, 'Owner cannot edit a participants message through HTTP');
    check($alice->call('/api/messages/' . $messages[1]['id'] . '/edit', ['content' => 'Alice corrected'])['status'] === 200, 'Message author can replace their own history');
    check(count($alice->call('/api/threads/' . $threadId)['json']['messages']) === 2, 'Fork replacement removes later participants message');
    check($alice->call('/api/threads/' . $threadId . '/share', ['visibility' => 'organization'], false)['status'] === 403, 'CSRF protection enforced');
} finally {
    if ($threadId) (new Agent\Thread($threadId))->delete();
    Agent\Database::connection()->prepare('DELETE FROM profiles WHERE name = ?')->execute([$profileName]);
}

<?php
require __DIR__ . '/helpers.php';

class SettingsContractSession
{
    private $cookie;
    private $path;
    private $csrf;

    public function __construct($username)
    {
        $this->cookie = tmpfile(); $this->path = stream_get_meta_data($this->cookie)['uri'];
        $state = $this->call('/api/state'); $this->csrf = $state['json']['csrf'];
        $login = $this->call('/api/login', ['username' => $username, 'password' => 'phillip-demo']);
        check($login['status'] === 200, 'Settings contract HTTP login: ' . $username);
        $this->csrf = $login['json']['csrf'];
    }

    public function call($path, $body = null)
    {
        $curl = curl_init('http://127.0.0.1:8080' . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $this->path, CURLOPT_COOKIEJAR => $this->path, CURLOPT_TIMEOUT => 15]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-CSRF-Token: ' . $this->csrf]);
        }
        $text = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if (is_resource($curl)) curl_close($curl);
        return ['status' => $status, 'json' => json_decode($text, true)];
    }
}

$db = Agent\Database::connection();
$name = 'settings-contract-' . bin2hex(random_bytes(5));
$config = ['url' => 'http://127.0.0.1:65431/v1/chat/completions', 'model' => 'settings-contract-fixture'];
$keys = ['services.searxng', 'services.camoufox', 'attachment_parsers'];
$original = [];
$permissionUser = null;
foreach ($keys as $key) { $q = $db->prepare('SELECT value FROM settings WHERE key = ?'); $q->execute([$key]); $original[$key] = $q->fetchColumn(); }
try {
    $admin = new SettingsContractSession('admin');
    $alice = new SettingsContractSession('alice');
    $saved = $admin->call('/api/admin/profile', ['name' => $name, 'config' => json_encode($config), 'overrides' => '{}', 'enabled' => 'false']);
    check($saved['status'] === 200 && !$saved['json']['profile']['enabled'] && Agent\Config::profile($saved['json']['profile']) === $config, 'HTTP profile save accepts untouched config/override textarea JSON');
    $invalid = $admin->call('/api/admin/profile', ['name' => $name, 'config' => json_encode($config + ['temperature' => 0.7]), 'overrides' => '{"supports_chat":"yes"}']);
    check($invalid['status'] === 400 && Agent\Config::profile($name) === $config, 'Invalid raw capability overrides reject the profile without updates');

    $permissionUser = Agent\Identity::createUser(['username'=>'settings-permission-' . bin2hex(random_bytes(5)), 'password'=>'contract-password']);
    $permission = ['scope_type'=>'user','scope_id'=>$permissionUser['id'],'tool'=>'Agent\\Tools\\Fetch','allowed'=>'false'];
    $result = $admin->call('/api/admin/permission', $permission);
    check($result['status'] === 200 && !Agent\Access::tool($permissionUser['id'], $permission['tool']), 'HTTP permission contracts accept form-style false without granting access');
    $permission['allowed'] = 'maybe';
    check($admin->call('/api/admin/permission', $permission)['status'] === 400 && !Agent\Access::tool($permissionUser['id'], $permission['tool']), 'Ambiguous HTTP permission values reject without changing the denial');
    $invalid = $admin->call('/api/admin/profile', ['name' => $name, 'config' => json_encode($config + ['temperature' => 0.7]), 'enabled' => 'perhaps']);
    check($invalid['status'] === 400 && Agent\Config::profile($name) === $config, 'Ambiguous enabled values reject the HTTP profile before updates');

    $services = ['searxng' => Agent\Config::service('searxng'), 'camoufox' => Agent\Config::service('camoufox')];
    $result = $admin->call('/api/admin/services', ['services' => json_encode($services)]);
    check($result['status'] === 200 && Agent\Config::service('searxng') === $services['searxng'], 'HTTP service save accepts untouched textarea JSON');
    $result = $admin->call('/api/admin/services', ['services' => '{"searxng":{"url":"http://must-not-save"},"camoufox":{"url":"bad"}}']);
    check($result['status'] === 400 && Agent\Config::service('searxng') === $services['searxng'], 'Malformed HTTP service maps reject before partial updates');
    check($admin->call('/api/admin/services', ['services' => '[]'])['status'] === 400, 'Service JSON list is rejected as a non-map');

    $parsers = ['text/plain' => $name, '.txt' => $name];
    $result = $admin->call('/api/admin/attachment-parsers', ['parsers' => json_encode($parsers)]);
    check($result['status'] === 200 && Agent\AttachmentParser::configuration() == $parsers, 'HTTP parser save accepts untouched textarea JSON');
    check($admin->call('/api/admin/attachment-parsers', ['parsers' => '{".pdf":"nonexistent-contract-profile"}'])['status'] !== 200 && Agent\AttachmentParser::configuration() == $parsers, 'Invalid parser profile leaves the previous configuration unchanged');
    check($admin->call('/api/admin/attachment-parsers', ['parsers' => '[]'])['status'] === 400, 'Parser JSON list is rejected as a non-map');
    foreach (['/api/admin/profile' => ['name' => $name, 'config' => json_encode($config)], '/api/admin/services' => ['services' => json_encode($services)], '/api/admin/attachment-parsers' => ['parsers' => json_encode($parsers)]] as $path => $body) {
        check($alice->call($path, $body)['status'] === 403, 'Shared contracts do not bypass HTTP administrator authorization: ' . $path);
    }
} finally {
    if ($permissionUser) {
        Agent\Mappings::delete('permissions', ['scope_type'=>'user','scope_id'=>$permissionUser['id']]);
        Agent\Mappings::delete('users', ['id'=>$permissionUser['id']]);
    }
    foreach ($original as $key => $value) {
        if ($value === false) $db->prepare('DELETE FROM settings WHERE key = ?')->execute([$key]);
        else $db->prepare('INSERT INTO settings (key, value) VALUES (?, ?::jsonb) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value')->execute([$key, $value]);
    }
    $db->prepare('DELETE FROM profiles WHERE name = ?')->execute([$name]);
}

<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';
$web = new WebFixture();
try {
    $admin = Agent\Installation::install(['auth'=>'standalone'],'admin','phillip-demo','Administrator');
    $people = ['admin'=>$admin];
    foreach (['alice','bob','carol','visitor'] as $name) $people[$name] = Agent\Identity::createUser($name,'phillip-demo',ucfirst($name));
    $web->server(dirname(__DIR__).'/public/index.php', true);
    $web->login('admin','phillip-demo','admin');
    $web->login('alice','phillip-demo','alice');

    $db = Agent\Database::connection();
    $name = 'settings-contract-' . bin2hex(random_bytes(5));
    $config = ['url' => 'http://127.0.0.1:65431/v1/chat/completions', 'model' => 'settings-contract-fixture'];
    $keys = ['services.searxng', 'services.camoufox', 'attachment_parsers'];
    $original = [];
    $permissionUser = null;
    foreach ($keys as $key) { $q = $db->prepare('SELECT value FROM settings WHERE key = ?'); $q->execute([$key]); $original[$key] = $q->fetchColumn(); }
    try {
        $saved = $web->request('/api/admin/profile', ['name' => $name, 'config' => json_encode($config), 'overrides' => '{}', 'enabled' => 'false'], 'admin');
        check($saved['status'] === 200 && !$saved['json']['profile']['enabled'] && Agent\Config::profile($saved['json']['profile']) === $config, 'HTTP profile save accepts untouched config/override textarea JSON');
        $invalid = $web->request('/api/admin/profile', ['name' => $name, 'config' => json_encode($config + ['temperature' => 0.7]), 'overrides' => '{\"supports_chat\":\"yes\"}'], 'admin');
        check($invalid['status'] === 400 && Agent\Config::profile($name) === $config, 'Invalid raw capability overrides reject the profile without updates');

        $permissionUser = Agent\Identity::createUser(['username'=>'settings-permission-' . bin2hex(random_bytes(5)), 'password'=>'contract-password']);
        $permission = ['scope_type'=>'user','scope_id'=>(string)$permissionUser['id'],'tool'=>\Agent\Tools\Tasks::class,'allowed'=>'false'];
        $result = $web->request('/api/admin/permission', $permission, 'admin');
        check($result['status'] === 200 && !Agent\Access::tool($permissionUser['id'], $permission['tool']), 'HTTP permission contracts accept form-style false without granting access');
        $permission['allowed'] = 'maybe';
        check($web->request('/api/admin/permission', $permission, 'admin')['status'] === 400 && !Agent\Access::tool($permissionUser['id'], $permission['tool']), 'Ambiguous HTTP permission values reject without changing the denial');
        $invalid = $web->request('/api/admin/profile', ['name' => $name, 'config' => json_encode($config + ['temperature' => 0.7]), 'enabled' => 'perhaps'], 'admin');
        check($invalid['status'] === 400 && Agent\Config::profile($name) === $config, 'Ambiguous enabled values reject the HTTP profile before updates');

        $services = ['searxng' => Agent\Config::service('searxng'), 'camoufox' => Agent\Config::service('camoufox')];
        $result = $web->request('/api/admin/services', ['services' => json_encode($services)], 'admin');
        check($result['status'] === 200 && Agent\Config::service('searxng') === $services['searxng'], 'HTTP service save accepts untouched textarea JSON');
        $result = $web->request('/api/admin/services', ['services' => '{\"searxng\":{\"url\":\"http://must-not-save\"},\"camoufox\":{\"url\":\"bad\"}}'], 'admin');
        check($result['status'] === 400 && Agent\Config::service('searxng') === $services['searxng'], 'Malformed HTTP service maps reject before partial updates');
        check($web->request('/api/admin/services', ['services' => '[]'], 'admin')['status'] === 400, 'Service JSON list is rejected as a non-map');

        $parsers = ['text/plain' => $name, '.txt' => $name];
        $result = $web->request('/api/admin/attachment-parsers', ['parsers' => json_encode($parsers)], 'admin');
        check($result['status'] === 200 && Agent\AttachmentParser::configuration() == $parsers, 'HTTP parser save accepts untouched textarea JSON');
        check($web->request('/api/admin/attachment-parsers', ['parsers' => '{\".pdf\":\"nonexistent-contract-profile\"}'], 'admin')['status'] !== 200 && Agent\AttachmentParser::configuration() == $parsers, 'Invalid parser profile leaves the previous configuration unchanged');
        check($web->request('/api/admin/attachment-parsers', ['parsers' => '[]'], 'admin')['status'] === 400, 'Parser JSON list is rejected as a non-map');
        foreach (['/api/admin/profile' => ['name' => $name, 'config' => json_encode($config)], '/api/admin/services' => ['services' => json_encode($services)], '/api/admin/attachment-parsers' => ['parsers' => json_encode($parsers)]] as $path => $body) {
            check($web->request($path, $body, 'alice')['status'] === 403, 'Shared contracts do not bypass HTTP administrator authorization: ' . $path);
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
} finally { $web->close(); }

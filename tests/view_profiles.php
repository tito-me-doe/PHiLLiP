<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';
$web = new WebFixture();
try {
    $admin = Agent\Installation::install(['auth'=>'standalone'],'admin','phillip-demo','Administrator');
    $people = ['admin'=>$admin];
    foreach (['alice','bob','carol','visitor'] as $name) $people[$name] = Agent\Identity::createUser($name,'phillip-demo',ucfirst($name));
    $web->server(dirname(__DIR__).'/public/index.php', true);
    $web->login('alice','phillip-demo','alice');
    $names = []; $db = Agent\Database::connection();
    $extractMessages = function ($response) {
        foreach ($response['headers'] as $line) {
            if (stripos($line, 'X-PHiLLiP-Messages:') === 0) {
                return json_decode(base64_decode(trim(substr($line, strlen('X-PHiLLiP-Messages:')))), true);
            }
        }
        return [];
    };
    try {
        $parent = 'view-profile-parent-' . bin2hex(random_bytes(5));
        $child = $parent . '-child'; $names = [$parent, $child];
        $dummy = 'view-profile-dummy-' . bin2hex(random_bytes(5)); $names[] = $dummy;
        Agent\Profiles::save($parent, ['url' => 'http://127.0.0.1:1/v1/chat/completions', 'model' => 'inherited-fixture-model', 'headers' => ['Authorization' => 'Bearer VIEW_PROFILE_PRIVATE']]);
        Agent\Profiles::save($child, ['preset' => $parent]);
        Agent\Profiles::save($dummy, ['url' => 'http://127.0.0.1:1/v1/chat/completions', 'model' => 'dummy-model']);
        foreach ($names as $name) $db->prepare('UPDATE profiles SET enabled = TRUE, supports_chat = TRUE WHERE name = ?')->execute([$name]);
        $response = $web->request('/api/state', null, 'alice');
        $profiles = array_column($response['json']['profiles'], null, 'name');
        check($response['status'] === 200 && $profiles[$child]['model'] === 'inherited-fixture-model', 'Preset-only enabled profiles display the resolved model rather than raising PHP warnings');
        $msgs = $extractMessages($response);
        check($msgs === [] && strpos(json_encode($response['json']), 'VIEW_PROFILE_PRIVATE') === false, 'Profile rendering emits no diagnostics or private configuration');
        $db->prepare('DELETE FROM profiles WHERE name = ?')->execute([$parent]);
        $response = $web->request('/api/state', null, 'alice');
        $profiles = array_column($response['json']['profiles'] ?? [], null, 'name');
        check($response['status'] === 200 && !in_array($child, array_column($response['json']['profiles'] ?? [], 'name'), true) && $response['json']['profiles'], 'A missing preset parent degrades one profile without breaking the frontend or other models');
        $msgs = $extractMessages($response);
        check(strpos(json_encode($msgs), $child) !== false, 'Unavailable profiles provide an actionable administrator-configuration warning');
        $db->prepare("UPDATE profiles SET config = '{}'::jsonb WHERE name = ?")->execute([$child]);
        $response = $web->request('/api/state', null, 'alice');
        check($response['status'] === 200 && !in_array($child, array_column($response['json']['profiles'] ?? [], 'name'), true), 'Malformed enabled profile metadata cannot create undefined-model engine diagnostics');
    } finally {
        foreach ($names as $name) $db->prepare('DELETE FROM profiles WHERE name = ?')->execute([$name]);
    }
} finally { $web->close(); }

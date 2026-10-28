<?php
require __DIR__ . '/helpers.php';
$cookie = tmpfile(); $cookiePath = stream_get_meta_data($cookie)['uri'];
$request = function ($path, $body = null, $csrf = '') use ($cookiePath) {
    $headers = [];
    $curl = curl_init('http://127.0.0.1:8080' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookiePath, CURLOPT_COOKIEJAR => $cookiePath, CURLOPT_TIMEOUT => 15,
        CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$headers) {
            if (stripos($line, 'X-PHiLLiP-Messages:') === 0) $headers = json_decode(base64_decode(trim(substr($line, strlen('X-PHiLLiP-Messages:')))), true);
            return strlen($line);
        }]);
    if ($body !== null) curl_setopt_array($curl, [CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]]);
    $text = curl_exec($curl);
    if ($text === false) throw new RuntimeException(curl_error($curl));
    return ['status' => curl_getinfo($curl, CURLINFO_HTTP_CODE), 'json' => json_decode($text, true), 'messages' => $headers];
};
$names = []; $db = Agent\Database::connection();
try {
    $csrf = $request('/api/state')['json']['csrf'];
    $login = $request('/api/login', ['username' => 'alice', 'password' => 'phillip-demo'], $csrf);
    check($login['status'] === 200, 'Profile rendering fixture signs in through the shared frontend');
    $parent = 'view-profile-parent-' . bin2hex(random_bytes(5));
    $child = $parent . '-child'; $names = [$parent, $child];
    Agent\Profiles::save($parent, ['url' => 'http://127.0.0.1:1/v1/chat/completions', 'model' => 'inherited-fixture-model', 'headers' => ['Authorization' => 'Bearer VIEW_PROFILE_PRIVATE']]);
    Agent\Profiles::save($child, ['preset' => $parent]);
    // Simulate previously verified profiles; rendering must never perform inference.
    foreach ($names as $name) $db->prepare('UPDATE profiles SET enabled = TRUE, supports_chat = TRUE WHERE name = ?')->execute([$name]);
    $response = $request('/api/state');
    $profiles = array_column($response['json']['profiles'], null, 'name');
    check($response['status'] === 200 && $profiles[$child]['model'] === 'inherited-fixture-model', 'Preset-only enabled profiles display the resolved model rather than raising PHP warnings');
    check($response['messages'] === [] && strpos(json_encode($response['json']), 'VIEW_PROFILE_PRIVATE') === false, 'Profile rendering emits no diagnostics or private configuration');
    $db->prepare('DELETE FROM profiles WHERE name = ?')->execute([$parent]);
    $response = $request('/api/state');
    check($response['status'] === 200 && !in_array($child, array_column($response['json']['profiles'], 'name'), true) && $response['json']['profiles'], 'A missing preset parent degrades one profile without breaking the frontend or other models');
    check(strpos(json_encode($response['messages']), $child) !== false, 'Unavailable profiles provide an actionable administrator-configuration warning');
    $db->prepare("UPDATE profiles SET config = '{}'::jsonb WHERE name = ?")->execute([$child]);
    $response = $request('/api/state');
    check($response['status'] === 200 && !in_array($child, array_column($response['json']['profiles'], 'name'), true), 'Malformed enabled profile metadata cannot create undefined-model engine diagnostics');
} finally {
    foreach ($names as $name) $db->prepare('DELETE FROM profiles WHERE name = ?')->execute([$name]);
    fclose($cookie);
}

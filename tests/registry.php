<?php
require __DIR__ . '/helpers.php';
$db = Agent\Database::connection();
$db->beginTransaction();
try {
    $registry = new Agent\Registry($db);
    $endpoint = ['url' => 'http://127.0.0.1:65432', 'engine' => 'fixture', 'status' => 'online', 'metadata' => ['version' => 'test']];
    $model = ['model' => 'test-model', 'status' => 'loaded', 'supports_vision' => null, 'supports_tools' => true, 'metadata' => ['context' => 8192]];
    $registry->record($endpoint, [$model]);
    $registry->record($endpoint, [$model]);
    $name = Agent\Registry::profileName($endpoint['url'], $model['model']);
    $profile = $registry->profile($name);
    check($profile && !$profile['enabled'], 'Discovered profile disabled by default');
    check($profile['supports_tools'] === true && $profile['supports_vision'] === null, 'Capabilities use nullable boolean columns');
    check(Agent\Config::profile($name)['model'] === 'test-model', 'Disabled profiles usable from PHP');
    check(!array_filter($registry->profiles(true), function ($row) use ($name) { return $row['name'] === $name; }), 'Disabled profile hidden from user selection');
    $registry->unavailable($endpoint['url']);
    $q = $db->prepare('SELECT status FROM endpoints WHERE url = ?');
    $q->execute([$endpoint['url']]);
    check($q->fetchColumn() === 'offline', 'Endpoint can become unavailable');
    putenv('PROFILE_JSON=' . __DIR__ . '/fixtures/profiles.json');
    check(Agent\Config::profile('json-test')['model'] === 'json-fallback', 'JSON fallback for missing database profile');
    expectException(function () { Agent\Config::profile('missing-profile'); }, 'Run the onboarding');
} finally { $db->rollBack(); }

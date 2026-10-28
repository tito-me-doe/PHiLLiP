<?php
require __DIR__ . '/helpers.php';
$name = 'fixture-verified-' . bin2hex(random_bytes(3));
try {
    $profile = Agent\Profiles::save($name, Agent\Config::profile('default'), true);
    check($profile['enabled'] === true && $profile['tested_at'] !== null, 'Profile enabled only after configuration verification');
    check($profile['supports_chat'] === true, 'Actual chat capability stored as boolean');
    check($profile['supports_streaming'] === true && $profile['supports_tools'] === true, 'Streaming and tools explicitly tested');
    check($profile['supports_vision'] === false, 'Text-only local model does not claim vision');
    $metadata = json_decode($profile['metadata'], true);
    check(isset($metadata['tests']['supports_chat']['seconds']), 'Probe evidence stored in metadata JSON');
} finally { Agent\Database::connection()->prepare('DELETE FROM profiles WHERE name = ?')->execute([$name]); }

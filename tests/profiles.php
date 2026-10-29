<?php
require __DIR__ . '/helpers.php';
$name = 'fixture-verified-' . bin2hex(random_bytes(3));
try {
    $profile = Agent\Profiles::save($name, Agent\Config::profile('default'), true);
    check($profile['enabled'] === true && $profile['tested_at'] !== null, 'Profile enabled only after configuration verification');
    check($profile['supports_chat'] === true, 'Actual chat capability stored as boolean');
    check($profile['supports_streaming'] === true && $profile['supports_tools'] === true, 'Streaming and tools explicitly tested');
    $metadata = json_decode($profile['metadata'], true);
    check(is_bool($profile['supports_vision']) && isset($metadata['tests']['supports_vision']['ok']) && $profile['supports_vision'] === $metadata['tests']['supports_vision']['ok'], 'Vision capability matches actual probe evidence, whether the configured model is text-only or multimodal');
    check(isset($metadata['tests']['supports_chat']['seconds']), 'Probe evidence stored in metadata JSON');
} finally { Agent\Database::connection()->prepare('DELETE FROM profiles WHERE name = ?')->execute([$name]); }

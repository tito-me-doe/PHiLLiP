<?php
use Agent\Views as V;

$user = $state['user'];
Agent\Pages::authorizeSection($user['id'], 'models', 'profiles');

$p = !empty($_GET['name']) ? (new Agent\Registry())->profile($_GET['name']) : null;
$config = $p
    ? Agent\Config::profile($p)
    : [
        'url' => 'http://127.0.0.1:8000/v1/chat/completions',
        'model' => '',
        'temperature' => 0.65,
        'stream' => true
    ];
$metadata = $p ? json_decode($p['metadata'], true) : [];
?>
<form method="post" action="<?= Agent\Pages::link('models') ?>" id="profile-form" class="form-stack"><label>Profile name<input name="name" value="<?= V::h(
    $p ? $p['name'] : ''
) ?>" <?= $p
    ? 'readonly'
    : '' ?> required></label><label>Endpoint, model & request parameters<textarea name="config" class="json-editor" rows="12"><?= V::pretty(
     $config
  ) ?></textarea></label><label class="check-label"><input name="enabled" type="checkbox" <?= $p && $p['enabled']
    ? 'checked'
    : '' ?>>Enable for users (Save runs capability tests)</label><details class="activity"><summary>Advanced capability overrides</summary><label>Boolean capability overrides<textarea name="overrides" class="json-editor"><?= V::pretty(
     isset($metadata['overrides']) ? $metadata['overrides'] : (object) []
) ?></textarea></label></details><?php if ($p): ?><details class="activity"><summary>Metadata & test results</summary><pre><?= V::pretty(
     $metadata
) ?></pre></details><?php endif; ?><div class="form-error"></div><?= V::csrf($state, 'save-profile') ?><button class="primary">Save profile</button></form>

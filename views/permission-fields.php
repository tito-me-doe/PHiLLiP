<?php
use Agent\Views as V;
$selected = ['user'=>[], 'team'=>[]];
foreach ($management['permissions'] as $grant) {
    if ($grant['tool'] === $resource && isset($selected[$grant['scope_type']])) $selected[$grant['scope_type']][] = (string)$grant['scope_id'];
}
?>
<input type="hidden" name="allowed_users_present" value="true"><input type="hidden" name="allowed_groups_present" value="true">
<fieldset><legend>Users</legend>
<?php foreach ($management['users'] as $person): ?>
    <label class="check-label"><input name="allowed_users[]" type="checkbox" value="<?= V::h($person['id']) ?>" <?= in_array((string)$person['id'],$selected['user'],true) ? 'checked' : '' ?>><?= V::h($person['display_name'].' ('.$person['username'].')') ?></label>
<?php endforeach; ?></fieldset>
<fieldset><legend>Groups</legend>
<?php foreach ($management['groups'] as $group): if ((string)$group['id'] === '1') continue; ?>
    <label class="check-label"><input name="allowed_groups[]" type="checkbox" value="<?= V::h($group['id']) ?>" <?= in_array((string)$group['id'],$selected['team'],true) ? 'checked' : '' ?>><?= V::h($group['name']) ?></label>
<?php endforeach; ?></fieldset>
<p class="hint">Administrators always have access. Select no one to keep it to administrators.</p>

<?php
use Agent\Views as V;
Agent\Pages::authorize($state['user']['id'],'models');
$profile = (new Agent\Registry())->profile(['id'=>$_GET['id'] ?? $_GET['defaults'] ?? '']);
if (!$profile) throw new InvalidArgumentException('Profile not found');
$assignments = Agent\Database::rows('SELECT d.*,p.name FROM profile_defaults d LEFT JOIN profiles p ON p.id=d.profileid ORDER BY d.default_for');
?>
<form method="post" action="<?= Agent\Pages::link('models') ?>" class="form-stack" data-profile-defaults>
<?= V::csrf($state,'profile-defaults') ?><input type="hidden" name="profileid" value="<?= (int)$profile['id'] ?>">
<p>Default tasks for <strong><?= V::h($profile['name']) ?></strong></p>
<fieldset><legend>Default for</legend>
<?php foreach ($assignments as $assignment): ?>
<label class="check-label"><input type="checkbox" name="tasks[]" value="<?= V::h($assignment['default_for']) ?>" <?= $assignment['profileid']===$profile['id'] ? 'checked' : '' ?>><?= V::h(Agent\Profiles::TASK_LABELS[$assignment['default_for']]) ?><?php if ($assignment['name'] && $assignment['profileid']!==$profile['id']): ?><small>Currently <?= V::h($assignment['name']) ?></small><?php endif; ?></label>
<?php endforeach; ?></fieldset>
<p class="hint">Choosing a task replaces its current profile.</p>
<div class="form-error"></div><button class="primary">Save defaults</button>
</form>

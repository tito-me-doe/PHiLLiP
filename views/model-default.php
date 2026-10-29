<?php
use Agent\Views as V;
Agent\Pages::authorize($state['user']['id'], 'models');
$task = $_GET['task'] ?? '';
if (!in_array($task, Agent\Profiles::DEFAULT_TASKS, true)) throw new InvalidArgumentException('Unknown task default');
$default = Agent\Database::row('SELECT profileid FROM profile_defaults WHERE default_for=?',[$task]);
$options = [''=>'Current model (fallback)'];
foreach ((new Agent\Registry())->profiles() as $profile) $options[$profile['id']] = $profile['name'];
?>
<form method="post" action="<?= Agent\Pages::link('models') ?>" class="form-stack">
<?= V::csrf($state,'benchmark-default') ?>
<input type="hidden" name="return_to" value="models"><input type="hidden" name="default_for" value="<?= V::h($task) ?>">
<?= V::select('profileid',$task,$options,$default['profileid'] ?? '') ?>
<button class="primary">Save default</button>
</form>

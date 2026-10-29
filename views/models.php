<?php
use Agent\Views as V;
Agent\Pages::authorize($state['user']['id'], 'models');
$registry = new Agent\Registry();
$profiles = $registry->profiles();
$models = $registry->models();
$endpoints = $registry->endpoints();
$names = array_column($profiles,'name','id');
?>
<?php if (isset($_GET['task'])): ?><section class="card"><?= V::render('model-default',['state'=>$state]) ?></section><?php endif; ?>
<section class="card"><h2>Task profile defaults</h2>
<p class="hint">Explicit assignments only; prompts never trigger model selection. Unassigned tasks retain their current model. Embeddings require a capable endpoint.</p>
<div class="table-wrap"><table class="registry"><thead><tr><th>Task</th><th>Profile</th><th></th></tr></thead><tbody>
<?php foreach (Agent\Database::rows('SELECT * FROM profile_defaults ORDER BY default_for') as $default): ?>
<tr><td><?= V::h($default['default_for']) ?></td><td><?= V::h($default['profileid'] !== null ? ($names[$default['profileid']] ?? 'Unavailable profile') : 'Current model (fallback)') ?></td>
<td><?= V::iconAction('edit','Edit default '.$default['default_for'],['data-dialog'=>'model-default','data-task'=>$default['default_for'],'href'=>'?task='.rawurlencode($default['default_for'])]) ?></td></tr>
<?php endforeach; ?></tbody></table></div></section>
<section class="card"><h2>Model discovery</h2>
<p class="hint"><?= count($endpoints) ?> endpoints · <?= count($models) ?> models · <?= count($profiles) ?> profiles. Discovery never runs inference.</p>
<form method="post" id="scan-form" class="grid">
<label>LAN CIDRs<input name="cidr" value="<?= V::h(getenv('SCAN_CIDRS')) ?>" placeholder="192.168.1.0/24"></label>
<label>Ports<input name="ports" value="<?= V::h(getenv('SCAN_PORTS')) ?>"></label>
<div class="row"><label class="check-label"><input name="all" type="checkbox">All ports</label><button class="primary">Scan network</button></div>
</form>
<?php $scan = Agent\Database::row("SELECT id FROM operations WHERE type='scan' AND status IN ('queued','running') ORDER BY id DESC LIMIT 1"); ?>
<div id="scan-status" role="status" aria-live="polite" <?= $scan ? 'data-scan-active="'.V::h($scan['id']).'"' : '' ?>></div>
</section>
<section class="card"><h2>Profiles</h2><div class="table-wrap"><table class="registry">
<thead><tr><th>Profile / model</th><th>Status</th><th>Capabilities</th><th></th></tr></thead><tbody>
<?php foreach ($profiles as $profile): $config = Agent\Config::profile($profile); ?>
<tr><td><strong><?= V::h($profile['name']) ?></strong><small><?= V::h($config['model'] ?? $config['body']['model'] ?? '') ?></small><small><?= V::h($config['url'] ?? '') ?></small></td>
<td><?= $profile['enabled'] ? 'Enabled' : 'Not enabled' ?><small><?= $profile['tested_at'] ? 'Tested '.V::h($profile['tested_at']) : 'Not tested' ?></small></td>
<td><?php foreach (Agent\Registry::CAPABILITIES as $key=>$label): if ($profile[$key]): ?><span class="badge"><?= V::h($label) ?></span><?php endif; endforeach; ?></td>
<td><?= V::iconAction('edit','Edit profile '.$profile['name'],['data-dialog'=>'profile','data-name'=>$profile['name'],'data-profile-edit'=>$profile['name']]) ?></td></tr>
<?php endforeach; ?>
</tbody><tfoot><tr><td colspan="4"><?= V::iconAction('plus','New profile',['data-dialog'=>'profile']) ?></td></tr></tfoot></table></div></section>
<?php foreach (['Engines'=>$endpoints,'Models'=>$models] as $label=>$records): ?>
<section class="card"><h2><?= $label ?></h2><div class="table-wrap"><table class="registry">
<thead><tr><th><?= $label === 'Engines' ? 'Endpoint' : 'Model' ?></th><th>Status</th><th>Metadata</th></tr></thead><tbody>
<?php foreach ($records as $record): ?><tr><td><?= V::h($record['model'] ?? $record['url']) ?><small><?= V::h($record['engine'] ?? '') ?></small></td>
<td><?= V::h($record['status']) ?></td><td><details><summary>Inspect metadata</summary><pre><?= V::pretty($record['metadata']) ?></pre></details></td></tr><?php endforeach; ?>
<?php if (!$records): ?><tr><td colspan="3" class="empty">Nothing discovered yet.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php endforeach; ?>

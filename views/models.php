<?php
use Agent\Views as V;
Agent\Pages::authorize($state['user']['id'], 'models');
$registry = new Agent\Registry();
$models = $registry->models();
$profiles = $registry->profiles();
$endpoints = $registry->endpoints();
$names = array_column($profiles,'name','id');
usort($profiles,function($a,$b) { return ($b['enabled'] <=> $a['enabled']) ?: strnatcasecmp($a['name'],$b['name']); });
$assignments = [];
foreach (Agent\Database::rows('SELECT * FROM profile_defaults ORDER BY default_for') as $default) {
    if ($default['profileid'] !== null) $assignments[$default['profileid']][] = $default['default_for'];
}
$embedding = Agent\Profiles::assignment('embed');
$missing = [];
if (!array_filter($profiles,function($p) { return $p['enabled'] && $p['supports_chat']; })) $missing[] = 'Missing chat profile: enable a working chat profile so users can start conversations.';
if (!$embedding) $missing[] = 'Missing profile: Embeddings is required for vector indexing and semantic search. Assign an embedding-capable profile in Default for.';
elseif (empty($embedding['supports_embeddings'])) $missing[] = 'The Embeddings profile has not been verified for embeddings. Verify its capabilities before relying on vector indexing.';

?>
<?php if (isset($_GET['defaults'])): ?><section class="card"><?= V::render('profile-defaults',['state'=>$state]) ?></section><?php endif; ?>
<?php if (isset($_GET['profile']) && in_array($_GET['profile'], ['new','edit'], true)): ?><section class="card"><?= V::render('profile-form',['state'=>$state]) ?></section><?php endif; ?>
<?php $scan = Agent\Database::row("SELECT id FROM operations WHERE type='scan' AND status IN ('queued','running') ORDER BY id DESC LIMIT 1");
$byEndpoint = [];
foreach ($models as $model) $byEndpoint[$model['endpoint_id']][] = $model; ?>
<section class="card" id="model-registry">
<div class="card-head"><h2>Models</h2>
<form method="post" id="scan-form" class="toolbar"><input name="cidr" value="<?= V::h(getenv('SCAN_CIDRS')) ?>" placeholder="Network, e.g. 192.168.1.0/24" aria-label="Network to scan"><input name="ports" value="<?= V::h(getenv('SCAN_PORTS')) ?>" placeholder="Ports" aria-label="Ports to scan"><label class="check-label"><input name="all" type="checkbox">All ports</label><button class="primary">Scan</button></form></div>
<div id="scan-status" role="status" aria-live="polite" <?= $scan ? 'data-scan-active="'.V::h($scan['id']).'"' : '' ?>></div>
<div class="table-wrap"><table class="registry grouped">
<thead><tr><th>Server</th><th>Model</th><th>Capabilities</th><th>Status</th><th></th></tr></thead>
<?php foreach ($endpoints as $endpoint): ?>
<tbody><tr class="group"><td colspan="2"><strong><?= V::h($endpoint['url']) ?></strong> <span class="muted"><?= V::h($endpoint['engine']) ?></span></td><td></td><td><span class="badge <?= $endpoint['status'] === 'available' ? '' : 'neutral' ?>"><?= V::h(ucfirst($endpoint['status'])) ?></span></td>
<td><details class="metadata"><summary>Details</summary><pre><?= V::pretty($endpoint['metadata']) ?></pre></details></td></tr>
<?php foreach ($byEndpoint[$endpoint['id']] ?? [] as $model): ?>
<tr><td></td><td><?= V::h($model['model']) ?></td>
<td><div class="capabilities"><?php foreach (Agent\Registry::CAPABILITIES as $key=>$label): if (!empty($model[$key])): ?><span class="badge"><?= V::h($label) ?></span><?php endif; endforeach; ?></div></td>
<td><span class="badge <?= $model['status'] === 'available' ? '' : 'neutral' ?>"><?= V::h(ucfirst($model['status'])) ?></span></td>
<td><details class="metadata"><summary>Details</summary><pre><?= V::pretty($model['metadata']) ?></pre></details></td></tr>
<?php endforeach; ?>
<?php if (empty($byEndpoint[$endpoint['id']])): ?><tr><td></td><td colspan="4" class="muted">No models found</td></tr><?php endif; ?>
</tbody>
<?php endforeach; ?>
<?php if (!$endpoints): ?><tbody><tr><td colspan="5" class="empty">Nothing discovered yet. Scan your network to find model servers.</td></tr></tbody><?php endif; ?>
</table></div>
</section>
<section class="card" id="profiles"><div class="card-head"><h2>Profiles</h2><?= V::iconAction('plus','New profile',['data-dialog'=>'profile','href'=>Agent\Pages::link('models',['profile'=>'new'])]) ?></div>
<div class="table-wrap"><table class="registry">
<thead><tr><th></th><th>Profile</th><th>Capabilities</th><th>Default for</th><th></th></tr></thead><tbody>
<?php foreach ($profiles as $profile): $config = Agent\Config::profile($profile); ?>
<tr<?= $profile['enabled'] ? '' : ' class="is-off"' ?>><td class="switch-cell"><form method="post" class="switch-form" data-profile-enable="<?= V::h($profile['name']) ?>"><?= V::csrf($state, 'profile-enable') ?><input type="checkbox" role="switch" <?= $profile['enabled'] ? 'checked' : '' ?> aria-label="<?= V::h(($profile['enabled'] ? 'Switch off ' : 'Switch on ').$profile['name']) ?>"></form></td><td><strong><?= V::h($profile['name']) ?></strong><small><?= V::h($config['model'] ?? $config['body']['model'] ?? '') ?><?= $profile['tested_at'] ? '' : ' · not verified' ?></small></td>
<td><div class="capabilities"><?php foreach (Agent\Registry::CAPABILITIES as $key=>$label): if ($profile[$key]): ?><span class="badge"><?= V::h($label) ?></span><?php endif; endforeach; ?></div></td>
<td><?= V::h(implode(', ',array_map(function($task) { return Agent\Profiles::TASK_LABELS[$task]; },$assignments[$profile['id']] ?? [])) ?: '—') ?></td>
<td class="actions"><?= V::iconAction('gear','Edit defaults for '.$profile['name'],['data-dialog'=>'profile-defaults','data-id'=>$profile['id'],'href'=>Agent\Pages::link('models',['defaults'=>$profile['id']])]) ?><?= V::iconAction('edit','Edit profile '.$profile['name'],['data-dialog'=>'profile','data-name'=>$profile['name'],'data-profile-edit'=>$profile['name'],'href'=>Agent\Pages::link('models',['profile'=>'edit','name'=>$profile['name']])]) ?></td></tr>
<?php endforeach; ?>
<?php if (!$profiles): ?><tr><td colspan="5" class="empty">No profiles yet. Scan for models, then add one.</td></tr><?php endif; ?>
</tbody></table></div>
<?php foreach ($missing as $message): ?><p class="dependency-warning" role="status"><?= V::h($message) ?></p><?php endforeach; ?>
</section>
<?= V::render('parsers',['state'=>$state,'editing'=>isset($_GET['configure']) && $_GET['configure']==='parsers']) ?>
<?= V::render('services',['state'=>$state,'editing'=>isset($_GET['configure']) && $_GET['configure']==='services']) ?>

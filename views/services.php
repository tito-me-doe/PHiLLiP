<?php
use Agent\Views as V;
Agent\Pages::authorize($state['user']['id'], 'services');
$services = [];
foreach (['searxng','camoufox'] as $name) {
    try { $services[$name] = Agent\Config::service($name); }
    catch (Exception $e) { $services[$name] = ['error'=>$e->getMessage()]; }
}
?>
<?php if (!empty($editing) || isset($_GET['edit'])): ?>
<form method="post" id="services-form" class="form-stack"><label>Service configuration<textarea class="json-editor" name="services" rows="15"><?= V::pretty($services) ?></textarea></label><div class="form-error" id="services-error"></div><button class="primary">Save services</button></form>
<?php else: ?>
<section class="card"><h2>Self-hosted services</h2>
<p class="hint">Database settings override local JSON. Secrets should reference environment variables.</p>
<div class="table-wrap"><table class="registry"><thead><tr><th>Service</th><th>URL</th><th>Configuration</th><th></th></tr></thead><tbody>
<?php foreach ($services as $name=>$service): ?><tr><td><?= V::h($name) ?></td><td><?= V::h($service['url'] ?? 'Not configured') ?></td>
<td><pre><?= V::pretty(array_intersect_key($service, array_flip(['url_env','token_env','error']))) ?></pre></td><td><?= V::iconAction('edit','Edit service '.$name,['data-dialog'=>'services','href'=>'?edit=1']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>

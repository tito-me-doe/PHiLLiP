<?php
use Agent\Views as V;
Agent\Pages::authorize($state['user']['id'], 'models');
$known = ['searxng', 'camoufox'];
$services = [];
foreach ($known as $name) {
    try { $services[$name] = Agent\Config::service($name); }
    catch (Exception $e) { $services[$name] = ['error'=>$e->getMessage()]; }
}
// Edit one named service at a time; only the submitted entry is rewritten.
// Flat fields post straight to Config::configureServices({name,url,url_env,token_env}).
$name = !empty($editing) && isset($_GET['name']) && in_array($_GET['name'], $known, true)
    ? $_GET['name']
    : '';
// The table lists resolved URLs, but the editor loads the RAW stored fallback
// (url_env unresolved) so an edit/save never bakes a runtime env URL into
// storage. A missing/unloadable service yields a blank editable form with a
// warning instead of leaking/resolving the environment URL.
$service = [];
$hasError = false;
if ($name) {
    try { $service = Agent\Config::service($name, false); }
    catch (Exception $e) { $service = ['error'=>$e->getMessage()]; $hasError = true; }
}
?>
<?php if (!empty($editing) && $name): ?>
<form method="post" id="service-form" class="form-stack"><?= V::csrf($state,'save-services') ?>
<input type="hidden" name="name" value="<?= V::h($name) ?>">
<h2>Edit <?= V::h($name) ?></h2>
<?php if ($hasError): ?><div class="form-warning"><?= V::h($service['error']) ?></div><?php endif; ?>
<label>URL<input name="url" type="url" value="<?= V::h($service['url'] ?? '') ?>" required></label>
<label>URL variable <small>environment variable that holds the URL</small><input name="url_env" type="text" value="<?= V::h($service['url_env'] ?? '') ?>" placeholder="SEARXNG_URL"></label>
<label>Token variable <small>environment variable that holds the token</small><input name="token_env" type="text" value="<?= V::h($service['token_env'] ?? '') ?>" placeholder="BROWSER_TOKEN"></label>
<div class="form-error" id="service-error"></div>
<div class="row end"><button class="primary">Save</button><a class="button" data-close-editor href="<?= V::h(Agent\Pages::link('models')) ?>">Cancel</a></div>
</form>
<?php else: ?>
<section class="card" id="services"><div class="card-head"><h2>Services</h2></div>
<div class="table-wrap"><table class="registry"><thead><tr><th>Service</th><th>URL</th><th>Variables</th><th></th></tr></thead><tbody>
<?php foreach ($services as $name=>$service): ?><tr><td><strong><?= V::h($name) ?></strong></td><td><?= V::h($service['url'] ?? 'Not configured') ?><?php if (!empty($service['error'])): ?><small class="error"><?= V::h($service['error']) ?></small><?php endif; ?></td>
<td><?php foreach (['url_env','token_env'] as $key) if (!empty($service[$key])): ?><code><?= V::h($service[$key]) ?></code> <?php endif; ?></td><td><?= V::iconAction('edit','Edit service '.$name,['data-dialog'=>'services','data-name'=>$name,'href'=>Agent\Pages::link('models',['configure'=>'services','name'=>$name])]) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>

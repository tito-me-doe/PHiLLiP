<?php
use Agent\Views as V;
Agent\Pages::authorize($state['user']['id'], 'models');
try { $parsers = Agent\AttachmentParser::configuration(); }
catch (Exception $e) { $parsers = []; Agent\Log::writeWarning($e->getMessage()); }
?>
<?php if (!empty($editing)): ?>
<form method="post" id="attachment-parsers-form" class="form-stack">
<?= V::csrf($state,'save-parsers') ?>
<?php
$type = isset($_GET['name']) && is_string($_GET['name']) && array_key_exists($_GET['name'], $parsers) ? $_GET['name'] : '';
$profiles = (new Agent\Registry())->profiles();
$profileNames = array_column($profiles, 'name');
?>
<label>File type<input name="type" value="<?= V::h($type) ?>" <?= $type !== '' ? 'readonly' : '' ?> required placeholder=".pdf or image/*"></label>
<label>Parsing profile<select name="profile">
<?php if ($type !== ''): ?><option value="">Remove mapping</option><?php else: ?><option value="" disabled selected>Choose a profile</option><?php endif; ?>
<?php if ($type !== '' && !in_array($parsers[$type], $profileNames, true)): ?><option value="<?= V::h($parsers[$type]) ?>" selected><?= V::h($parsers[$type]) ?> (unavailable)</option><?php endif; ?>
<?php foreach ($profiles as $profile): ?><option value="<?= V::h($profile['name']) ?>" <?= $type !== '' && $parsers[$type] === $profile['name'] ? 'selected' : '' ?>><?= V::h($profile['name']) ?></option><?php endforeach; ?>
</select></label>
<div class="form-error"></div><button class="primary">Save mapping</button></form>
<?php else: ?>
<section class="card" id="attachment-parsing"><div class="card-head"><h2>Attachment parsing</h2><?= V::iconAction('plus','New mapping',['data-dialog'=>'parsers','href'=>Agent\Pages::link('models',['configure'=>'parsers'])]) ?></div>
<div class="table-wrap"><table class="registry"><thead><tr><th>File type</th><th>Profile</th><th></th></tr></thead><tbody>
<?php foreach ($parsers as $type=>$profile): ?><tr><td><code><?= V::h($type) ?></code></td><td><?= V::h($profile) ?></td><td><?= V::iconAction('edit','Edit parsing '.$type,['data-dialog'=>'parsers','data-name'=>$type,'href'=>Agent\Pages::link('models',['configure'=>'parsers','name'=>$type])]) ?></td></tr><?php endforeach; ?>
<?php if (!$parsers): ?><tr><td colspan="3" class="empty">Built-in conversion is used for every file type.</td></tr><?php endif; ?>
</tbody></table></div>
</section>
<?php endif; ?>

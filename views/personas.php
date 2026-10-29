<?php
use Agent\Views as V;
$user = $state['user'];
Agent\Pages::authorize($user['id'], 'personas');
?>
<section class="card"><h2>Personas</h2>
<p class="hint">Selecting a persona copies its prompt into a conversation. Later changes do not rewrite existing conversations.</p>
<div class="table-wrap"><table class="registry"><thead><tr><th>Name</th><th>Instructions</th><th>Visibility</th><th></th></tr></thead><tbody>
<?php foreach ($state['personas'] as $persona): ?>
<tr><td><strong><?= V::h($persona['name']) ?></strong><?= $persona['is_default'] ? '<small>Curated</small>' : '' ?></td>
<td><?= nl2br(V::h($persona['prompt'])) ?></td><td><?= V::h($persona['visibility']) ?></td>
<td><?php if ($persona['owner_id'] === $user['id'] || ($user['is_admin'] && $persona['visibility'] !== 'private')): ?>
<?= V::iconAction('edit','Edit persona '.$persona['name'],['data-dialog'=>'persona','data-id'=>$persona['id']]) ?>
<?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody><tfoot><tr><td colspan="4"><?= V::iconAction('plus','New persona',['data-dialog'=>'persona']) ?></td></tr></tfoot></table></div></section>

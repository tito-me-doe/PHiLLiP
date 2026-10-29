<?php
use Agent\Views as V;
$user = $state['user'];
Agent\Pages::authorize($user['id'], 'knowledge');
$claims = Agent\Knowledge::recall($_GET['q'] ?? '', $user['id'], null, 50);
?>
<section class="card"><h2>Source-backed memory</h2>
<p class="hint">Every conversation is indexed. Memory additionally permits cross-thread retrieval and maintaining durable knowledge.</p>
<form method="get" class="row"><input name="q" value="<?= V::h($_GET['q'] ?? '') ?>" placeholder="Search facts, preferences, relationships…" aria-label="Search memory"><button>Search</button></form>
<div class="table-wrap"><table class="registry"><thead><tr><th>Memory</th><th>Visibility</th><th>Sources</th><th></th></tr></thead><tbody>
<?php foreach ($claims as $claim): ?>
<tr><td><?= V::h($claim['content']) ?></td><td><?= V::h($claim['visibility']) ?></td><td>
<?php foreach ($claim['sources'] as $source): ?><a href="<?= V::url('?thread='.$claim['thread_id'].'#message-'.$source) ?>"><?= V::h($source) ?></a> <?php endforeach; ?>
</td><td><?php if ($claim['owner_id'] === $user['id']): ?><?= V::iconAction('edit','Edit memory',['data-dialog'=>'claim','data-id'=>$claim['id']]) ?><?= V::iconAction('trash','Forget memory',['data-forget'=>$claim['id']]) ?><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$claims): ?><tr><td colspan="4" class="empty">No matching memories yet.</td></tr><?php endif; ?>
</tbody></table></div></section>

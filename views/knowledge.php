<?php
use Agent\Views as V;
$user = $state['user'];
Agent\Pages::authorize($user['id'], 'knowledge');
$can = array_flip(Agent\Pages::sections($user['id'], 'knowledge')); // the cards this person may see
$query = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$link = function ($q, $page = 1) { return Agent\Pages::link('knowledge', array_filter(['q' => $q, 'page' => $page > 1 ? $page : null], function ($v) { return $v !== '' && $v !== null; })); };
?>
<?php if (isset($can['graph'])): ?>
<section class="card" id="memory-graph">
<div class="card-head"><h2>Graph</h2><?php if ($query !== ''): ?><a class="button" href="<?= V::h($link('')) ?>">Show all</a><?php endif; ?></div>
<?php $edges = Agent\Knowledge::graphEdges($query, $user['id']); ?>
<?php if ($edges): ?>
<div class="graph-wrap"><?= Agent\GraphLayout::svg($edges, function ($label) use ($link) { return $link($label); }, $query) ?></div>
<?php else: ?><p class="empty"><?= $query === '' ? 'Nothing is connected yet. Memories that relate two things appear here.' : 'No connections match.' ?></p><?php endif; ?>
</section>
<?php endif; ?>
<?php if (isset($can['memories'])):
    $page = Agent\Knowledge::recallPage($query, $user['id'], $_GET['page'] ?? 1);
?>
<section class="card" id="memories">
<div class="card-head"><h2>Memories</h2><form method="get" class="toolbar"><input name="q" value="<?= V::h($query) ?>" placeholder="Search memory" aria-label="Search memory"><button class="primary">Search</button></form></div>
<div class="table-wrap"><table class="registry"><thead><tr><th>Memory</th><th class="vis">Visibility</th><th>Sources</th><th></th></tr></thead><tbody>
<?php foreach ($page['rows'] as $claim): ?>
<tr><td><?= V::h($claim['content']) ?></td><td class="vis"><?= V::h($claim['visibility']) ?></td><td>
<?php foreach ($claim['sources'] as $source): ?><a href="<?= V::url('?thread='.$claim['thread_id'].'#message-'.$source) ?>"><?= V::h($source) ?></a> <?php endforeach; ?>
</td><td class="actions"><?php if ($claim['owner_id'] === $user['id']): ?><?= V::iconAction('edit','Edit memory',['data-dialog'=>'claim','data-id'=>$claim['id']]) ?><?= V::iconAction('trash','Forget memory',['data-forget'=>$claim['id']]) ?><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$page['rows']): ?><tr><td colspan="4" class="empty">No matching memories yet.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($page['pages'] > 1 || $page['total'] > 0): ?>
<nav class="pager" aria-label="Memory pages">
    <?php if ($page['page'] > 1): ?><a class="button icon" href="<?= V::h($link($query, $page['page'] - 1)) ?>" rel="prev" aria-label="Previous page"><?= V::icon('chevron-left') ?></a><?php else: ?><span class="button icon" aria-hidden="true" disabled></span><?php endif; ?>
    <span class="pager-status">Page <?= (int)$page['page'] ?> of <?= (int)$page['pages'] ?> · <?= (int)$page['total'] ?> <?= $page['total'] === 1 ? 'memory' : 'memories' ?></span>
    <?php if ($page['page'] < $page['pages']): ?><a class="button icon" href="<?= V::h($link($query, $page['page'] + 1)) ?>" rel="next" aria-label="Next page"><?= V::icon('chevron-right') ?></a><?php else: ?><span class="button icon" aria-hidden="true" disabled></span><?php endif; ?>
</nav>
<?php endif; ?>
</section>
<?php endif; ?>

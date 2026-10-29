<?php
use Agent\Views as V;
$data = (new Agent\Administration($state['user']))->data('navigation');
$users = array_column($data['users'],'display_name','id');
$groups = array_column($data['groups'],'name','id');
$allowed = [];
foreach ($data['permissions'] as $grant) {
    if ($grant['scope_type'] !== 'user' && (string)$grant['scope_id'] === '1') continue;
    if (strpos($grant['tool'],'page.') !== 0) continue;
    $kind = $grant['scope_type'] === 'user' ? 'users' : 'groups';
    $names = $kind === 'users' ? $users : $groups;
    $allowed[$grant['tool']][$kind][] = $names[$grant['scope_id']] ?? $grant['scope_id'];
}
?>
<?php if (isset($_GET['edit'])): ?>
<section class="card"><?= V::render('management-form', ['state'=>$state]) ?></section>
<?php else: ?>
<section class="card">
    <h2>Pages and routes</h2>
    <p class="hint">Menu and route access use the same user/group grants. Edit a row to manage its allowed users and groups. Routes sort alphabetically by path.</p>
    <div class="table-wrap"><table class="registry"><thead><tr><th>Label</th><th>Route</th><th>Allowed users</th><th>Allowed groups</th><th></th></tr></thead><tbody>
    <?php foreach ($data['pages'] as $page): $grants = $allowed['page.'.$page['id']] ?? []; ?>
        <tr><td><?= V::h($page['title']) ?><?php if ($page['id'] !== $page['handler']): ?><small>Also requires <?= V::h($page['handler']) ?> permission</small><?php endif; ?></td>
            <td><code><?= V::h($page['path']) ?></code></td><td><?= V::h(implode(', ',$grants['users'] ?? []) ?: '—') ?></td>
            <td><?= V::h(implode(', ',array_merge(['Administrators (automatic)'],$grants['groups'] ?? []))) ?></td>
            <td><?= V::editor('navigation', $page['id'], 'Edit route '.$page['title']) ?></td></tr>
    <?php endforeach; ?>
    </tbody><tfoot><tr><td colspan="5"><?= V::editor('navigation', null, 'New route') ?></td></tr></tfoot></table></div>
</section>
<?php endif; ?>

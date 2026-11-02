<?php
use Agent\Views as V;
use Agent\Pages;
$data = (new Agent\Administration($state['user']))->data('access');
$users = array_column($data['users'], 'display_name', 'id');
$groups = array_column($data['groups'], 'name', 'id');
$byUser = $byGroup = [];
foreach ($data['memberships'] as $membership) {
    $byUser[$membership['user_id']][] = $groups[$membership['team_id']] ?? $membership['team_id'];
    $byGroup[$membership['team_id']][] = $users[$membership['user_id']] ?? $membership['user_id'];
}
// Who is granted what: resource => ['users' => [...], 'groups' => [...]].
$granted = [];
foreach ($data['permissions'] as $grant) {
    if ($grant['scope_type'] !== 'user' && (string)$grant['scope_id'] === '1') continue; // Administrators always have access.
    $kind = $grant['scope_type'] === 'user' ? 'users' : 'groups';
    $names = $kind === 'users' ? $users : $groups;
    $granted[$grant['tool']][$kind][] = $names[$grant['scope_id']] ?? $grant['scope_id'];
}
$who = function ($resource) use ($granted) {
    return V::h(implode(', ', array_merge($granted[$resource]['groups'] ?? [], $granted[$resource]['users'] ?? [])) ?: 'Administrators only');
};
$descriptions = [
    'SearchWeb' => 'Search the web',
    'Fetch' => 'Read pages and files',
    'BrowseWeb' => 'Use a web browser',
    'Memory' => 'Search and keep knowledge',
    'Tasks' => 'Classify, extract and compare'
];
if (isset($_GET['edit'])):
?>
<section class="card"><?= V::render('management-form', ['state'=>$state]) ?></section>
<?php else: ?>
<section class="card">
    <div class="card-head"><h2>Users</h2><?= V::editor('users', null, 'New user') ?></div>
    <div class="table-wrap"><table class="registry"><thead><tr><th></th><th>Username</th><th>Name</th><th>Groups</th><th></th></tr></thead><tbody>
    <?php foreach ($data['users'] as $person): ?>
        <?php $manage = !$person['is_admin'] || $state['user']['is_admin']; ?>
        <tr<?= $person['active'] ? '' : ' class="is-off"' ?>><td class="switch-cell"><?php if ($manage && (string)$person['id'] !== (string)$state['user']['id']): ?><form method="post" class="switch-form" data-user-active="<?= V::h($person['id']) ?>" data-username="<?= V::h($person['username']) ?>" data-display-name="<?= V::h($person['display_name']) ?>"><input type="checkbox" role="switch" <?= $person['active'] ? 'checked' : '' ?> aria-label="<?= V::h(($person['active'] ? 'Deactivate ' : 'Activate ').$person['username']) ?>"></form><?php endif; ?></td><td><?= V::h($person['username']) ?></td><td><?= V::h($person['display_name']) ?></td>
            <td><?= V::h(implode(', ', array_merge(['Everyone'], $byUser[$person['id']] ?? []))) ?></td>
            <td class="actions"><?php if ($manage): ?><?= V::editor('users', $person['id'], 'Edit user '.$person['username']) ?><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<section class="card">
    <div class="card-head"><h2>Groups</h2><?= V::editor('groups', null, 'New group') ?></div>
    <div class="table-wrap"><table class="registry"><thead><tr><th>Group</th><th>Members</th><th></th></tr></thead><tbody>
    <?php foreach ($data['groups'] as $group): $id = (string)$group['id']; ?>
        <tr><td><?= V::h($group['name']) ?></td>
            <td><?= V::h($id === '0' ? 'Everyone signed in' : ($id === '1' ? 'Full access' : (implode(', ', $byGroup[$group['id']] ?? []) ?: '—'))) ?></td>
            <td><?php if ($id !== '0' && ($id !== '1' || $state['user']['is_admin'])): ?><?= V::editor('groups', $group['id'], 'Edit group '.$group['name']) ?><?php endif; ?>
                <?php if (!in_array($id, ['0','1','2'], true)): ?><?= V::editor('groups', $group['id'], 'Remove group '.$group['name'], ['remove'=>1]) ?><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<section class="card">
    <div class="card-head"><h2>Pages</h2><?= V::editor('navigation', null, 'New page') ?></div>
    <div class="table-wrap"><table class="registry"><thead><tr><th>Page</th><th>Route</th><th>Who can open it</th><th></th></tr></thead><tbody>
    <?php foreach ($data['pages'] as $page): ?>
        <tr><td><?= V::h($page['title']) ?><?php if ($page['id'] !== $page['handler']): ?><small>Also needs <?= V::h($page['handler']) ?></small><?php endif; ?></td>
            <td><code><?= V::h($page['path']) ?></code></td><td><?= $who('page.'.$page['id']) ?></td>
            <td><?= V::editor('navigation', $page['id'], 'Edit page '.$page['title']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<section class="card">
    <div class="card-head"><h2>Tools</h2></div>
    <div class="table-wrap"><table class="registry"><thead><tr><th>Tool</th><th>Who can use it</th><th></th></tr></thead><tbody>
    <?php foreach (Agent\Frontend::TOOLS as $tool): $name = substr($tool, strrpos($tool, '\\') + 1); ?>
        <tr><td><strong><?= V::h(Agent\ToolRegistry::label($name)) ?></strong><small><?= V::h($descriptions[$name] ?? '') ?></small></td>
            <td><?= $who($tool) ?></td>
            <td><?= V::editor('tools', null, 'Edit tool access '.$name, ['resource'=>$tool]) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<?php endif; ?>

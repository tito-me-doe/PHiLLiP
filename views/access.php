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
if (isset($_GET['edit'])):
?>
<section class="card"><?= V::render('management-form', ['state'=>$state]) ?></section>
<?php else: ?>
<nav class="access-links" aria-label="Access control tables"><a href="#access-users">Users</a><a href="#access-groups">Groups</a><a href="#access-grants">Permissions</a></nav>
<section class="card" id="access-users">
    <h2>Users</h2>
    <p class="hint">Edit a user to manage their groups and account. Memberships are additive; every signed-in user belongs to Everyone.</p>
    <div class="table-wrap"><table class="registry"><thead><tr><th>Username</th><th>Name</th><th>Groups</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($data['users'] as $person): ?>
        <tr><td><?= V::h($person['username']) ?></td><td><?= V::h($person['display_name']) ?></td>
            <td><?= V::h(implode(', ', array_merge(['Everyone'], $byUser[$person['id']] ?? []))) ?></td>
            <td><span class="badge <?= $person['active'] ? '' : 'error' ?>"><?= $person['active'] ? 'Active' : 'Inactive' ?></span></td>
            <td><?php if (!$person['is_admin'] || $state['user']['is_admin']): ?><?= V::editor('users', $person['id'], 'Edit user '.$person['username']) ?><?php else: ?><span class="muted">Administrator only</span><?php endif; ?></td></tr>
    <?php endforeach; ?>
</tbody><tfoot><tr><td colspan="5"><?= V::editor('users', null, 'New user') ?></td></tr></tfoot></table></div>
</section>
<section class="card" id="access-groups">
    <h2>Groups</h2>
    <p class="hint">Edit a group to manage its members. Built-in group IDs and names are fixed.</p>
    <div class="table-wrap"><table class="registry"><thead><tr><th>ID</th><th>Group</th><th>Members</th><th></th></tr></thead><tbody>
    <?php foreach ($data['groups'] as $group): ?>
        <tr><td><?= V::h($group['id']) ?></td><td><?= V::h($group['name']) ?><?php if ((string)$group['id'] === '1'): ?><small>All permissions automatically</small><?php endif; ?></td>
            <td><?= V::h((string)$group['id'] === '0' ? 'All signed-in users (automatic)' : implode(', ', $byGroup[$group['id']] ?? [])) ?></td>
            <td><?php if ((string)$group['id'] === '0'): ?><span class="muted">Automatic</span>
                <?php elseif ((string)$group['id'] !== '1' || $state['user']['is_admin']): ?><?= V::editor('groups', $group['id'], 'Edit group '.$group['name']) ?>
                <?php else: ?><span class="muted">Administrator only</span><?php endif; ?>
                <?php if (!in_array((string)$group['id'], ['0','1','2'], true)): ?>
                    <?= V::editor('groups', $group['id'], 'Remove group '.$group['name'], ['remove'=>1]) ?>
                <?php endif; ?></td></tr>
    <?php endforeach; ?>
</tbody><tfoot><tr><td colspan="4"><?= V::editor('groups', null, 'New group') ?></td></tr></tfoot></table></div>
</section>
<section class="card" id="access-grants">
    <h2>Permissions</h2>
    <p class="hint">Administrators (1) automatically have all permissions. Other users need a grant directly or through any of their groups; otherwise access is denied. Everyone (0) applies to all signed-in users.</p>
    <div class="table-wrap"><table class="registry"><thead><tr><th>Applies to</th><th>Resource</th><th></th></tr></thead><tbody>
    <?php foreach ($data['permissions'] as $grant):
        $scope = ($grant['scope_type'] === 'user' ? 'user:' : 'group:').$grant['scope_id'];
        $subject = $grant['scope_type'] === 'user' ? ($users[$grant['scope_id']] ?? $grant['scope_id']) : ($groups[$grant['scope_id']] ?? $grant['scope_id']); ?>
        <tr><td><?= V::h(($grant['scope_type'] === 'user' ? 'User · ' : 'Group · ').$subject) ?></td>
            <td><?= V::h($data['resources'][$grant['tool']] ?? $grant['tool']) ?></td>
            <td><?= V::editor('permissions', null, 'Remove permission', ['scope'=>$scope,'resource'=>$grant['tool'],'remove'=>1]) ?></td></tr>
    <?php endforeach; ?>
</tbody><tfoot><tr><td colspan="3"><?= V::editor('permissions', null, 'New permission') ?></td></tr></tfoot></table></div>
</section>
<?php endif; ?>

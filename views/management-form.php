<?php
use Agent\Views as V;
use Agent\Pages;
$entity = $_GET['entity'] ?? $_GET['edit'] ?? '';
if (!in_array($entity, ['users','groups','permissions','navigation'], true)) throw new InvalidArgumentException('Unknown editor');
$data = (new Agent\Administration($state['user']))->data($entity);
$record = $_GET['record'] ?? null;
if ($record !== null && !is_string($record)) throw new InvalidArgumentException('Invalid record');
$remove = Agent\Config::boolean($_GET['remove'] ?? false, 'Remove record');
$row = null;
foreach ($entity === 'navigation' ? $data['pages'] : ($data[$entity] ?? []) as $candidate) {
    if ($record !== null && isset($candidate['id']) && (string)$candidate['id'] === $record) $row = $candidate;
}
if ($record !== null && !$row) throw new InvalidArgumentException('Record not found');
$returnPage = $entity === 'navigation' ? 'navigation' : 'access';
$labels = ['users'=>'user','groups'=>'group','permissions'=>'permission','navigation'=>'route'];
$title = ($remove ? 'Remove ' : ($row ? 'Edit ' : 'New ')).$labels[$entity];
?>
<div class="dialog-head"><h2><?= V::h($title) ?></h2><a class="button icon" data-close-editor href="<?= Pages::link($returnPage) ?>" aria-label="Close"><?= V::icon('close') ?></a></div>
<div class="dialog-body">
<form method="post" action="<?= Pages::link($returnPage) ?>" class="form-stack" data-manage="<?= V::h($entity) ?>">
    <?= V::csrf($state, 'manage-'.$entity) ?>
    <?php if ($record !== null): ?><input type="hidden" name="id" value="<?= V::h($record) ?>"><?php endif; ?>
    <?php if ($remove): ?>
        <input type="hidden" name="remove" value="true">
        <?php if ($entity === 'permissions'): ?>
            <input type="hidden" name="scope" value="<?= V::h($_GET['scope'] ?? '') ?>">
            <input type="hidden" name="resource" value="<?= V::h($_GET['resource'] ?? '') ?>">
            <p>Revoke this grant? Other grants from a user or their groups still apply.</p>
        <?php else: ?><p>Remove <?= V::h($row['name']) ?>? Only empty groups without permissions or shared content can be removed.</p><?php endif; ?>
        <label class="check-label"><input type="checkbox" name="confirm" value="true" required>Confirm removal</label>
    <?php elseif ($entity === 'users'):
        if ($row && $row['is_admin'] && !$state['user']['is_admin']) throw new RuntimeException('Only an administrator may manage administrator accounts');
        $selected = $row ? array_column(Agent\Mappings::rows('memberships',['user_id'=>$row['id']]),'team_id') : []; ?>
        <label>Username<input name="username" required maxlength="100" autocomplete="off" value="<?= V::h($row['username'] ?? '') ?>"></label>
        <label>Name<input name="display_name" required maxlength="160" value="<?= V::h($row['display_name'] ?? '') ?>"></label>
        <label><?= $row ? 'New password (leave blank to keep)' : 'Password' ?><input name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" <?= $row ? '' : 'required' ?>></label>
        <?= V::select('active', 'Account status', ['true'=>'Active','false'=>'Inactive'], !$row || $row['active'] ? 'true' : 'false') ?>
        <input type="hidden" name="groups_present" value="true">
        <fieldset><legend>Groups</legend><p class="hint">Everyone (0) is automatic. Select any number of additional groups.</p>
        <?php foreach ($data['groups'] as $group):
            if ((string)$group['id'] === '0') continue; ?>
            <label class="check-label"><input name="groups[]" type="checkbox" value="<?= V::h($group['id']) ?>" <?= in_array((string)$group['id'], $selected, true) ? 'checked' : '' ?> <?= (string)$group['id'] === '1' && !$state['user']['is_admin'] ? 'disabled' : '' ?>><?= V::h($group['name']) ?></label>
        <?php endforeach; ?></fieldset>
    <?php elseif ($entity === 'groups'):
        $selected = $row ? array_column(Agent\Mappings::rows('memberships',['team_id'=>$row['id']]),'user_id') : []; ?>
        <label>Name<input name="name" required maxlength="160" value="<?= V::h($row['name'] ?? '') ?>" <?= $row && in_array((string)$row['id'], ['0','1','2'], true) ? 'readonly' : '' ?>></label>
        <?php if ($record === '0'): ?><p>Everyone automatically includes every signed-in user; no membership assignments are needed.</p>
        <?php else: ?><input type="hidden" name="members_present" value="true">
            <fieldset><legend>Members</legend>
            <?php foreach ($data['users'] as $person): ?>
                <label class="check-label"><input name="members[]" type="checkbox" value="<?= V::h($person['id']) ?>" <?= in_array($person['id'], $selected, true) ? 'checked' : '' ?>><?= V::h($person['display_name'].' ('.$person['username'].')') ?></label>
            <?php endforeach; ?></fieldset>
        <?php endif; ?>
    <?php elseif ($entity === 'permissions'):
        $subjects = [];
        foreach ($data['groups'] as $group) if ((string)$group['id'] !== '1') $subjects['group:'.$group['id']] = 'Group · '.$group['name'];
        foreach ($data['users'] as $person) $subjects['user:'.$person['id']] = 'User · '.$person['display_name']; ?>
        <?= V::select('scope', 'Applies to', $subjects, $_GET['scope'] ?? 'group:0') ?>
        <?= V::select('resource', 'Resource to allow', [''=>'Choose a resource']+$data['resources']) ?>
        <p class="hint">This creates a grant. To revoke access, remove its grants—there are no deny rules. Administrators (1) always have all permissions and need no grants.</p>
    <?php elseif ($entity === 'navigation'): ?>
        <?php if (!$row): ?><input type="hidden" name="create" value="true"><label>Identifier<input name="id" required pattern="[a-z][a-z0-9-]*" maxlength="80"></label><?php endif; ?>
        <label>Label<input name="title" required maxlength="80" value="<?= V::h($row['title'] ?? '') ?>"></label>
        <label>Route<input name="path" required maxlength="100" placeholder="/my-page" value="<?= V::h($row['path'] ?? '') ?>"></label>
        <?php if ($row): ?><label>Page concept<input name="handler" readonly value="<?= V::h($row['handler']) ?>"></label>
        <?php else: echo V::select('handler', 'Page concept', array_combine(array_keys(Pages::VIEWS),array_keys(Pages::VIEWS))); endif; ?>
        <?php
        $allowedUsers = $allowedGroups = [];
        foreach ($data['permissions'] as $grant) if ($row && $grant['tool'] === 'page.'.$row['id']) {
            if ($grant['scope_type'] === 'user') $allowedUsers[] = (string)$grant['scope_id'];
            elseif ($grant['scope_type'] === 'team') $allowedGroups[] = (string)$grant['scope_id'];
        }
        ?>
        <input type="hidden" name="allowed_users_present" value="true"><input type="hidden" name="allowed_groups_present" value="true">
        <fieldset><legend>Allowed users</legend>
        <?php foreach ($data['users'] as $person): ?>
            <label class="check-label"><input name="allowed_users[]" type="checkbox" value="<?= V::h($person['id']) ?>" <?= in_array($person['id'],$allowedUsers,true) ? 'checked' : '' ?>><?= V::h($person['display_name'].' ('.$person['username'].')') ?></label>
        <?php endforeach; ?></fieldset>
        <fieldset><legend>Allowed groups</legend>
        <p class="hint">Administrators (1) — always allowed, automatically.</p>
        <?php foreach ($data['groups'] as $group): if ((string)$group['id'] === '1') continue; ?>
            <label class="check-label"><input name="allowed_groups[]" type="checkbox" value="<?= V::h($group['id']) ?>" <?= in_array((string)$group['id'],$allowedGroups,true) ? 'checked' : '' ?>><?= V::h($group['name'].' ('.$group['id'].')') ?></label>
        <?php endforeach; ?></fieldset>
        <p class="hint">These are the same grants managed in Access control. No selection permits only Administrators; Everyone (0) permits all signed-in users.</p>
        <p class="hint">Routes sort alphabetically by path. A new route also requires its underlying page's permission.</p>
    <?php endif; ?>
    <div class="form-error" role="alert"></div>
    <button class="primary"><?= $remove ? 'Remove' : 'Save' ?></button>
</form>
</div>

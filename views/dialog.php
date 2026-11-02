<?php
use Agent\Views as V;
$kind = isset($_GET['kind']) ? $_GET['kind'] : '';
// An expert is an instruction-only persona; it shares the persona editor and API.
$newExpert = $kind === 'expert';
if ($newExpert) $kind = 'persona';
$user = $state['user'];
if ($kind === 'manage') {
    echo V::render('management-form', ['state'=>$state]);
    return;
}
if ($kind === 'project-todo') {
    echo '<div class="dialog-head"><h2>Project ToDo</h2><button class="icon" data-action="close-dialog" aria-label="Close">'.V::icon('close').'</button></div><div class="dialog-body">';
    echo V::render('projects',['state'=>$state,'projectId'=>$_GET['project'] ?? null,'todoEditor'=>true]);
    echo '</div>';
    return;
}
if ($kind === 'project-decision') {
    echo '<div class="dialog-head"><h2>Project decision</h2><button class="icon" data-action="close-dialog" aria-label="Close">'.V::icon('close').'</button></div><div class="dialog-body">';
    echo V::render('projects',['state'=>$state,'projectId'=>$_GET['project'] ?? null,'decisionEditor'=>true]);
    echo '</div>';
    return;
}
if (in_array($kind, ['benchmark-test','benchmark-question','benchmark-questions'], true)) {
    Agent\Pages::authorize($user['id'],'benchmarks');
    echo '<div class="dialog-head"><h2>Edit test suite</h2><button class="icon" data-action="close-dialog" aria-label="Close">'.V::icon('close').'</button></div><div class="dialog-body">';
    echo V::render($kind === 'benchmark-questions' ? 'benchmark-questions' : 'benchmark-form',['state'=>$state,'kind'=>$kind]);
    echo '</div>';
    return;
}
if (in_array($kind, ['benchmark-run','benchmark-metadata','benchmark-profile'], true)) {
    Agent\Pages::authorize($user['id'],'benchmarks');
    echo '<div class="dialog-head"><h2>'.($kind === 'benchmark-run' ? 'Benchmark run' : ($kind === 'benchmark-profile' ? 'Profile results' : 'Model metadata')).'</h2><button class="icon" data-action="close-dialog" aria-label="Close">'.V::icon('close').'</button></div><div class="dialog-body benchmark-inspection">';
    echo V::render('benchmark-inspect',['state'=>$state,'kind'=>$kind,'id'=>isset($_GET['id']) ? (int)$_GET['id'] : 0]);
    echo '</div>';
    return;
}
if (in_array($kind, ['services','parsers','model-default','profile-defaults'], true)) {
    Agent\Pages::authorize($user['id'], 'models');
    echo '<div class="dialog-head"><h2>'.['services'=>'Service','parsers'=>'Attachment parsing','model-default'=>'Profile defaults','profile-defaults'=>'Profile defaults'][$kind].'</h2><button class="icon" data-action="close-dialog" aria-label="Close">'.V::icon('close').'</button></div><div class="dialog-body">';
    echo V::render($kind, ['state'=>$state,'editing'=>true]);
    echo '</div>';
    return;
}
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$title = 'Settings';
if ($kind === 'profile') {
    $title = 'Configure model profile';
} elseif ($kind === 'persona') {
    Agent\Pages::authorize($user['id'], 'personas');
    $isExpert = $newExpert || ($id && empty(Agent\Personas::get($id)['sheet']));
    $title = ($id ? 'Edit ' : 'New ') . ($isExpert ? 'expert' : 'persona');
} elseif ($kind === 'chat') {
    $title = 'Conversation settings';
} elseif ($kind === 'edit') {
    $title = 'Edit your message';
} elseif ($kind === 'claim') {
    Agent\Pages::authorize($user['id'], 'knowledge');
    $title = 'Edit memory';
} else {
    throw new InvalidArgumentException('Unknown dialog');
}
?>
<div class="dialog-head"><h2><?= $title ?></h2><button class="icon" data-action="close-dialog" aria-label="Close"><?= V::icon(
    'close'
) ?></button></div><div class="dialog-body">
<?php if ($kind === 'profile'):
    echo V::render('profile-form', ['state' => $state]);
elseif ($kind === 'persona'):

    $p = $id
        ? Agent\Personas::get($id)
        : [
            'id' => null,
            'name' => '',
            'prompt' => '',
            'tools' => [],
            'contract' => null,
            'visibility' => 'private',
            'team_id' => null,
            'owner_id' => $user['id'],
            'sheet' => null,
            'avatar' => null,
            'pronouns' => null,
            'tagline' => null,
            'maturity' => 'everyone'
        ];
    if ($id) {
        Agent\Access::personaEdit($user['id'], $p);
    }
    ?>
<?php
$sheet = isset($p['sheet']) && is_array($p['sheet']) ? $p['sheet'] : [];
$character = $id ? (bool)$sheet : !$newExpert;
$voice = isset($sheet['voice']) && is_array($sheet['voice']) ? $sheet['voice'] : [];
$val = function ($value) { return V::h((string)$value); };
$lines = function ($list) { return V::h(implode("\n", is_array($list) ? $list : [])); };
$chosenAvatar = !empty($p['avatar']) ? $p['avatar'] : 'terminal';
if ($character): ?>
<form method="post" id="persona-form" class="form-stack persona-editor" data-id="<?= V::h($p['id']) ?>">
<div class="persona-preview" aria-hidden="true"><?= V::avatar($state, 'persona-avatar', $chosenAvatar) ?><span class="pv-tagline"><?= $val(!empty($p['tagline']) ? $p['tagline'] : 'Their tagline') ?></span><span class="persona-line"><strong class="pv-name"><?= $val($p['name'] !== '' ? $p['name'] : 'Their name') ?></strong><em class="pv-pronouns"><?= $val($p['pronouns'] ?? '') ?></em></span></div>
<div class="grid"><label>Name<input name="name" value="<?= $val($p['name']) ?>" maxlength="80" required></label>
<label>Pronouns (optional)<input name="pronouns" value="<?= $val($p['pronouns'] ?? '') ?>" maxlength="40" placeholder="she/her, he/him, they/them…"></label></div>
<label>Tagline<input name="tagline" value="<?= $val($p['tagline'] ?? '') ?>" maxlength="140" required placeholder="One line that sums them up"></label>
<fieldset class="avatar-gallery"><legend>Look</legend><div class="avatar-choices">
<?php foreach (Agent\PersonaSheet::avatars() as $avatar): ?>
<label class="avatar-choice" title="<?= V::h(ucfirst($avatar)) ?>"><input type="radio" name="avatar" value="<?= V::h($avatar) ?>" <?= $avatar === $chosenAvatar ? 'checked' : '' ?>><?= V::avatar($state, 'persona-avatar', $avatar) ?><span class="sr-only"><?= V::h(ucfirst($avatar)) ?></span></label>
<?php endforeach; ?></div></fieldset>
<label>Who are they?<textarea name="bio" rows="4" required placeholder="Age, background, quirks, what they care about"><?= $val($sheet['bio'] ?? '') ?></textarea></label>
<label>How do they sound? (optional)<input name="voice_summary" value="<?= $val($voice['summary'] ?? '') ?>" maxlength="400" placeholder="Warm and dry, quick and teasing, slow and calm…"></label>
<div class="grid"><label>Sense of humor (optional)<input name="humor" value="<?= $val($voice['humor'] ?? '') ?>" maxlength="400"></label>
<label>Approach to problems (optional)<textarea name="approach" rows="2"><?= $val($sheet['approach'] ?? '') ?></textarea></label></div>
<div class="grid"><label>Phrases they would say (one per line)<textarea name="signature" rows="3"><?= $lines($voice['signature'] ?? []) ?></textarea></label>
<label>Things they never do (one per line)<textarea name="avoid" rows="3"><?= $lines($voice['avoid'] ?? []) ?></textarea></label></div>
<label>Lines they hold (one per line, optional)<textarea name="boundaries" rows="2"><?= $lines($sheet['boundaries'] ?? []) ?></textarea><small>Every persona already says it is an AI when sincerely asked and puts safety first.</small></label>
<details><summary>Example exchanges (the best way to capture a voice)</summary>
<?php $examples = isset($sheet['examples']) && is_array($sheet['examples']) ? $sheet['examples'] : [];
foreach ([1, 2, 3] as $n): $example = $examples[$n - 1] ?? ['user' => '', 'reply' => '']; ?>
<div class="grid"><label>Someone says<input name="example<?= $n ?>_user" value="<?= $val($example['user']) ?>" maxlength="400"></label>
<label>They reply<textarea name="example<?= $n ?>_reply" rows="2"><?= $val($example['reply']) ?></textarea></label></div>
<?php endforeach; ?></details>
<?php $isAdult = ($p['maturity'] ?? 'everyone') === 'adult'; if (!empty($state['adult_personas']) || $isAdult): ?>
<div class="check-label"><input type="hidden" name="maturity" value="everyone"><label><input type="checkbox" name="maturity" value="adult" <?= $isAdult ? 'checked' : '' ?>> Adult-oriented (flirting or bawdy humor, never sexually explicit)</label><?php if (empty($state['adult_personas'])): ?><small>Adult-oriented personas are currently turned off for this installation.</small><?php endif; ?></div>
<?php endif; ?>
<details><summary>Advanced</summary><label>Default tools (optional)<input name="tools" value="<?= V::h(implode(', ', $p['tools'])) ?>" placeholder="Fetch, SearchWeb"><small>Named tools only. Existing user and team permissions still apply.</small></label>
<label>Response contract (optional JSON)<textarea name="contract" class="json-editor" rows="3" placeholder='{"answer":{"type":"string"}}'><?= $p['contract'] !== null ? V::pretty($p['contract']) : '' ?></textarea></label></details>
<div class="grid"><?= V::visibilityField($state, $p['visibility'], false, $p['owner_id'] === $user['id']),
    V::teamField($state, $p['team_id']) ?></div><div class="form-error"></div><div class="row end"><?php if ($id): ?><button type="button" id="delete-persona" data-id="<?= $id ?>">Delete</button><?php endif; ?><button class="primary">Save persona</button></div></form>
<?php else: ?>
<form method="post" id="persona-form" class="form-stack" data-id="<?= V::h($p['id']) ?>"><label>Name<input name="name" value="<?= V::h(
    $p['name']
) ?>" required></label><label>Instructions<textarea name="prompt" rows="8" required><?= V::h($p['prompt']) ?></textarea></label>
<label>Default tools (optional)<input name="tools" value="<?= V::h(
    implode(', ', $p['tools'])
) ?>" placeholder="Fetch, SearchWeb"><small>Named tools only. Existing user and team permissions still apply.</small></label>
<label>Response contract (optional JSON)<textarea name="contract" class="json-editor" rows="4" placeholder='{"answer":{"type":"string"}}'><?= $p[
    'contract'
] !== null
    ? V::pretty($p['contract'])
    : '' ?></textarea></label>
<div class="grid"><?= V::visibilityField($state, $p['visibility'], false, $p['owner_id'] === $user['id']),
    V::teamField($state, $p['team_id']) ?></div><div class="form-error"></div><div class="row end"><?php if (
    $id
): ?><button type="button" id="delete-persona" data-id="<?= $id ?>">Delete</button><?php endif; ?><button class="primary">Save</button></div></form>
<?php endif; ?>
<?php
elseif ($kind === 'chat'):

    $t = $current ? $current['thread'] : null;
    $owner = !$t || $t['owner_id'] === $user['id'];
    $disabled = $owner ? '' : 'disabled';
    $settings = $t
        ? $t['settings']
        : [
            'profile' => isset($state['profiles'][0]) ? $state['profiles'][0]['name'] : '',
            'tools' => [],
            'parameters' => []
        ];
    $system = '';
    if ($current) {
        foreach ($current['messages'] as $message) {
            if ($message['role'] === 'system') {
                $system = $message['raw']['content'];
            }
        }
    }
    ?>
<form method="post" id="chat-settings-form" class="form-stack"><label>Model profile<select name="profile" <?= $disabled ?>><?php foreach (
    $state['profiles']
    as $profile
): ?><option value="<?= V::h($profile['name']) ?>" <?= $settings['profile'] === $profile['name'] ? 'selected' : '' ?>><?= V::h(
    $profile['name'] . ' · ' . $profile['model']
) ?></option><?php endforeach; ?></select></label><div><label>Agent tools</label><div class="grid"><?php foreach (
    $state['tools']
    as $tool
): ?><label class="check-label"><input type="checkbox" name="tools" value="<?= V::h($tool) ?>" <?= in_array(
    $tool,
    Agent\ToolRegistry::policies($settings['tools']),
    true
)
    ? 'checked'
    : '' ?> <?= $disabled ?>><?= V::h(
    substr($tool, strrpos($tool, '\\') + 1)
) ?></label><?php endforeach; ?></div></div><div class="grid"><label>Temperature<input name="temperature" type="number" min="0" max="2" step="0.05" value="<?= V::h(
    isset($settings['parameters']['temperature']) ? $settings['parameters']['temperature'] : 0.65
) ?>" <?= $disabled ?>></label><label>Maximum output tokens<input name="max_tokens" type="number" min="1" value="<?= V::h(
    isset($settings['parameters']['max_tokens']) ? $settings['parameters']['max_tokens'] : 4096
) ?>" <?= $disabled ?>></label></div><label>System prompt<textarea name="system_prompt" rows="6" <?= $disabled ?>><?= V::h(
    $system
) ?></textarea></label>
<label>Assistant name<input name="assistant_name" value="<?= V::h(
    isset($settings['assistant_name']) ? $settings['assistant_name'] : 'PHiLLiP'
) ?>" <?= $disabled ?>></label><label>Response contract (optional JSON)<textarea name="contract" rows="3" class="json-editor" <?= $disabled ?>><?= isset(
    $settings['contract']
)
    ? V::pretty($settings['contract'])
    : '' ?></textarea></label>
<?php if ($t && $owner): ?><div class="grid"><?= V::visibilityField($state, $t['visibility']),
    V::teamField($state, $t['team_id']) ?></div><?php endif; ?><div class="form-error"></div><div class="row end"><?php if (
    $owner
): ?><button type="button" id="save-as-persona">Save prompt as persona</button><button class="primary">Save settings</button><?php else: ?><span class="hint">Only the thread creator can change these settings.</span><?php endif; ?></div></form>
<?php
elseif ($kind === 'edit'):

    if (!$current) {
        throw new RuntimeException('A thread is required');
    }
    Agent\Access::editMessage($user['id'], new Agent\Thread($current['thread']['id']), $id);
    $original = '';
    foreach ($current['messages'] as $message) {
        if ($message['id'] == $id) {
            $original = $message['raw']['content'];
        }
    }
    ?>
<form method="post" id="edit-message" class="form-stack" data-id="<?= $id ?>"><label>Message<textarea name="content" rows="6"><?= V::h(
    $original
) ?></textarea></label><p class="hint">Saving replaces this message and deletes everything after it, including other participants’ later messages.</p><div class="form-error"></div><button class="primary">Replace and send</button></form>
<?php
elseif ($kind === 'claim'):

    $claim = Agent\Knowledge::get($id, $user['id']);
    if ($claim['owner_id'] !== $user['id']) {
        throw new RuntimeException('Only the memory owner may change it');
    }
    ?>
<form method="post" id="claim-edit" class="form-stack" data-id="<?= $id ?>"><label>Claim<textarea name="content" rows="5"><?= V::h(
    $claim['content']
) ?></textarea></label><div class="form-error"></div><button class="primary">Save</button></form>
<?php
endif; ?></div>

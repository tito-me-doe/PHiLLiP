<?php
use Agent\Views as V;
$kind = isset($_GET['kind']) ? $_GET['kind'] : '';
$user = $state['user']; $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$title = 'Settings';
if ($kind === 'profile') { Agent\Access::admin($user['id']); $title = 'Configure model profile'; }
elseif ($kind === 'persona') $title = $id ? 'Edit persona' : 'New persona';
elseif ($kind === 'chat') $title = 'Conversation settings';
elseif ($kind === 'edit') $title = 'Edit your message';
elseif ($kind === 'claim') $title = 'Edit memory';
else throw new InvalidArgumentException('Unknown dialog');
?>
<div class="dialog-head"><h2><?=$title?></h2><button class="icon" data-action="close-dialog" aria-label="Close"><?=V::icon('close')?></button></div><div class="dialog-body">
<?php if ($kind === 'profile'):
$p = !empty($_GET['name']) ? (new Agent\Registry())->profile($_GET['name']) : null;
$config = $p ? Agent\Config::profile($p) : ['url' => 'http://127.0.0.1:8000/v1/chat/completions', 'model' => '', 'temperature' => 0.65, 'stream' => true];
$metadata = $p ? json_decode($p['metadata'], true) : [];
?>
<form method="post" id="profile-form" class="form-stack"><label>Profile name<input name="name" value="<?=V::h($p ? $p['name'] : '')?>" <?=$p ? 'readonly' : ''?> required></label><label>Endpoint, model & request parameters<textarea name="config" class="json-editor" rows="12"><?=V::pretty($config)?></textarea></label><label class="check-label"><input name="enabled" type="checkbox" <?=$p && $p['enabled'] ? 'checked' : ''?>>Enable for users (Save runs capability tests)</label><details class="activity"><summary>Advanced capability overrides</summary><label>Boolean capability overrides<textarea name="overrides" class="json-editor"><?=V::pretty(isset($metadata['overrides']) ? $metadata['overrides'] : (object)[]) ?></textarea></label></details><?php if ($p): ?><details class="activity"><summary>Metadata & test results</summary><pre><?=V::pretty($metadata)?></pre></details><?php endif; ?><p class="hint">Disabled profiles are still available to application code. Enabling sends small inference requests to test the configuration.</p><div class="form-error"></div><button class="primary">Save profile</button></form>
<?php elseif ($kind === 'persona'):
$p = $id ? Agent\Personas::get($id) : ['id' => null, 'name' => '', 'prompt' => '', 'tools' => [], 'contract' => null, 'visibility' => 'private', 'team_id' => null, 'owner_id' => $user['id']];
if ($id) Agent\Access::personaEdit($user['id'], $p);
?>
<form method="post" id="persona-form" class="form-stack" data-id="<?=V::h($p['id'])?>"><label>Name<input name="name" value="<?=V::h($p['name'])?>" required></label><label>System prompt<textarea name="prompt" rows="8" required><?=V::h($p['prompt'])?></textarea></label>
<label>Default tools (optional)<input name="tools" value="<?=V::h(implode(', ', $p['tools']))?>" placeholder="Fetch, SearchWeb"><small>Named tools only. Existing user and team permissions still apply.</small></label>
<label>Response contract (optional JSON)<textarea name="contract" class="json-editor" rows="4" placeholder='{"answer":{"type":"string"}}'><?=$p['contract'] !== null ? V::pretty($p['contract']) : ''?></textarea></label>
<div class="grid"><?=V::visibilityField($state, $p['visibility'], false, $p['owner_id'] === $user['id'])?><?=V::teamField($state, $p['team_id'])?></div><div class="form-error"></div><div class="row end"><?php if ($id): ?><button type="button" id="delete-persona" data-id="<?=$id?>">Delete</button><?php endif; ?><button class="primary">Save persona</button></div></form>
<?php elseif ($kind === 'chat'):
$t = $current ? $current['thread'] : null; $owner = !$t || $t['owner_id'] === $user['id']; $disabled = $owner ? '' : 'disabled';
$settings = $t ? $t['settings'] : ['profile' => isset($state['profiles'][0]) ? $state['profiles'][0]['name'] : '', 'tools' => [], 'parameters' => []];
$system = ''; if ($current) foreach ($current['messages'] as $message) if ($message['role'] === 'system') $system = $message['raw']['content'];
?>
<form method="post" id="chat-settings-form" class="form-stack"><label>Model profile<select name="profile" <?=$disabled?>><?php foreach ($state['profiles'] as $profile): ?><option value="<?=V::h($profile['name'])?>" <?=$settings['profile'] === $profile['name'] ? 'selected' : ''?>><?=V::h($profile['name'] . ' · ' . $profile['model'])?></option><?php endforeach; ?></select></label><div><label>Agent tools</label><div class="grid"><?php foreach ($state['tools'] as $tool): ?><label class="check-label"><input type="checkbox" name="tools" value="<?=V::h($tool)?>" <?=in_array($tool, Agent\ToolRegistry::policies($settings['tools']), true) ? 'checked' : ''?> <?=$disabled?>><?=V::h(substr($tool, strrpos($tool, '\\') + 1))?></label><?php endforeach; ?></div></div><div class="grid"><label>Temperature<input name="temperature" type="number" min="0" max="2" step="0.05" value="<?=V::h(isset($settings['parameters']['temperature']) ? $settings['parameters']['temperature'] : 0.65)?>" <?=$disabled?>></label><label>Maximum output tokens<input name="max_tokens" type="number" min="1" value="<?=V::h(isset($settings['parameters']['max_tokens']) ? $settings['parameters']['max_tokens'] : 4096)?>" <?=$disabled?>></label></div><label>System prompt<textarea name="system_prompt" rows="6" <?=$disabled?>><?=V::h($system)?></textarea></label>
<label>Assistant name<input name="assistant_name" value="<?=V::h(isset($settings['assistant_name']) ? $settings['assistant_name'] : 'PHiLLiP')?>" <?=$disabled?>></label><label>Response contract (optional JSON)<textarea name="contract" rows="3" class="json-editor" <?=$disabled?>><?=isset($settings['contract']) ? V::pretty($settings['contract']) : ''?></textarea></label>
<?php if ($t && $owner): ?><div class="grid"><?=V::visibilityField($state, $t['visibility'])?><?=V::teamField($state, $t['team_id'])?></div><?php endif; ?><div class="form-error"></div><div class="row end"><?php if ($owner): ?><button type="button" id="save-as-persona">Save prompt as persona</button><button class="primary">Save settings</button><?php else: ?><span class="hint">Only the thread creator can change these settings.</span><?php endif; ?></div></form>
<?php elseif ($kind === 'edit'):
if (!$current) throw new RuntimeException('A thread is required');
Agent\Access::editMessage($user['id'], new Agent\Thread($current['thread']['id']), $id);
$original = ''; foreach ($current['messages'] as $message) if ($message['id'] == $id) $original = $message['raw']['content'];
?>
<form method="post" id="edit-message" class="form-stack" data-id="<?=$id?>"><label>Message<textarea name="content" rows="6"><?=V::h($original)?></textarea></label><p class="hint">Saving replaces this message and deletes everything after it, including other participants’ later messages.</p><div class="form-error"></div><button class="primary">Replace and send</button></form>
<?php elseif ($kind === 'claim'): $claim = Agent\Knowledge::get($id, $user['id']); if ($claim['owner_id'] !== $user['id']) throw new RuntimeException('Only the memory owner may change it'); ?>
<form method="post" id="claim-edit" class="form-stack" data-id="<?=$id?>"><label>Claim<textarea name="content" rows="5"><?=V::h($claim['content'])?></textarea></label><div class="form-error"></div><button class="primary">Save</button></form>
<?php endif; ?></div>

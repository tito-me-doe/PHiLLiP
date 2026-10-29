<?php
use Agent\Views as V;
$t = $current ? $current['thread'] : null;
$owner = !$t || $t['owner_id'] === $state['user']['id'];
$reference = $t ? $t['settings']['profile'] : (isset($state['profiles'][0]) ? $state['profiles'][0]['name'] : '');
$selected = is_array($reference) ? '' : $reference;
$model = is_array($reference)
    ? (isset($reference['model'])
        ? $reference['model']
        : (isset($reference['body']['model'])
            ? $reference['body']['model']
            : 'CLI profile'))
    : $selected;
foreach ($state['profiles'] as $profile) {
    if ($profile['name'] === $selected) {
        $model = $profile['model'];
    }
}
$initialProgress = '';
if ($current) {
    foreach ($current['runs'] as $run) {
        if (in_array($run['status'], ['queued', 'running'], true)) {
            $initialProgress = 'Work is ' . $run['status'] . '…';
            break;
        }
    }
}
?>
<header class="topbar"><div class="row"><button class="icon mobile-menu" data-action="menu" aria-label="Open navigation"><?= V::icon(
    'menu'
) ?></button><div class="chat-heading"><div class="title" id="chat-title"><?= V::h(
    $t ? (trim($t['title']) !== '' ? $t['title'] : 'New conversation') : 'A fresh conversation'
) ?></div><?php if ($t && $owner): ?><button class="icon title-edit" data-action="rename" aria-label="Rename conversation"><?= V::icon(
    'edit'
) ?></button><form id="rename-form" method="post" hidden><?= V::csrf(
    $state,
    'rename'
) ?><input type="hidden" name="thread_id" value="<?= $t['id'] ?>"><input name="title" value="<?= V::h(
    $t['title']
) ?>" maxlength="160" required aria-label="Conversation title"><button class="primary">Save</button><button type="button" data-action="cancel-rename">Cancel</button></form><?php endif; ?></div></div><div class="top-actions"><?php if(!empty($t['project_id'])): ?><a class="button" href="<?= Agent\Pages::link('projects',['project'=>$t['project_id']]) ?>">Project plan</a><?php endif; ?><span class="model-pill"><?= V::h(
    $model ?: 'Choose a model'
) ?></span><?php if ($t): ?><span class="badge neutral"><?= V::h(
    $t['visibility']
) ?></span><?php endif; ?><button class="icon" data-action="theme" aria-label="Toggle light or dark mode"><?= V::icon(
    'theme'
) ?></button><button class="icon" data-dialog="chat" aria-label="Chat settings"><?= V::icon('gear') ?></button></div></header>
<div class="chat-scroll" id="chat-scroll"><div id="messages" class="<?= $current ? 'messages' : 'welcome' ?>"><?= V::render(
    'messages',
    compact('state', 'current')
) ?></div></div>
<div class="composer-area"><div class="composer-wrap"><div class="progress" id="progress"><?= V::h(
    $initialProgress
) ?></div><form id="message-form" class="composer" enctype="multipart/form-data" method="post" action="<?= V::url() ?>"><?= V::csrf(
    $state,
    'send'
) ?><input type="hidden" name="submission_key" value="<?= bin2hex(random_bytes(16)) ?>"><input type="hidden" name="thread_id" value="<?= $t
    ? $t['id']
    : '' ?>"><div class="attachment-chips" id="attachment-chips"></div><div id="attachment-status" class="hint" role="status"></div><label class="sr-only" for="prompt">Message PHiLLiP</label><textarea id="prompt" name="content" placeholder="A question, a half-formed idea, a rabbit hole…" rows="2"></textarea><div class="composer-bottom"><div class="composer-controls"><button type="button" class="icon" data-action="attach" aria-label="Attach files"><?= V::icon(
    'attach'
) ?></button><input type="file" name="files[]" id="files" class="files-input" multiple><details class="tools-menu"><summary class="icon" aria-label="Agent tools"><?= V::icon(
    'tools'
) ?></summary><div class="tools-panel"><strong>Agent tools</strong><p class="hint">Available for the next message</p><?php
foreach ($state['tools'] as $tool): ?><label class="check-label"><input type="checkbox" data-chat-tool value="<?= V::h(
    $tool
) ?>" <?= in_array($tool, Agent\ToolRegistry::policies($t ? $t['settings']['tools'] : []), true) ? 'checked' : '' ?> <?= $owner
     ? ''
     : 'disabled' ?>><?= V::h(Agent\ToolRegistry::label(substr($tool, strrpos($tool, '\\') + 1))) ?></label><?php endforeach;
if (!$state['tools']): ?><p class="hint">No tools are permitted for this account.</p><?php endif;
?></div></details></div><div class="row"><button type="button" class="icon stop" id="stop-button" data-action="stop" aria-label="Stop agent" hidden><?= V::icon(
    'stop'
) ?></button><button class="primary send" id="send-button" aria-label="Send message" <?= $state['profiles']
    ? ''
    : 'disabled' ?>><?= V::icon(
    'send'
) ?></button></div></div></form><div class="composer-foot"><span id="queue-note">Enter to send · Shift + Enter for a new line</span><span>PHiLLiP can be wrong. Stay curious.</span></div><noscript><style>.files-input{display:block;margin-bottom:8px}</style><p class="hint">Messages run in the background. Refresh this page to see new results; enable JavaScript for streaming and interactive controls.</p></noscript></div></div>
<script type="application/json" data-chat-state><?= V::json([
    'thread' => $t,
    'workflow' => $current ? $current['workflow'] : null,
    'panels' => $current ? $current['panels'] : (object) [],
    'selectedProfile' => $selected
]) ?></script>

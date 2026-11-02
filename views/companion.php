<?php
use Agent\Views as V;
// The companion is the avatar of whoever the person is talking to; PHiLLiP when no conversation is open.
$companionSettings = $current && isset($current['thread']['settings']) && is_array($current['thread']['settings']) ? $current['thread']['settings'] : [];
$companionName = !empty($companionSettings['assistant_name']) && is_string($companionSettings['assistant_name']) ? $companionSettings['assistant_name'] : 'PHiLLiP';
$companionAvatar = !empty($companionSettings['avatar']) && is_string($companionSettings['avatar']) ? $companionSettings['avatar'] : 'terminal';
$companionReactions = !empty($companionSettings['reactions']) && is_array($companionSettings['reactions']) ? $companionSettings['reactions'] : [];
?>
<aside class="chat-companion" data-name="<?= V::h($companionName) ?>" data-reactions="<?= V::h(json_encode((object)$companionReactions, JSON_UNESCAPED_UNICODE)) ?>" aria-label="<?= V::h($companionName) ?> companion">
    <span class="companion-bubble" aria-hidden="true"><?= V::h(isset($companionReactions['resting']) ? $companionReactions['resting'] : 'Right here with you.') ?></span>
    <button class="companion-character" data-action="avatar-react" aria-label="Say hello to <?= V::h($companionName) ?>"><?= V::avatar($state, '', $companionAvatar) ?></button>
    <span class="companion-status" aria-hidden="true"><?= V::h($companionName) ?></span>
</aside>

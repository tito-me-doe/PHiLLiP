<?php
use Agent\Views as V;
Agent\Pages::authorize($state['user']['id'],'appearance');
?>
<section class="card"><h2>A little light, or a little dark?</h2>
<p class="muted">Choose the look that feels comfortable. Saved in this browser for your account.</p>
<div class="row">
<?php foreach (['light'=>'Light','dark'=>'Dark'] as $theme=>$label): ?>
<button data-theme-choice="<?= $theme ?>" aria-pressed="<?= $state['preferences']['theme'] === $theme ? 'true' : 'false' ?>"><?= $label ?></button>
<?php endforeach; ?>
</div></section>
<section class="card"><h2>Your little sidekick</h2>
<p class="muted">A character, not a voice. Quiet company with a few expressions up its sleeve.</p>
<div class="table-wrap"><table class="registry"><thead><tr><th>Character</th><th>Name</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach (['terminal'=>'Little terminal','sprout'=>'Sprout'] as $name=>$label): ?>
<tr><td class="avatar-option avatar-preview"><?= V::avatar($state,'',$name) ?></td><td><?= V::h($label) ?></td>
<td><?= ($state['preferences']['avatar'] ?? 'terminal') === $name ? 'Selected' : '' ?></td>
<td><button data-avatar-choice="<?= $name ?>">Choose</button></td></tr>
<?php endforeach; ?>
</tbody></table></div><p class="hint">Your avatar is independent of your persona. Reduced-motion preferences are respected.</p>
</section>

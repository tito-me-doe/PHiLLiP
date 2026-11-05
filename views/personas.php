<?php
use Agent\Views as V;
$user = $state['user'];
Agent\Pages::authorize($user['id'], 'personas');
$can = array_flip(Agent\Pages::sections($user['id'], 'personas')); // the cards this person may see
$adultOn = !empty($state['adult_personas']);
$characters = array_values(array_filter($state['personas'], function ($p) { return !empty($p['sheet']); }));
// Adult-oriented characters sort last, and read as switched off while the 18+ setting is off.
$isAdult = function ($p) { return ($p['maturity'] ?? 'everyone') === 'adult'; };
usort($characters, function ($a, $b) use ($isAdult) { return (int)$isAdult($a) <=> (int)$isAdult($b); });
$experts = array_values(array_filter($state['personas'], function ($p) { return empty($p['sheet']); }));
// Whoever may edit a persona may also switch it off: administrators for shared and shipped ones, creators for their own.
$mayEdit = function ($p) use ($user) { return (string)$p['owner_id'] === (string)$user['id'] || (!empty($user['is_admin']) && ($p['visibility'] ?? 'private') !== 'private'); };
$switch = function ($p, $editable) use ($state) {
    $on = !empty($p['enabled']);
    if (!$editable) return $on ? '' : '<span class="badge neutral">Off</span>';
    return '<form method="post" class="switch-form" data-persona-enable="' . (int)$p['id'] . '">' . V::csrf($state, 'persona-enable')
        . '<input type="hidden" name="persona_id" value="' . (int)$p['id'] . '"><input type="checkbox" role="switch" name="enabled" value="yes"'
        . ($on ? ' checked' : '') . ' aria-label="' . V::h(($on ? 'Switch off ' : 'Switch on ') . $p['name']) . '"><noscript><button>Save</button></noscript></form>';
};
?>
<?php if (isset($can['personas'])): ?>
<section class="card" id="personas">
<div class="card-head"><h2>Personas</h2><div class="row">
<?php if (!empty($user['is_admin'])): ?>
<form method="post" id="persona-adult-form" class="switch-form"><?= V::csrf($state, 'persona-adult') ?><label class="switch-label">18+<input type="checkbox" role="switch" name="adult" value="yes" <?= $adultOn ? 'checked' : '' ?> aria-label="Allow adult-oriented personas"></label><noscript><button>Save</button></noscript></form>
<?php endif; ?>
<?= V::iconAction('plus', 'New persona', ['data-dialog' => 'persona']) ?></div></div>
<div class="table-wrap"><table class="persona-table registry" id="persona-table"><thead><tr><th></th><th>Persona</th><th>About</th><th class="vis">Visibility</th><th></th></tr></thead><tbody>
<?php foreach ($characters as $persona):
    $adult = ($persona['maturity'] ?? 'everyone') === 'adult';
    $editable = $mayEdit($persona);
?>
<tr<?= empty($persona['enabled']) || ($adult && !$adultOn) ? ' class="is-off"' : '' ?><?= $adult ? ' data-adult' : '' ?>><td class="switch-cell"><?= $switch($persona, $editable) ?></td><td><div class="persona-name-cell"><?= V::avatar($state, 'persona-thumb', !empty($persona['avatar']) ? $persona['avatar'] : 'terminal') ?><span class="persona-name-stack"><strong><?= V::h($persona['name']) ?></strong><span class="persona-meta"><?php if (!empty($persona['pronouns'])): ?><small><?= V::h($persona['pronouns']) ?></small><?php endif; ?><?php if ($adult): ?><span class="persona-badge">18+</span><?php endif; ?></span></span></div></td>
<td><?= V::h(mb_strimwidth($persona['tagline'] ?? '', 0, 160, '…')) ?><details><summary>Full prompt</summary><?= nl2br(V::h($persona['prompt'])) ?></details></td><td class="vis"><?= V::h($persona['visibility']) ?></td>
<td class="actions"><?php if ($adultOn || !$adult): ?><?= V::iconAction('copy', 'Make your own copy of ' . $persona['name'], ['data-persona-copy' => $persona['id']]) ?><?php endif; ?>
<?php if ($editable): ?><?= V::iconAction('edit', 'Edit persona ' . $persona['name'], ['data-dialog' => 'persona', 'data-id' => $persona['id']]) ?><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></section>

<?php endif; if (isset($can['experts'])): ?>
<section class="card" id="experts">
<div class="card-head"><h2>Experts</h2><?= V::iconAction('plus', 'New expert', ['data-dialog' => 'expert']) ?></div>
<div class="table-wrap"><table class="persona-table registry"><thead><tr><th></th><th>Expert</th><th>Instructions</th><th>Tools</th><th class="vis">Visibility</th><th></th></tr></thead><tbody>
<?php foreach ($experts as $expert): $editable = $mayEdit($expert); ?>
<tr<?= empty($expert['enabled']) ? ' class="is-off"' : '' ?>><td class="switch-cell"><?= $switch($expert, $editable) ?></td><td><strong><?= V::h($expert['name']) ?></strong></td>
<td><?= V::h(mb_strimwidth($expert['prompt'], 0, 140, '…')) ?><details><summary>Full instructions</summary><?= nl2br(V::h($expert['prompt'])) ?></details></td>
<td><?= V::h(implode(', ', array_map(function ($tool) { return Agent\ToolRegistry::label(substr($tool, strrpos($tool, '\\') + 1)); }, $expert['tools'] ?? [])) ?: '—') ?></td>
<td class="vis"><?= V::h($expert['visibility']) ?></td>
<td class="actions"><?php if ($editable): ?><?= V::iconAction('edit', 'Edit expert ' . $expert['name'], ['data-dialog' => 'expert', 'data-id' => $expert['id']]) ?><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$experts): ?><tr><td colspan="6" class="empty">No experts yet.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php endif; ?>

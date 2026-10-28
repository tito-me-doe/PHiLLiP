<?php use Agent\Views as V; ?>
<?php if (!$state['installed'] || (!empty($state['onboarding']) && $state['user'])): ?>
<?=V::render('setup', ['state' => $state])?>
<?php elseif (!$state['user']): ?>
<?=V::render('login', ['state' => $state])?>
<?php else: ?>
<?=V::render('shell', ['state' => $state])?>
<?php endif; ?>
<script type="application/json" id="initial-state"><?=V::json($state)?></script>

<?php use Agent\Views as V; ?>
<main class="login"><div class="login-card">
<?=V::avatar($state, '', 'terminal')?>
<div class="brand">PHiLL<i>i</i>P</div><p>A little intelligence. A lot of character.</p>
<?php if (!empty($state['onboarding'])): ?><p class="hint">Welcome! Use the administrator username and temporary password printed by <code>./bin/install</code>. We'll replace that password first.</p><?php endif; ?>
<?php if ($state['auth_mode'] === 'erp'): ?>
<div class="error-panel">Sign in through your ERP application to use PHiLLiP.</div>
<?php else: ?>
<form id="login-form" class="form-stack" method="post" action="<?=V::url()?>"><?=V::csrf($state, 'login')?><label>Username<input name="username" autocomplete="username" required autofocus></label><label>Password<input name="password" type="password" autocomplete="current-password" required></label><div id="login-error"></div><button class="primary" type="submit">Make yourself at home →</button></form>
<?php endif; ?>
<p class="hint" style="margin:20px 0 0">Your models. Your conversations. Your space.</p></div></main>

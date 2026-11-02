<?php
use Agent\Views as V;
$installation = Agent\Installation::get();
$stage = isset($installation['onboarding']['stage']) ? $installation['onboarding']['stage'] : 'models';
$steps = [
    'password' => 'Password',
    'configuration' => 'Installation',
    'models' => 'Models'
];
?>
<main class="setup setup-wizard"><header class="setup-heading"><div class="brand">PHiLL<i>i</i>P<span>_</span></div><h1>Set up PHiLLiP</h1></header>
<?php if (!$installation): ?>
<section class="card"><h2>Run the installer</h2><p>Run <code>./bin/install</code> in the PHiLLiP directory, then refresh.</p><a class="button" href="<?= V::url() ?>">Refresh</a></section>
<?php else: ?>
<nav class="setup-steps" aria-label="Setup progress"><?php foreach ($steps as $key => $label): ?><span class="<?= $stage === $key
    ? 'current'
    : '' ?>" <?= $stage === $key ? 'aria-current="step"' : '' ?>><?= V::h($label) ?></span><?php endforeach; ?></nav>
<?php if ($stage === 'password'): ?>
<section class="card setup-card"><h2>Choose a password</h2><p class="muted">This replaces the temporary one.</p>
<form method="post" class="form-stack" data-setup-api="password" action="<?= V::url() ?>"><?= V::csrf($state, 'setup-password') ?>
<label data-help="Choose a unique password of 12 to 72 characters. A password manager is an excellent place to keep it. Your temporary password will stop working immediately.">New password<input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required autofocus></label>
<label data-help="One more time, so a typo doesn't become your new password.">Confirm password<input type="password" name="confirmation" minlength="12" maxlength="72" autocomplete="new-password" required></label><div class="form-error" role="alert"></div><button class="primary" type="submit">Save my password →</button></form></section>
<?php elseif ($stage === 'configuration'): ?>
<form method="post" id="setup-form" class="form-stack" action="<?= V::url() ?>"><?= V::csrf($state, 'setup-configure') ?>
<section class="card setup-card"><h2>Installation</h2>
<label data-help="Standalone works immediately with your new administrator account. Choose integration only when connecting PHiLLiP to an existing application's database or session.">Installation type<select name="existing"><option value="no">Standalone · recommended</option><option value="yes">Connect an existing application</option></select></label>
<div id="existing-db" class="form-stack" hidden><label data-help="This is the PDO connection string for your existing application. PHiLLiP keeps its own internal PostgreSQL database.">PDO DSN<input name="dsn" placeholder="mysql:host=erp;dbname=business"></label><div class="grid"><label>Database username<input name="db_username" autocomplete="off"></label><label>Database password<input name="db_password" type="password" autocomplete="off"></label></div><button type="button" id="inspect-db">Connect & list tables</button><div id="db-error" role="alert"></div><div id="mapping-fields">
<?php foreach (Agent\Mappings::COLUMNS as $entity => $columns): ?><div class="mapping-card"><h3><?= V::h(
    ucfirst($entity)
) ?></h3><label data-help="Map this entity to an existing table or keep PHiLLiP's own table. Each entity is independent; mappings default to read-only.">Source table<select data-map="<?= $entity ?>"><option value="">Create PHiLLiP table</option></select></label><div id="columns-<?= $entity ?>"></div></div><?php endforeach; ?></div><label data-help="Only needed when mapping an existing users table with standalone login. Verify the existing account that should become PHiLLiP's administrator; its password is not saved in setup settings.">Existing administrator username<input name="mapped_username" autocomplete="username"></label><label>Existing administrator password<input name="mapped_password" type="password" autocomplete="current-password"></label></div></section>
<section class="card setup-card form-stack"><h2>Personas</h2>
<label data-help="Applies to everyone on this installation. Turn it off for a shared or workplace setup. An administrator can change it later on the Personas page.">Adult-oriented personas<select name="adult_personas"><option value="no">Off · recommended for shared or workplace installations</option><option value="yes">On · available to everyone here</option></select></label></section>
<section class="card setup-card form-stack"><h2>Sign-in</h2><label data-help="Standalone uses the account you just secured. An ERP session is an advanced integration: keep standalone unless your application already authenticates these users.">Sign-in method<select name="auth"><option value="standalone">PHiLLiP username and password</option><option value="erp">Existing ERP session</option></select></label><div id="erp-fields" class="form-stack" hidden><label>ERP bootstrap file<input name="bootstrap" placeholder="/var/www/erp/session.php"></label><label>Session name<input name="session_name" placeholder="PHPSESSID"></label><label>User ID path in $_SESSION<input name="user_path" value="user_id"></label></div><noscript><p>Standalone setup works without JavaScript. Enable JavaScript to inspect and map an external application's tables.</p></noscript></section><div class="form-error" role="alert"></div><button class="primary" type="submit">Find my models →</button></form>
<?php else:
    $status = Agent\Onboarding::status($state['user']);
    $ranges = array_filter(explode(',', getenv('SCAN_CIDRS') ?: ''));
    ?>
<section class="card setup-card"><h2>Models</h2><p class="muted">Find a model server on your network, or enter its address.</p>
<form method="post" class="form-stack" data-setup-api="scan" action="<?= V::url() ?>"><?= V::csrf($state, 'setup-scan') ?>
<label data-help="Scan only networks you administer or have permission to scan. We inspect engine metadata on common ports; discovery doesn't load models or send conversation data.">Network<select name="network" id="setup-network"><?php foreach (
    $ranges
    as $range
): ?><option value="<?= V::h($range) ?>"><?= V::h(
    $range
) ?></option><?php endforeach; ?><option value="custom">Another network or a single host…</option></select></label><label id="setup-cidr-label" data-help="Use a single IPv4 address or a network such as 192.168.1.0/24. Setup scans are limited to 4096 addresses.">Network or host<input name="cidr" value="<?= V::h(
    $ranges ? reset($ranges) : ''
) ?>" placeholder="192.168.1.0/24" required></label><button type="submit">Discover local models</button><div class="form-error" role="alert"></div></form>
<details class="setup-custom"><summary>I know my model server's address</summary><form method="post" class="form-stack" data-setup-api="endpoint" action="<?= V::url() ?>"><?= V::csrf(
    $state,
    'setup-endpoint'
) ?><label data-help="Enter your engine's HTTP or HTTPS address. In Docker, localhost refers to the container. For an engine on this computer use host.docker.internal, or use the server's LAN address.">Model server<input type="url" name="url" placeholder="http://host.docker.internal:11434" required></label><label data-help="Leave this empty unless your model engine requires an API key. The key is saved with the server-side profile and is never shown in discovery results.">API key · optional<input type="password" name="api_key" autocomplete="off"></label><button type="submit">Find models at this address</button><div class="form-error" role="alert"></div></form></details></section>
<section class="card setup-card"><h2>Default model</h2><form method="post" class="form-stack" data-setup-api="model" action="<?= V::url() ?>"><?= V::csrf(
    $state,
    'setup-model'
) ?><label data-help="Choose a chat model that's running on a reachable engine. We'll test a harmless response and its optional capabilities before making it your default. This can take a few minutes.">Default chat model<select name="profile_id" id="setup-model-select" required><option value="">Choose a discovered model</option><?php foreach (
    $status['profiles']
    as $profile
): ?><option value="<?= V::h($profile['id']) ?>"><?= V::h(
    $profile['model'] . ' · ' . $profile['url']
) ?></option><?php endforeach; ?></select></label><button type="submit">Test & use this model</button><div class="form-error" role="alert"></div></form><div id="setup-progress" role="status" aria-live="polite"><?php foreach (
    $status['operations']
    as $operation
): ?><p><?= V::h(
    ($operation['type'] === 'scan' ? 'Discovery' : 'Model verification') . ' · ' . $operation['status']
) ?></p><?php endforeach; ?></div></section>
<section class="card setup-finish"><span class="badge" id="setup-ready-label"><?= $status['ready']
    ? 'Ready to chat'
    : 'One working model to go' ?></span><h2>Ready to start</h2><form method="post" data-setup-api="finish" action="<?= V::url() ?>"><?= V::csrf(
    $state,
    'setup-finish'
) ?><button class="primary" id="setup-finish" type="submit" <?= $status['ready']
    ? ''
    : 'disabled' ?>>Open my first conversation →</button><div class="form-error" role="alert"></div></form><noscript><a href="<?= V::url() ?>">Refresh discovery and verification status</a></noscript></section>
<?php endif;endif; ?>
<aside class="setup-guide" aria-label="Your setup companion"><?= V::avatar(
    $state,
    '',
    'terminal'
) ?><div class="setup-bubble" id="setup-help" role="status" aria-live="polite">I'm PHiLLiP. I'll explain things as you go. Select a field and we'll take it one step at a time.</div></aside></main>

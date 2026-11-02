<?php
require __DIR__ . '/helpers.php';

use Agent\Requirements;

// Services can also come from config/services.local.json; use an empty file so this test does not depend on the machine.
$empty = tempnam(sys_get_temp_dir(), 'services');
file_put_contents($empty, '{}');
putenv('SERVICE_JSON=' . $empty);
register_shutdown_function(function () use ($empty) { @unlink($empty); });

// Binaries, extensions and malformed needs
check(Requirements::need('binary:sh') === null, 'An installed program satisfies a binary need');
check(strpos((string)Requirements::need('binary:phillip-no-such-program'), 'not installed') !== false, 'A missing program is reported as not installed');
check(Requirements::need('extension:json') === null, 'A loaded extension satisfies an extension need');
check(strpos((string)Requirements::need('extension:phillip_no_such_extension'), 'not loaded') !== false, 'A missing extension is reported as not loaded');
check(strpos((string)Requirements::need('bogus'), 'unknown requirement') !== false, 'A malformed need is reported, never silently satisfied');
$combined = Requirements::check(['binary:sh', 'binary:phillip-no-such-program', 'extension:phillip_no_such_extension']);
check($combined['available'] === false && count($combined['missing']) === 2 && $combined['missing'][0]['need'] === 'binary:phillip-no-such-program', 'check() lists exactly the unmet needs');
check(Requirements::check([]) === ['available' => true, 'missing' => []], 'Nothing required means available');

// Services: unconfigured until configured, and the tool that needs it is not offered
check(strpos((string)Requirements::need('service:searxng'), 'not configured') !== false, 'An unconfigured service is reported as not configured');
check(Requirements::tool('Agent\\Tools\\SearchWeb')['available'] === false, 'A tool that needs an unconfigured service is unavailable');
check(Requirements::tool('Agent\\Tools\\Source')['available'] === true, 'A tool with no declared needs is available');
$registry = new Agent\ToolRegistry();
$registry->register('Agent\\Tools\\SearchWeb');
check($registry->definitions() === [], 'An unavailable tool is not registered, so no model is offered it');
$reflecting = new Agent\ToolRegistry(true);
$reflecting->register('Agent\\Tools\\SearchWeb');
check(count($reflecting->definitions()) === 1, 'Reflection mode registers an unavailable tool so documentation and admin screens can describe it');
check(Requirements::tools()['SearchWeb']['available'] === false && Requirements::tools()['Source']['available'] === true, 'tools() reports every shipped tool class');
check(count(Requirements::unavailableSummary(['Agent\\Tools\\SearchWeb', 'Agent\\Tools\\Source'])) === 1, 'The summary lists only unavailable tools');
$canonical = new ReflectionMethod('Agent\\Rlm', 'canonicalTools');
$canonical->setAccessible(true);
expectException(function () use ($canonical) { $canonical->invoke(null, ['SearchWeb::search']); }, 'unavailable until configured');

Agent\Config::configureServices(['searxng' => ['url' => 'http://127.0.0.1:1']]);
check(Requirements::need('service:searxng') === null, 'A configured service satisfies the need immediately (not cached)');
$registry = new Agent\ToolRegistry();
$registry->register('Agent\\Tools\\SearchWeb');
check(count($registry->definitions()) === 1, 'Once configured the tool is registered and offered');
check($canonical->invoke(null, ['SearchWeb::search'])['tools'] === ['Agent\\Tools\\SearchWeb::search'], 'An expert can be granted a tool once it is available');

// Roles: assigned to an enabled profile
check(strpos((string)Requirements::need('role:classify'), 'no enabled profile') !== false, 'An unassigned role is unavailable');
$profile = Agent\Profiles::save('requirements-fixture', ['url' => 'http://127.0.0.1:1/v1/chat/completions', 'model' => 'requirements-fixture'], false);
Agent\Profiles::setDefault('classify', ['id' => $profile['id']]);
check(Requirements::need('role:classify') !== null, 'A role assigned to a disabled profile is still unavailable');
Agent\Database::execute('UPDATE profiles SET enabled = TRUE WHERE id = ?', [$profile['id']]);
check(Requirements::need('role:classify') === null, 'A role assigned to an enabled profile is available');

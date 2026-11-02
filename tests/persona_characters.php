<?php
require __DIR__ . '/helpers.php';

use Agent\Personas;
use Agent\PersonaSheet;

$sheets = PersonaSheet::all();
$alice = Agent\Identity::authenticate('alice', 'phillip-demo');
$bob = Agent\Identity::authenticate('bob', 'phillip-demo');
$carol = Agent\Identity::authenticate('carol', 'phillip-demo');
$admin = Agent\Identity::authenticate('admin', 'phillip-demo');

// A web request can never install the shipped cast.
expectException(function () { Agent\ToolContext::withValues(['frontend' => true], function () { PersonaSheet::install(); }); }, 'local operator');

// Install from a clean slate: the legacy seed row with the same name is adopted, never duplicated.
Agent\Database::execute('DELETE FROM personas WHERE slug IS NOT NULL OR (owner_id IS NULL AND name = ?)', ['PHiLLiP']);
Agent\Database::execute("INSERT INTO personas (name, prompt, visibility, is_default) VALUES ('PHiLLiP', 'legacy prompt', 'organization', TRUE)");
$first = PersonaSheet::install();
check($first['updated'] === 1 && $first['installed'] === count($sheets) - 1 && $first['kept'] === 0, 'Install adds the cast and adopts the legacy PHiLLiP row: ' . json_encode($first));
check(Agent\Database::row('SELECT COUNT(*) AS c FROM personas WHERE name = ?', ['PHiLLiP'])['c'] === 1, 'PHiLLiP exists exactly once');
$phillip = Agent\Database::row("SELECT * FROM personas WHERE slug = 'phillip'");
check($phillip['prompt'] !== 'legacy prompt' && $phillip['avatar'] === 'terminal' && $phillip['maturity'] === 'everyone' && $phillip['visibility'] === 'organization' && (bool)$phillip['is_default'], 'The adopted row becomes the shipped character');
check(PersonaSheet::install() === ['installed' => 0, 'updated' => 0, 'kept' => count($sheets)], 'Install is idempotent');

// Administrator edits survive a reinstall unless a replace is asked for.
$dave = Agent\Database::row("SELECT id FROM personas WHERE slug = 'dave'");
Personas::save($admin['id'], ['tagline' => 'Edited by an administrator.'], $dave['id']);
check(Personas::get($dave['id'])['tagline'] === 'Edited by an administrator.' && Personas::get($dave['id'])['sheet']['approach'] === $sheets['dave']['approach'], 'Editing a shipped character changes only the submitted fields and keeps the rest of the sheet');
PersonaSheet::install();
check(Personas::get($dave['id'])['tagline'] === 'Edited by an administrator.', 'A normal install keeps administrator edits');
PersonaSheet::install(true);
check(Personas::get($dave['id'])['tagline'] === $sheets['dave']['tagline'], 'install(true) restores the shipped sheet');
expectException(function () use ($alice, $dave) { Personas::save($alice['id'], ['tagline' => 'Hijacked.'], $dave['id']); }, 'Persona edit denied');

// Characters are what the welcome screen offers; instruction-only personas are not.
$adultCount = count(array_filter($sheets, function ($sheet) { return $sheet['maturity'] === 'adult'; }));
check(Personas::adultEnabled() === false, 'Adult-oriented personas are off until someone turns them on');
$offered = Personas::charactersFor($alice['id']);
check(count($offered) === count($sheets) - $adultCount && !in_array('adult', array_column($offered, 'maturity'), true), 'With adult personas off, everyone is offered the rest of the cast');
expectException(function () use ($alice) { (new Agent\Frontend($alice['id']))->persona(Agent\Database::row("SELECT id FROM personas WHERE slug = 'roz'")['id']); }, 'turned off');
expectException(function () use ($alice) { Personas::save($alice['id'], ['name' => 'Spicy', 'tagline' => 't', 'bio' => 'b', 'maturity' => 'adult']); }, 'turned off');
expectException(function () use ($alice) { Personas::duplicate($alice['id'], Agent\Database::row("SELECT id FROM personas WHERE slug = 'roz'")['id']); }, 'turned off');
expectException(function () use ($alice) { Personas::setAdultEnabled(true, $alice['id']); }, 'administrator');
Personas::setAdultEnabled(true, $admin['id']);
check(Personas::adultEnabled() === true && count(Personas::charactersFor($alice['id'])) === count($sheets), 'An administrator can turn adult personas on, and then the whole cast is offered');
check((new Agent\Frontend($alice['id']))->persona(Agent\Database::row("SELECT id FROM personas WHERE slug = 'roz'")['id'])['avatar'] === 'roz', 'An adult persona starts once the installation allows it');
check(!in_array('Thought partner', array_column($offered, 'name'), true), 'Instruction-only personas are not offered as characters');

// Anyone can create their own persona; it is private, composed from the sheet, and others cannot see or edit it.
$juniper = Personas::save($alice['id'], ['name' => 'Juniper', 'pronouns' => 'she/her', 'tagline' => 'A gentle gardener.', 'bio' => 'Tends a rooftop garden and treats problems like seedlings.',
    'voice_summary' => 'Soft and earthy.', 'signature' => "One leaf at a time.\nGrowth is slow.", 'avatar' => 'sprout', 'maturity' => 'adult', 'example1_user' => 'I am stressed', 'example1_reply' => 'Breathe. One small step.']);
check($juniper['visibility'] === 'private' && (string)$juniper['owner_id'] === (string)$alice['id'] && $juniper['avatar'] === 'sprout' && $juniper['maturity'] === 'adult' && $juniper['slug'] === null, 'A created persona is private, owned, and carries its avatar and maturity');
check(strpos($juniper['prompt'], 'Juniper') !== false && strpos($juniper['prompt'], 'One leaf at a time.') !== false && strpos($juniper['prompt'], 'Breathe. One small step.') !== false, 'Its prompt is composed from the sheet');
check(in_array($juniper['id'], array_column(Personas::charactersFor($alice['id']), 'id'), false) && !in_array($juniper['id'], array_column(Personas::charactersFor($bob['id']), 'id'), false), 'Only its creator is offered a private persona');
expectException(function () use ($bob, $juniper) { Personas::save($bob['id'], ['tagline' => 'Mine now.'], $juniper['id']); }, 'belongs to its owner');
$edited = Personas::save($alice['id'], ['tagline' => 'A very gentle gardener.'], $juniper['id']);
check($edited['tagline'] === 'A very gentle gardener.' && $edited['sheet']['bio'] === $juniper['sheet']['bio'] && $edited['sheet']['examples'] === $juniper['sheet']['examples'], 'Editing keeps what was not resubmitted');
expectException(function () use ($alice, $juniper) { Personas::save($alice['id'], ['prompt' => 'Replace everything.'], $juniper['id']); }, 'character sheet');
Personas::delete($alice['id'], $juniper['id']);
expectException(function () use ($juniper) { Personas::get($juniper['id']); }, 'not found');

// Validation explains what is missing.
expectException(function () use ($alice) { Personas::save($alice['id'], ['name' => 'No Tagline', 'bio' => 'x']); }, 'tagline');
expectException(function () use ($alice) { Personas::save($alice['id'], ['name' => 'N', 'tagline' => 't', 'bio' => 'b', 'avatar' => 'nope']); }, 'available avatars');
expectException(function () use ($alice) { Personas::save($alice['id'], ['name' => 'N', 'tagline' => 't', 'bio' => 'b', 'example1_user' => 'only half']); }, 'needs both');
expectException(function () use ($alice) { Personas::save($alice['id'], ['name' => str_repeat('x', 81), 'tagline' => 't', 'bio' => 'b']); }, 'too long');

// A conversation takes the avatar and the prompt rebuilt from the sheet; an instruction-only persona is untouched.
$daveRow = Personas::get($dave['id']);
$settings = Personas::settings($daveRow);
check($settings['avatar'] === 'dave' && $settings['assistant_name'] === 'Dave', 'A conversation carries the persona\'s avatar and name');
check($settings['reactions'] == $sheets['dave']['reactions'] && // jsonb does not keep key order, so compare by content
     $settings['reactions']['resting'] === 'Right here, pal.', 'A conversation carries the persona\'s own companion reactions');
$mine = Personas::duplicate($alice['id'], $dave['id']);
check($mine['name'] === 'Dave (my version)' && $mine['visibility'] === 'private' && (string)$mine['owner_id'] === (string)$alice['id'] && $mine['slug'] === null && $mine['avatar'] === 'dave', 'Customize makes a private copy that is the person\'s own');
check(Personas::save($alice['id'], ['tagline' => 'Dave, but mine.'], $mine['id'])['sheet']['approach'] === $sheets['dave']['approach'], 'The copy is fully editable and keeps the rest of the sheet');
expectException(function () use ($bob, $mine) { Personas::duplicate($bob['id'], $mine['id']); }, 'not found or access denied');
check(Personas::duplicate($alice['id'], Agent\Database::row("SELECT id FROM personas WHERE slug = 'phillip'")['id'])['name'] === 'PHiLLiP (my version)', 'Any visible character can be customized');
expectException(function () use ($alice) { Personas::duplicate($alice['id'], Agent\Database::row("SELECT id FROM personas WHERE name = 'Thought partner'")['id']); }, 'Only characters');
check($settings['system_prompt'] === PersonaSheet::prompt(array_replace($daveRow['sheet'], ['tools' => $daveRow['tools']])), 'The system prompt is rebuilt from the sheet each time');
$legacy = Agent\Database::row("SELECT id FROM personas WHERE name = 'Thought partner'");
check(strpos(Personas::settings(Personas::get($legacy['id']))['system_prompt'], 'Your name is Thought partner.') === 0 && !isset(Personas::settings(Personas::get($legacy['id']))['avatar']), 'An instruction-only persona keeps its old behavior');

// A character always starts: it goes without the tools this account may not use (and the prompt says so).
Agent\Database::execute("DELETE FROM permissions WHERE scope_type = 'team' AND scope_id = '0' AND resource IN (?, ?)", ['Agent\\Tools\\SearchWeb', 'Agent\\Tools\\Fetch']);
Agent\Database::execute("DELETE FROM permissions WHERE resource IN (?, ?)", ['Agent\\Tools\\SearchWeb', 'Agent\\Tools\\Fetch']);
$without = (new Agent\Frontend($carol['id']))->persona($dave['id']);
check($without['tools'] === [] && $without['persona_defaults']['tools'] === [] && strpos($without['system_prompt'], 'You have no tools') !== false, 'Without permission for its tools a character starts toolless, and knows it');
Agent\Identity::grant('user', $carol['id'], 'Agent\\Tools\\SearchWeb');
Agent\Identity::grant('user', $carol['id'], 'Agent\\Tools\\Fetch');
$with = (new Agent\Frontend($carol['id']))->persona($dave['id']);
check($with['tools'] === ['Agent\\Tools\\SearchWeb', 'Agent\\Tools\\Fetch'] && strpos($with['system_prompt'], 'You can look things up') !== false, 'With permission the character keeps its tools');
$strict = Personas::save($admin['id'], ['name' => 'Wants Source', 'prompt' => 'Needs a tool.', 'tools' => ['Source'], 'visibility' => 'organization']);
expectException(function () use ($carol, $strict) { (new Agent\Frontend($carol['id']))->persona($strict['id']); }, 'not permitted');

// Switching a persona off: only whoever may edit it can, and nobody can start a conversation with it while it is off.
expectException(function () use ($alice, $dave) { Personas::setEnabled($alice['id'], $dave['id'], false); }, 'Persona edit denied');
$off = Personas::setEnabled($admin['id'], $dave['id'], false);
check($off['enabled'] === false, 'An administrator can switch a shipped persona off');
check(!in_array($dave['id'], array_column(Personas::charactersFor($alice['id']), 'id'), false), 'A switched-off persona is no longer offered');
expectException(function () use ($alice, $dave) { (new Agent\Frontend($alice['id']))->persona($dave['id']); }, 'switched off');
$mine = Personas::save($alice['id'], ['name' => 'Switchable', 'tagline' => 't', 'bio' => 'b']);
check(Personas::setEnabled($alice['id'], $mine['id'], false)['enabled'] === false, 'People can switch off their own persona');
Personas::setEnabled($admin['id'], $dave['id'], true);
check(Personas::get($dave['id'])['enabled'] === true, 'Switching it back on restores it');
echo "ALL PASS\n";

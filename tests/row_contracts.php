<?php
require __DIR__ . '/helpers.php';

$alice = Agent\Identity::authenticate('alice', 'phillip-demo');
$bob = Agent\Identity::authenticate(['username' => 'bob', 'password' => 'phillip-demo']);
check(Agent\Identity::user('alice') === $alice && Agent\Identity::user($alice['id']) === $alice, 'User IDs and unique usernames resolve to the same canonical public row');
check(Agent\Identity::user($alice) === $alice && Agent\Identity::user((object)$alice) === $alice, 'User rows and row objects resolve by authoritative ID');
check(Agent\Identity::user(json_encode(['username' => 'alice'])) === $alice, 'User reference JSON is interpreted centrally');
check(Agent\Identity::user(' alice ') === $alice && Agent\Identity::authenticate(' alice ', 'phillip-demo') === $alice, 'Username boundary whitespace is tolerated when no exact legacy username exists');
$forged = $alice; $forged['is_admin'] = true; $forged['active'] = false; $forged['username'] = 'admin';
check(Agent\Identity::user($forged) === $alice, 'Caller-supplied user privilege and username fields never override database state');
$public = Agent\Identity::users(['username' => 'alice']);
check($public === [$alice] && !array_key_exists('password_hash', $public[0]), 'Bulk users share public-row normalization without exposing hashes');
expectException(function () { Agent\Identity::authenticate(['username' => 'alice', 'password_hash' => 'not-a-password']); }, 'username and password');
expectException(function () { Agent\Identity::authenticate(['username' => 'alice', 'password' => 'incorrect']); }, 'Invalid username');
expectException(function () { Agent\Identity::user(['active' => true]); }, 'id or username');
expectException(function () { Agent\Identity::user(true); }, 'id, username, or row');
expectException(function () { Agent\Identity::user(['id' => [], 'username' => 'admin']); }, 'id must be text');
expectException(function () { Agent\Identity::user('{broken'); }, 'reference JSON');
$options = ['title' => 'Options fixture', 'settings' => ['manual' => true], 'visibility' => 'team', 'team_id' => 'engineering', 'creation_key' => str_repeat('a', 32)];
check(Agent\Thread::options($options) === Agent\Thread::options('Options fixture', ['manual' => true], 'team', 'engineering', str_repeat('a', 32)), 'Thread creation payload and positional options share one pure canonical contract');
check(Agent\Thread::options((object)$options) === Agent\Thread::options($options), 'Row-like thread options share payload normalization');
check(Agent\Thread::options()['settings'] === ['profile' => 'default', 'parameters' => [], 'tools' => []], 'Thread options apply shared settings defaults without creating a thread');

$thread = null; $persona = null; $created = null; $collision = null; $legacy = null;
try {
    $thread = Agent\Thread::create($alice['id'], ['title' => 'Row contract fixture', 'settings' => ['manual' => true]]);
    $row = $thread->get();
    foreach ([$thread->id, (string)$thread->id, ' ' . $thread->id . ' ', $row, (object)$row, json_encode($row), $thread] as $input) {
        check((new Agent\Thread($input))->get() === $row, 'Thread references resolve the same persisted row');
    }
    $forged = $row; $forged['visibility'] = 'organization'; $forged['owner_id'] = $bob['id']; $forged['settings'] = ['tools' => ['forged']];
    check((new Agent\Thread($forged))->get() === $row, 'Thread row input cannot replace visibility, ownership, or settings');
    expectException(function () use ($thread, $bob) { Agent\Access::read($bob['id'], new Agent\Thread($thread->get())); }, 'access denied');
    foreach ([true, false, [], 0, -1, '1junk', 1.5, '999999999999999999999999999'] as $input) {
        expectException(function () use ($input) { new Agent\Thread($input); }, 'positive integer id');
    }
    expectException(function () use ($thread) { new Agent\Thread(['title' => $thread->get()['title']]); }, 'positive integer id');
    expectException(function () use ($alice) { Agent\Thread::create($alice['id'], ['title' => 'Invalid'], ['manual' => true]); }, 'either a payload');
    $run = $thread->enqueue($alice['id'], json_encode(['role' => 'assistant', 'content' => 'A queued user message']));
    $thread->append($run['id'], ['role' => 'assistant', 'content' => 'A response']);
    $messages = $thread->messages();
    check($messages[1]['raw']['role'] === 'user' && $messages[1]['raw']['content'] === 'A queued user message', 'Thread accepts message JSON while enforcing submitted user role');

    $persona = Agent\Personas::save($alice['id'], ['name' => 'Row contract fixture', 'prompt' => 'Be concise.']);
    check(Agent\Personas::get($persona) === $persona, 'Persona rows resolve by ID without trusting caller fields');
    check(Agent\Personas::get(' ' . $persona['id'] . ' ') === $persona, 'Persona numeric reference whitespace is normalized without changing its identity');
    $edited = Agent\Personas::save($alice['id'], ['name' => 'Row contract fixture updated', 'prompt' => 'Be kind.'], $persona);
    check($edited['id'] === $persona['id'] && $edited['prompt'] === 'Be kind.', 'Persona updates accept canonical row references');
    expectException(function () use ($bob, $persona) { Agent\Personas::delete($bob['id'], $persona); }, 'Private persona');
    expectException(function () { Agent\Personas::get('Row contract fixture updated'); }, 'positive integer id');

    $name = 'contracts-' . bin2hex(random_bytes(6));
    $created = Agent\Identity::createUser(['username' => ' ' . $name . ' ', 'password' => 'contract-password', 'is_admin' => 'false']);
    check($created['username'] === $name, 'New usernames normalize boundary whitespace centrally');
    check($created['display_name'] === $created['username'] && !$created['is_admin'], 'User payload supplies sensible display-name default and explicit boolean normalization');
    Agent\Identity::grant('user', $created['id'], 'Agent\\Tools\\Fetch', 'false');
    check(!Agent\Access::tool($created['id'], 'Agent\\Tools\\Fetch'), 'Permission contracts interpret the string false as denial, never a truthy grant');
    expectException(function () use ($created) { Agent\Identity::grant('user', $created['id'], 'Agent\\Tools\\Fetch', 'maybe'); }, 'boolean');
    check(!Agent\Access::tool($created['id'], 'Agent\\Tools\\Fetch'), 'Ambiguous permission input leaves the existing denial unchanged');
    check(Agent\Identity::authenticate((object)['username' => $created['username'], 'password' => 'contract-password']) === $created, 'Credentials accept row-like objects without authentication shortcuts');
    $collision = Agent\Identity::createUser(['username' => $created['id'], 'password' => 'collision-password']);
    expectException(function () use ($created) { Agent\Identity::user($created['id']); }, 'Ambiguous user reference');
    check(Agent\Identity::user(['id' => $created['id']]) === $created && Agent\Identity::user(['username' => $created['id']]) === $collision, 'Explicit criteria disambiguate username versus ID collisions');
    check(Agent\Access::authenticated($created['id']) === $created, 'ACL authentication resolves known IDs without username-collision ambiguity');
    expectException(function () use ($created) { Agent\Access::authenticated($created['username']); }, 'Authentication required');
    check(!Agent\Access::visible($created['username'], ['visibility' => 'organization']) && !Agent\Access::tool($created['username'], 'Agent\\Tools\\SearchWeb'), 'Unknown ACL IDs never fall back to usernames');
    expectException(function () use ($alice) { Agent\Access::authenticated(['id' => $alice['id']]); }, 'id must be text');
    $legacy = ' opaque-' . bin2hex(random_bytes(6)) . ' ';
    Agent\Mappings::insert('users', ['id' => $legacy, 'username' => ' alice ', 'password_hash' => password_hash(' password with spaces ', PASSWORD_DEFAULT), 'display_name' => 'Legacy whitespace fixture', 'active' => true, 'is_admin' => false]);
    $legacyUser = Agent\Identity::user(['id' => $legacy]);
    check($legacyUser['id'] === $legacy && Agent\Access::authenticated($legacy) === $legacyUser, 'Opaque ERP IDs preserve meaningful whitespace in lookup and ACLs');
    check(Agent\Identity::user(' alice ') === $legacyUser && Agent\Identity::authenticate(' alice ', ' password with spaces ') === $legacyUser, 'Exact legacy usernames take precedence and passwords are never trimmed');
    expectException(function () { Agent\Identity::authenticate(' alice ', 'password with spaces'); }, 'Invalid username');
} finally {
    if ($thread) $thread->delete();
    if ($persona) Agent\Personas::delete($alice['id'], $persona);
    if ($collision) Agent\Mappings::delete('users', ['id' => $collision['id']]);
    if ($created) {
        Agent\Mappings::delete('permissions', ['scope_type'=>'user','scope_id'=>$created['id']]);
        Agent\Mappings::delete('users', ['id' => $created['id']]);
    }
    if ($legacy) Agent\Mappings::delete('users', ['id' => $legacy]);
}

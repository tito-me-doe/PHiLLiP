<?php
require __DIR__ . '/helpers.php';
$users = [];
foreach (['admin', 'alice', 'bob', 'carol', 'visitor'] as $username) $users[$username] = Agent\Identity::authenticate($username, 'phillip-demo');
expectException(function () { Agent\Identity::authenticate('alice', 'incorrect'); }, 'Invalid username');
$threads = [];
try {
    foreach (['private' => null, 'team' => 'engineering', 'organization' => null] as $visibility => $team) {
        $threads[$visibility] = Agent\Thread::create($users['alice']['id'], 'ACL fixture ' . $visibility, ['manual' => true], $visibility, $team);
    }
    $expected = ['private' => ['alice'], 'team' => ['admin', 'alice', 'bob'], 'organization' => ['admin', 'alice', 'bob', 'carol', 'visitor']];
    foreach ($threads as $visibility => $thread) foreach ($users as $name => $user) {
        check(Agent\Access::visible($user['id'], $thread->get()) === in_array($name, $expected[$visibility]), $visibility . ' visibility for ' . $name);
    }
    expectException(function () use ($users, $threads) { Agent\Access::owner($users['bob']['id'], $threads['team']); }, 'Only the thread creator');
    $first = $threads['team']->enqueue($users['alice']['id'], 'First');
    $threads['team']->enqueue($users['bob']['id'], 'Second');
    $messages = $threads['team']->messages();
    Agent\Access::editMessage($users['alice']['id'], $threads['team'], $messages[1]['id']);
    expectException(function () use ($users, $threads, $messages) { Agent\Access::editMessage($users['admin']['id'], $threads['team'], $messages[1]['id']); }, 'Only the message author');
    expectException(function () use ($users, $threads, $messages) { Agent\Access::editMessage($users['alice']['id'], $threads['team'], $messages[2]['id']); }, 'Only the message author');
    $threads['team']->append($first['id'], ['role' => 'assistant', 'content' => 'Immutable']);
    $messages = $threads['team']->messages();
    foreach ($messages as $message) if ($message['role'] === 'assistant') expectException(function () use ($users, $threads, $message) { Agent\Access::editMessage($users['alice']['id'], $threads['team'], $message['id']); }, 'Only the message author');
    check(Agent\Access::tool($users['alice']['id'], 'Agent\\Tools\\SearchWeb'), 'Team tool permission inherited');
    check(!Agent\Access::tool($users['visitor']['id'], 'Agent\\Tools\\SearchWeb'), 'Unconfigured tool permission defaults to deny');
    check(Agent\Access::tool($users['alice']['id'], 'Agent\\Tools\\BrowseWeb'), 'Explicit user tool permission');
    check(!Agent\Access::tool($users['bob']['id'], 'Agent\\Tools\\BrowseWeb'), 'Explicit user denial');
    check(Agent\Access::tools($users['bob']['id'], $threads['team'], ['Agent\\Tools\\BrowseWeb']) === [], 'Thread tools constrained by owner and sender permissions');
    expectException(function () use ($users) { Agent\Access::personaEdit($users['admin']['id'], ['owner_id' => $users['alice']['id'], 'visibility' => 'private', 'is_default' => false]); }, 'Private persona');
    Agent\Access::personaEdit($users['admin']['id'], ['owner_id' => $users['alice']['id'], 'visibility' => 'team', 'is_default' => false]);
    check(true, 'Administrator may manage shared personas');
    $db = Agent\Database::connection();
    $db->prepare('DELETE FROM memberships WHERE user_id = ? AND team_id = ?')->execute([$users['bob']['id'], 'engineering']);
    check(!Agent\Access::visible($users['bob']['id'], $threads['team']->get()), 'Removing team membership immediately revokes visibility');
    $db->prepare('INSERT INTO memberships (user_id, team_id) VALUES (?, ?)')->execute([$users['bob']['id'], 'engineering']);
} finally { foreach ($threads as $thread) $thread->delete(); }

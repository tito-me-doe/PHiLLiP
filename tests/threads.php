<?php
require __DIR__ . '/helpers.php';
$thread = Agent\Thread::create('fixture-alice', 'Persistence integration', ['manual' => true, 'parameters' => ['temperature' => 0, 'max_tokens' => 512]]);
try {
    $first = $thread->enqueue('fixture-alice', 'Our project code is OAK-731. Reply OK.');
    $second = $thread->enqueue('fixture-bob', 'What is our project code? Reply only with the code.');
    $claimed = Agent\Run::claim($thread->id);
    check($claimed['id'] === $first['id'], 'First queued message claimed first');
    check(Agent\Run::claim($thread->id) === null, 'Only one active run allowed per thread');
    Agent\Run::execute($claimed['id']);
    check(Agent\Run::get($second['id'])['status'] === 'queued', 'Following message remains queued during first turn');
    $reloaded = new Agent\Thread($thread->id);
    $claimed = Agent\Run::claim($thread->id);
    check(strpos(Agent\Run::execute($claimed['id']), 'OAK-731') !== false, 'Reloaded persisted conversation recalls earlier turn');
    $messages = $reloaded->messages();
    check(array_column($messages, 'role') === ['system', 'user', 'assistant', 'user', 'assistant'], 'Queued messages ordered by turn, not insertion time');
    check(isset($messages[1]['raw']['settings']['config']['model']), 'Full resolved settings retained in raw message');
    $q = Agent\Database::connection()->prepare('SELECT COUNT(*) FROM events WHERE run_id = ?');
    $q->execute([$first['id']]);
    check($q->fetchColumn() >= 2, 'Run events persisted in database');
    $original = $messages[1]['id'];
    $edited = $thread->edit($original, 'Our project code is PINE-926. Reply OK.');
    $q = Agent\Database::connection()->prepare('SELECT COUNT(*) FROM messages WHERE id = ? OR run_id = ?');
    $q->execute([$original, $second['id']]);
    check($q->fetchColumn() == 0, 'Editing deletes replaced messages and later participants messages');
    $q = Agent\Database::connection()->prepare('SELECT COUNT(*) FROM events WHERE run_id = ?');
    $q->execute([$first['id']]);
    check($q->fetchColumn() == 0, 'Fork deletion cascades to event references');
    $claimed = Agent\Run::claim($thread->id);
    Agent\Run::execute($claimed['id']);
    check(Agent\Run::get($edited['id'])['status'] === 'completed', 'Edited conversation can execute again');
} finally { $thread->delete(); }

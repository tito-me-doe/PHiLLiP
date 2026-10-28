<?php
require __DIR__ . '/helpers.php';
$alice = Agent\Identity::authenticate('alice', 'phillip-demo'); $bob = Agent\Identity::authenticate('bob', 'phillip-demo');
$key = bin2hex(random_bytes(8));
$counter = Agent\State::open('fixture.concurrent', $key, ['counter' => 0]);
$thread = Agent\Thread::create($alice['id'], 'State fixture', ['manual' => true]);
function stateWriters($mode, $resource, $count, $number = 4)
{
    $processes = [];
    for ($i = 0; $i < $number; $i++) $processes[] = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/state_writer.php') . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($resource) . ' ' . $count,
        [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
    return $processes;
}
try {
    $row = $counter->read(); $counter->patch(['first' => true], $row['revision']);
    expectException(function () use ($counter, $row) { $counter->patch(['first' => false], $row['revision']); }, 'State changed');
    $counter->patch(['other' => true]);
    check($counter->read()['value']['first'] && $counter->read()['value']['other'], 'State patches preserve unrelated keys');
    foreach (stateWriters('increment', $key, 30) as $process) check(proc_close($process) === 0, 'Concurrent state writer completed');
    check($counter->read()['value']['counter'] === 120, 'Optimistic concurrency prevents lost updates across four PHP processes');

    $run = $thread->enqueue($alice['id'], 'State source');
    Agent\Run::claim($thread->id);
    $state = Agent\State::run($run['id']);
    $child = $state->child('agent', $key . '-research', ['task' => 'Future subagent state']);
    check($child->read()['parent_id'] === $state->id && $child->read()['thread_id'] == $thread->id, 'Child state inherits its parent run and thread associations');
    Agent\Run::event($run['id'], ['type' => 'attachment.progress', 'data' => ['message' => 'Preparing attached files']]);
    check($state->read()['value']['phase'] === 'preparing', 'Attachment preparation uses durable workflow state');
    Agent\Run::event($run['id'], ['type' => 'attachment.fallback', 'data' => ['message' => 'Using local OCR']]);
    check($state->read()['value']['progress'] === 'Using local OCR', 'Fallback progress survives browser reloads');
    Agent\Run::event($run['id'], ['type' => 'reasoning.delta', 'data' => ['content' => 'Thinking']]);
    Agent\Run::event($run['id'], ['type' => 'text.delta', 'data' => ['content' => 'Hello ']]);
    $eventId = Agent\Run::event($run['id'], ['type' => 'text.delta', 'data' => ['content' => 'world']]);
    Agent\WorkflowState::apply($run['id'], ['type' => 'text.delta', 'data' => ['content' => 'world']], $eventId);
    check($state->read()['value']['content'] === 'Hello world', 'Durable live output accumulates exactly once');
    check($state->read()['value']['reasoning'] === 'Thinking', 'Reasoning survives independently of browser state');
    $projection = $state->read()['value'];
    Agent\WorkflowState::rebuild($run['id']);
    check($state->read()['value'] === $projection, 'Projection can be rebuilt from durable events without rerunning model or tools');

    $before = Agent\WorkflowState::snapshot($thread->id);
    expectException(function () use ($run) { Agent\State::transaction(function () use ($run) { Agent\Run::event($run['id'], ['type' => 'text.delta', 'data' => ['content' => 'ROLLBACK']]); throw new RuntimeException('rollback marker'); }); }, 'rollback marker');
    check(Agent\WorkflowState::snapshot($thread->id) === $before, 'Events and workflow state roll back together');
    Agent\Database::connection()->beginTransaction();
    Agent\Run::event($run['id'], ['type' => 'text.delta', 'data' => ['content' => 'UNCOMMITTED']]);
    $other = new PDO(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASSWORD'));
    $q = $other->prepare("SELECT value->>'content' FROM states WHERE id = ?"); $q->execute([$state->id]);
    check($q->fetchColumn() === 'Hello world', 'Other connections cannot see uncommitted workflow output');
    Agent\Database::connection()->rollBack();

    $cursor = Agent\WorkflowState::snapshot($thread->id)['event_cursor'];
    $writers = stateWriters('events', $run['id'], 40, 2); $received = []; $until = microtime(true) + 20;
    do {
        $feed = Agent\WorkflowState::feed($thread->id, $cursor);
        foreach ($feed['events'] as $event) { $received[] = $event['id']; $cursor = $event['id']; }
        if (count($received) >= 80) break;
        usleep(10000);
    } while (microtime(true) < $until);
    foreach ($writers as $process) check(proc_close($process) === 0, 'Concurrent event writer completed');
    check(count($received) === 80 && count(array_unique($received)) === 80, 'Concurrent events arrive once without cursor gaps');
    check($feed['state']['event_cursor'] >= $cursor, 'Event feed includes a consistent authoritative workflow snapshot');

    $messageId = $thread->append($run['id'], ['role' => 'assistant', 'content' => 'Hello world', 'reasoning' => 'Thinking']);
    Agent\Database::connection()->prepare("UPDATE runs SET status = 'completed' WHERE id = ?")->execute([$run['id']]);
    Agent\Run::event($run['id'], ['type' => 'run.completed', 'data' => ['content' => 'Hello world']]);
    check($state->read()['value']['content'] === '', 'Completed output lives in messages, not a stale streaming buffer');
    $source = $thread->messages()[1]; $thread->edit($source['id'], 'Replacement state source');
    expectException(function () use ($child) { $child->read(); }, 'no longer exists');
    check(count(Agent\State::thread($thread->id)->children()) >= 1, 'Replacement run has fresh linked state');
    $thread->delete();
    check(Agent\Database::connection()->query("SELECT COUNT(*) FROM states WHERE scope LIKE 'ui.%'")->fetchColumn() == 0, 'UI state is not persisted in the database');
} finally {
    if (Agent\Database::connection()->inTransaction()) Agent\Database::connection()->rollBack();
    Agent\Database::connection()->prepare('DELETE FROM threads WHERE id = ?')->execute([$thread->id]);
    Agent\Database::connection()->prepare('DELETE FROM states WHERE id = ?')->execute([$counter->id]);
}

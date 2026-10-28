<?php
require __DIR__ . '/helpers.php';
$key = bin2hex(random_bytes(16)); $owner = 'submission-fixture-' . $key;
$processes = []; $thread = null;
try {
    for ($i = 0; $i < 4; $i++) {
        $process = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/submission_writer.php') . ' ' . escapeshellarg($owner) . ' ' . escapeshellarg($key),
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $processes[] = [$process, $pipes];
    }
    $results = [];
    foreach ($processes as $item) {
        $output = stream_get_contents($item[1][1]); $error = stream_get_contents($item[1][2]); fclose($item[1][1]); fclose($item[1][2]);
        $exit = proc_close($item[0]);
        check($exit === 0, 'Concurrent submission writer: ' . ($exit === 0 ? 'OK' : $exit . ' ' . $error . ' ' . $output)); $results[] = json_decode($output, true);
    }
    check(count(array_unique(array_column($results, 'thread_id'))) === 1, 'Concurrent creation retries create exactly one thread');
    check(count(array_unique(array_column($results, 'run_id'))) === 1, 'Concurrent message retries create exactly one queued run');
    $thread = new Agent\Thread($results[0]['thread_id']);
    check(count($thread->messages()) === 2, 'Duplicate submissions do not duplicate user messages');
    $run = Agent\Run::claim($thread->id);
    $replay = $thread->enqueue($owner, ['submission_key' => $key, 'content' => 'Submit exactly once']);
    check($replay['id'] === $run['id'] && $replay['status'] === 'running', 'Retry while running returns the same run');
    expectException(function () use ($thread, $owner, $key) { $thread->enqueue($owner, ['content' => 'Different payload', 'submission_key' => $key]); }, 'different message payload');
    expectException(function () use ($owner, $key) { Agent\Thread::create($owner, 'Different title', ['manual' => true], 'private', null, $key); }, 'different thread payload');
    $next = $thread->enqueue('another-author', ['content' => 'Submit exactly once', 'submission_key' => $key]);
    check($next['id'] !== $run['id'], 'Submission keys are scoped by author and thread');
    check(Agent\Run::claim($thread->id) === null, 'Retry and new queued message cannot start a second active run');
    Agent\State::transaction(function () use ($run) {
        Agent\Database::connection()->prepare("UPDATE runs SET status = 'completed' WHERE id = ?")->execute([$run['id']]);
        Agent\Run::event($run['id'], ['type' => 'run.completed', 'data' => ['content' => 'done']]);
    });
    $again = $thread->enqueue($owner, ['content' => 'Submit exactly once', 'submission_key' => $key]);
    check($again['id'] === $run['id'] && $again['status'] === 'completed', 'Retry after completion does not run the agent again');
    check(Agent\Run::claim($thread->id)['id'] === $next['id'], 'Exactly the next distinct submission advances in the queue');
    $alice = Agent\Identity::authenticate('alice', 'phillip-demo');
    expectException(function () use ($alice, $thread) { (new Agent\Frontend($alice['id']))->send($thread->id, ['content' => 'No key']); }, 'submission_key');
    check(Agent\Submission::hash(['a' => 1, 'b' => ['x' => 2]]) === Agent\Submission::hash(['b' => ['x' => 2], 'a' => 1]), 'Fingerprint is stable regardless of object key order');
} finally {
    if ($thread) $thread->delete();
    else Agent\Database::connection()->prepare('DELETE FROM threads WHERE owner_id = ? AND creation_key = ?')->execute([$owner, $key]);
}

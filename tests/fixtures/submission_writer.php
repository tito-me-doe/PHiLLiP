<?php
require dirname(__DIR__, 2) . '/bootstrap.php';
$thread = Agent\Thread::create($argv[1], 'Submission fixture', ['manual' => true], 'private', null, $argv[2]);
$run = $thread->enqueue($argv[1], ['content' => 'Submit exactly once', 'submission_key' => $argv[2]]);
echo json_encode(['thread_id' => $thread->id, 'run_id' => $run['id']]);

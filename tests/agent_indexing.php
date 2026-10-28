<?php
require __DIR__ . '/helpers.php';

// Isolate the claim queue without consuming real pending index jobs or exposing test agents to workers.
$db = Agent\Database::connection();
$db->beginTransaction();
try {
    $db->exec("CREATE TEMPORARY TABLE index_jobs (run_id BIGINT PRIMARY KEY, status TEXT NOT NULL DEFAULT 'queued', updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()) ON COMMIT DROP");
    $profile = ['url' => 'http://127.0.0.1:1/v1/chat/completions', 'model' => 'index-claim-fixture'];
    $agent = Agent\Agents::create('indexing-' . bin2hex(random_bytes(5)), ['team' => 'indexing-fixture-' . bin2hex(random_bytes(5)), 'profile' => $profile]);
    $thread = new Agent\Thread($agent['thread_id']);
    $run = $thread->enqueue($agent['owner_id'], 'Agent history must be indexed even though model execution has its own local worker.');
    Agent\Indexer::enqueue($run['id']);
    $manual = Agent\Thread::create($agent['owner_id'], 'Ordinary manual index fixture', ['profile' => $profile, 'manual' => true]);
    $manualRun = $manual->enqueue($agent['owner_id'], 'Ordinary manually controlled fixture.');
    Agent\Indexer::enqueue($manualRun['id']);
    check($thread->get()['settings']['manual'] === true, 'Managed agent execution stays excluded from the global run worker');
    check((int)Agent\Indexer::claim() === (int)$run['id'], 'The background indexer can claim managed agent conversations despite manual execution');
    check(Agent\Indexer::claim() === false, 'Ordinary manual fixture threads remain excluded from automatic indexing');
} finally { $db->rollBack(); }

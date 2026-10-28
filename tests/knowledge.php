<?php
require __DIR__ . '/helpers.php';
$alice = Agent\Identity::authenticate('alice', 'phillip-demo');
$bob = Agent\Identity::authenticate('bob', 'phillip-demo');
$thread = Agent\Thread::create($alice['id'], 'Knowledge fixture', ['manual' => true]);
$other = Agent\Thread::create($bob['id'], 'Other private fixture', ['manual' => true]);
try {
    $run = $thread->enqueue($alice['id'], 'Alice uses Lantern for inventory. Lantern runs on PostgreSQL.');
    $source = $thread->messages()[1]['id'];
    $first = Agent\Knowledge::remember($thread->id, $alice['id'], 'Alice uses Lantern', 'Alice', [$source], 'uses', 'Lantern');
    Agent\Knowledge::remember($thread->id, $alice['id'], 'Lantern uses PostgreSQL', 'Lantern', [$source], 'uses', 'PostgreSQL');
    check(count(Agent\History::search('Lantern', $thread->id, $alice['id'])) === 1, 'Full-text history search');
    check(Agent\History::search('Lantern', null, $bob['id']) === [], 'Private history hidden from another user');
    check(count(Agent\Knowledge::graph('Alice', $alice['id'], null, 2)) === 2, 'Graph traverses source-backed relationships');
    check(Agent\Knowledge::graph('Alice', $bob['id']) === [], 'Graph traversal respects private sources');
    $context = ['thread_id' => $other->id, 'user_id' => $bob['id'], 'frontend' => true];
    expectException(function () use ($context, $source) { Agent\ToolContext::withValues($context, function () use ($source) { Agent\Tools\History::read($source); }); }, 'not in the current thread');
    $thread->edit($source, 'Alice uses Birch now.');
    check(Agent\Knowledge::recall('Lantern', $alice['id']) === [], 'Deleting last source removes derived claims');
    check(Agent\Knowledge::graph('Alice', $alice['id']) === [], 'Deleting claims removes graph references');
} finally { $thread->delete(); $other->delete(); }

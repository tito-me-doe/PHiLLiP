<?php
require __DIR__ . '/helpers.php';
$alice = Agent\Identity::authenticate('alice', 'phillip-demo');
$bob = Agent\Identity::authenticate('bob', 'phillip-demo');
$thread = Agent\Thread::create($alice['id'], 'Memory tools fixture', ['manual' => true]);
try {
    $run = $thread->enqueue($alice['id'], 'My project is named Lantern.');
    $context = ['thread_id' => $thread->id, 'run_id' => $run['id'], 'user_id' => $alice['id'], 'frontend' => true];
    $saved = Agent\ToolContext::withValues($context, function () {
        return Agent\Tools\Memory::remember('Alice works on Lantern', 'Alice', 'works on', 'Lantern');
    });
    check(count(Agent\ToolContext::withValues($context, function () { return Agent\Tools\Memory::recall('Lantern'); })) === 1, 'Memory callable recalls source-backed knowledge');
    $other = $context; $other['user_id'] = $bob['id'];
    check(Agent\ToolContext::withValues($other, function () { return Agent\Tools\Memory::recall('Lantern'); }) === [], 'Memory callable cannot expose another users private knowledge');
    expectException(function () use ($other, $saved) { Agent\ToolContext::withValues($other, function () use ($saved) { Agent\Tools\Memory::forget((int)$saved['id']); }); }, 'access denied');
    Agent\ToolContext::withValues($context, function () use ($saved) { Agent\Tools\Memory::forget((int)$saved['id']); });
    check(Agent\Knowledge::recall('Lantern', $alice['id']) === [], 'Forget removes the record without a dismissal marker');
    $registry = new Agent\ToolRegistry(); $registry->register('Agent\\Tools\\Memory');
    check(count($registry->definitions()) === 7, 'Memory static entry points reflected as agent tools');
} finally { $thread->delete(); }

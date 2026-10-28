<?php
require __DIR__ . '/helpers.php';
use Agent\Project;
use Agent\Database;
use Agent\ToolContext;

$owner = 'cli:test-workflow-' . bin2hex(random_bytes(5));
$project = null; $personaId = null;
$complete = ['title' => 'Deliverable', 'goal' => 'Produce fixture deliverable', 'requirements' => 'Use fixture context', 'context' => 'Deterministic test without providers', 'gates' => ['Deliverable is verified']];
$context = function ($todo) use (&$project, $owner) {
    $thread = !empty($todo['thread_id']) ? new Agent\Thread($todo['thread_id']) : Agent\Thread::create($owner, 'Workflow task', ['manual' => true]);
    $run = $thread->enqueue($owner, 'Fixed assignment');
    Agent\Run::claim($thread->id);
    Database::execute("UPDATE project_todos SET thread_id = ?, run_id = ?, status = 'working', run_revision = revision WHERE id = ? AND project_id = ?", [$thread->id, $run['id'], $todo['id'], $project->id]);
    return ['thread_id' => $thread->id, 'run_id' => $run['id']];
};
try {
    $project = Project::create($owner, ['title' => 'Workflow test', 'settings' => ['tools' => [], 'project_manual' => true]]);
    $root = $project->addTodo($complete);
    check($root['creator_todo_id'] === null, 'Human-created root has null creator provenance');
    $wf = $project->workflow();
    check($wf->project() === $project, 'workflow() exposes its project');
    expectException(function () use ($wf, $complete) { $wf->addChild($complete); }, 'No execution context');
    expectException(function () use ($wf, $root) { $wf->reviseChild($root['id'], ['title' => 'Changed']); }, 'No execution context');
    expectException(function () use ($wf) { $wf->escalate('Question'); }, 'No execution context');
    check($wf->decisions() === [], 'Project-wide decisions start empty');
    Database::execute("UPDATE projects SET status = 'running' WHERE id = ?", [$project->id]);
    $rootContext = $context($root);
    $globalRevision = $project->get()['revision'];
    $pauseCode = 'require $argv[1]; Agent\\State::transaction(function () use ($argv) { Agent\\Database::row("SELECT id FROM projects WHERE id=? FOR UPDATE", [$argv[2]]); Agent\\Database::execute("UPDATE projects SET status=\'paused\' WHERE id=?", [$argv[2]]); echo "paused\\n"; flush(); usleep(500000); });';
    $pauser = proc_open(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($pauseCode) . ' ' . escapeshellarg(dirname(__DIR__) . '/bootstrap.php') . ' ' . (int)$project->id, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pausePipes);
    try {
        check(trim(fgets($pausePipes[1])) === 'paused', 'Concurrent pause holds the project transaction lock');
        ToolContext::withValues($rootContext, function () use ($wf, $complete) { expectException(function () use ($wf, $complete) { $wf->addChild($complete); }, 'Project is not running'); });
        check(count($project->todos()) === 1, 'A caller waiting for the project lock revalidates after a concurrent pause');
    } finally {
        foreach ($pausePipes as $pipe) fclose($pipe);
        proc_close($pauser);
    }
    Database::execute("UPDATE projects SET status = 'running' WHERE id = ?", [$project->id]);
    $persona = Database::row("INSERT INTO personas (owner_id, name, prompt, visibility) VALUES (?, ?, 'Fixture persona', 'private') RETURNING id", [$owner, $owner]);
    $personaId = $persona['id'];
    $child = ToolContext::withValues($rootContext, function () use ($wf, $project, $complete, $root, $personaId) {
        check((string)$wf->current()['id'] === (string)$root['id'], 'current() resolves the durable working assignment');
        expectException(function () use ($wf) { $wf->addChild(['title' => 'Incomplete']); }, 'goal is required');
        expectException(function () use ($wf, $complete) { $wf->addChild($complete + ['parent_id' => null]); }, 'forced');
        $child = $project->addTodo($complete + ['persona_id' => $personaId]);
        check((string)$child['parent_id'] === (string)$root['id'] && (string)$child['creator_todo_id'] === (string)$root['id'], 'Runtime creation forces direct ancestry and creator provenance');
        expectException(function () use ($project, $root) { $project->updateTodo($root['id'], ['title' => 'Rewrite my assignment']); }, 'direct child');
        expectException(function () use ($wf, $child) { $wf->reviseChild($child['id'], ['gates' => []]); }, 'acceptance gate');
        expectException(function () use ($wf, $child) { $wf->reviseChild($child['id'], ['goal' => '']); }, 'goal is required');
        $wait = $wf->waitForChildren();
        check($wait['waiting'] && $wait['reason'] === 'children', 'waitForChildren durably records yield intent');
        $question = $wf->escalate('Root needs direction', ['observation' => 'Fixture']);
        check($question['target_todo_id'] === null, 'Root escalation targets the human');
        expectException(function () use ($wf, $question) { $wf->answer($question['decision_id'], 'Forged human', 'human'); }, 'human actor');
        return $child;
    });
    $rootDecision = $wf->decisions()[0];
    $answered = $wf->answer($rootDecision['id'], 'Proceed', 'cli:human');
    check($answered['answer'] === 'Proceed' && $answered['answered_by'] === 'cli:human', 'Human answer persists outside runtime context');
    $childContext = $context($child);
    $grandchild = ToolContext::withValues($childContext, function () use ($wf, $complete) { return $wf->addChild($complete); });
    $grandThread = Agent\Thread::create($owner, 'Queued grandchild', ['manual' => true]);
    $grandRun = $grandThread->enqueue($owner, 'Never begin');
    Database::execute("UPDATE project_todos SET thread_id = ?, run_id = ?, status = 'working', run_revision = revision, result = 'Old result' WHERE id = ?", [$grandThread->id, $grandRun['id'], $grandchild['id']]);
    ToolContext::withValues($rootContext, function () use ($wf, $child, $grandchild, $grandRun) {
        expectException(function () use ($wf, $grandchild) { $wf->reviseChild($grandchild['id'], ['title' => 'Forbidden grandchild']); }, 'direct child');
        $stopping = $wf->reviseChild($child['id'], ['context' => 'Revised context', 'persona_id' => null]);
        check($stopping['pending_stop'] && (int)Database::row('SELECT revision FROM project_todos WHERE id = ?', [$child['id']])['revision'] === 1, 'Live descendants stop before assignment revision changes');
        check(Agent\Run::get($grandRun['id'])['status'] === 'stopped', 'Revision cancels queued descendants before mutation');
    });
    Agent\Run::finishStopped($childContext['run_id'], 'Revision stop acknowledged');
    $child = ToolContext::withValues($rootContext, function () use ($wf, $project, $child) {
        Database::execute("UPDATE project_todos SET result = 'Stale parent rollup', evidence = '{\"old\":true}'::jsonb WHERE id = ?", [$wf->current()['id']]);
        $changed = $project->updateTodo($child['id'], ['context' => 'Revised context', 'persona_id' => null]);
        check((int)$changed['revision'] === 2 && $changed['persona_id'] === null, 'Scoped revision increments child generation and honors null persona');
        check($wf->current()['result'] === '' && $wf->current()['evidence'] === [], 'Child revision invalidates active parent result and evidence');
        return $changed;
    });
    $grandAfter = Database::row('SELECT * FROM project_todos WHERE id = ?', [$grandchild['id']]);
    check((int)$grandAfter['revision'] === 2 && $grandAfter['result'] === '' && $grandAfter['run_id'] === null, 'Revision invalidates descendant results and generations');
    check($project->get()['revision'] === $globalRevision && (int)Database::row('SELECT revision FROM project_todos WHERE id = ?', [$root['id']])['revision'] === 1, 'Runtime revision preserves global readiness and parent assignment');
    // Force a worker to claim a queued child while the reviser is acquiring its thread lock.
    $raceChild = ToolContext::withValues($rootContext, function () use ($wf, $complete) { return $wf->addChild($complete); });
    $raceThread = Agent\Thread::create($owner, 'Claim race child', ['manual' => true]);
    $raceRun = $raceThread->enqueue($owner, 'Claim without executing');
    Database::execute("UPDATE project_todos SET thread_id = ?, run_id = ?, status = 'working', run_revision = revision WHERE id = ?", [$raceThread->id, $raceRun['id'], $raceChild['id']]);
    $claimCode = 'require $argv[1]; Agent\\State::transaction(function () use ($argv) { Agent\\Database::row("SELECT id FROM threads WHERE id=? FOR UPDATE", [$argv[2]]); Agent\\Run::claim($argv[2]); echo "claimed\\n"; flush(); usleep(500000); });';
    $claimer = proc_open(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($claimCode) . ' ' . escapeshellarg(dirname(__DIR__) . '/bootstrap.php') . ' ' . (int)$raceThread->id, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $claimPipes);
    try {
        check(trim(fgets($claimPipes[1])) === 'claimed', 'Concurrent worker claims the queued child under its thread lock');
        $pending = ToolContext::withValues($rootContext, function () use ($wf, $raceChild) { return $wf->reviseChild($raceChild['id'], ['context' => 'After claim race']); });
        check($pending['pending_stop'] && (int)Agent\Run::get($raceRun['id'])['stop_level'] > 0 && (int)Database::row('SELECT revision FROM project_todos WHERE id = ?', [$raceChild['id']])['revision'] === 1, 'Revision rechecks run status after the thread lock and stops a concurrent claim before mutation');
    } finally {
        foreach ($claimPipes as $pipe) fclose($pipe);
        proc_close($claimer);
    }
    Agent\Run::finishStopped($raceRun['id'], 'Claim race stop acknowledged');
    ToolContext::withValues($rootContext, function () use ($wf, $raceChild) { $wf->reviseChild($raceChild['id'], ['context' => 'After claim race']); });
    $childContext = $context($child);
    $decision = ToolContext::withValues($childContext, function () use ($wf) { return $wf->escalate('Child revision two needs direction'); });
    ToolContext::withValues($rootContext, function () use ($wf, $decision) {
        check($wf->answer($decision['decision_id'], 'Parent revision one supplies direction')['answer'] !== null, 'Parent and child can answer across independent revisions');
    });
    ToolContext::withValues(array_replace($rootContext, ['todo_revision' => 999]), function () use ($wf, $complete) {
        expectException(function () use ($wf, $complete) { $wf->addChild($complete); }, 'stale');
    });
    $project->pause($owner);
    ToolContext::withValues($rootContext, function () use ($wf, $complete) { expectException(function () use ($wf, $complete) { $wf->addChild($complete); }, 'running'); });
    Agent\Run::finishStopped($rootContext['run_id'], 'Fixture paused');
    Agent\Run::finishStopped($childContext['run_id'], 'Fixture paused');
    $changed = $project->updateTodo($child['id'], ['context' => 'Human revised assignment']);
    check((int)$changed['revision'] === 3 && (int)Database::row('SELECT revision FROM project_todos WHERE id = ?', [$grandchild['id']])['revision'] === 3, 'Human plan revision invalidates subtree generations and history');
    Database::execute("UPDATE project_todos SET status = 'waiting', wait_reason = 'decision' WHERE id = ?", [$root['id']]);
    $snapshot = $project->snapshot();
    check($snapshot['progress']['waiting'] === 1 && isset($snapshot['decisions']) && is_array($snapshot['todos'][0]['readiness']), 'Snapshot includes waiting counts, decisions and decoded readiness');
} finally {
    if ($project) {
        foreach ($project->todos() as $todo) if ($todo['thread_id'] !== null) Database::execute('DELETE FROM threads WHERE id = ? AND owner_id = ?', [$todo['thread_id'], $owner]);
        Database::execute('DELETE FROM threads WHERE id = ? AND owner_id = ?', [$project->get()['thread_id'], $owner]);
    }
    if ($personaId) Database::execute('DELETE FROM personas WHERE id = ? AND owner_id = ?', [$personaId, $owner]);
}

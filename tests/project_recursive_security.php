<?php
require __DIR__ . '/helpers.php';

use Agent\Database;
use Agent\Project;
use Agent\ProjectWorkflow;
use Agent\Run;
use Agent\Thread;
use Agent\ToolContext;

$owner = 'cli:test-' . bin2hex(random_bytes(4));
$capturedThreads = [];
$capturedProjectId = null;
$failures = [];

function record($cond, $msg) {
    global $failures;
    if (!$cond) {
        $failures[] = $msg;
        echo "FAIL: $msg\n";
    } else {
        echo "PASS: $msg\n";
    }
}

function assertThrows($cb, $contains) {
    try { $cb(); return false; } catch (\Throwable $e) {
        return strpos($e->getMessage(), $contains) !== false;
    }
}

// Thread::get() already decodes settings to an array.
function threadSettingsManual($thread)
{
    $settings = $thread->get()['settings'];
    return is_array($settings) && isset($settings['manual']) && $settings['manual'] === true;
}

// Create a working execution context: manual thread, run claimed running, todo marked working
// with revision and run_revision pinned to the current project revision.
function makeWorking($project, $todo, $owner, &$capturedThreads)
{
    $row = $project->get();
    $rev = (int)$row['revision'];
    $thread = Thread::create($owner, 'wf runtime ' . bin2hex(random_bytes(2)), ['manual' => true]);
    $capturedThreads[] = $thread->id;
    $run = Database::row("INSERT INTO runs (thread_id, author_id, settings, status) VALUES (?, ?, '{}'::jsonb, 'queued') RETURNING *", [$thread->id, $owner]);
    $claimed = Run::claim($thread->id);
    record($claimed !== null && (string)$claimed['id'] === (string)$run['id'] && $claimed['status'] === 'running', 'Run::claim transitions queued run to running');
    Database::execute("UPDATE project_todos SET thread_id=?, run_id=?, status='working', revision=?, run_revision=? WHERE id=? AND project_id=?", [$thread->id, $run['id'], $rev, $rev, $todo['id'], $project->id]);
    return ['thread_id' => $thread->id, 'run_id' => $run['id']];
}

function withContext($ctx, $owner, $project, $callback)
{
    return ToolContext::withValues(array_merge($ctx, ['user_id' => $owner, 'project_id' => $project->id]), $callback);
}

try {
    // Fixture: project owned by opaque CLI owner, manual true, root fully specified.
    // All human/sibling fixtures created BEFORE any run is marked working.
    $rootThread = Thread::create($owner, 'Project root ' . $owner, ['manual' => true]);
    $capturedThreads[] = $rootThread->id;
    $project = Project::create($owner, [
        'thread_id' => $rootThread->id,
        'title' => 'Recursive security fixture ' . $owner,
        'settings' => ['manual' => true],
    ]);
    $capturedProjectId = $project->id;
    $wf = new ProjectWorkflow($project);
    record(threadSettingsManual($rootThread), 'Parent thread settings manual true');

    $root = $project->addTodo([
        'title' => 'Root deliverable',
        'goal' => 'Produce the root deliverable',
        'requirements' => 'Root requirements',
        'context' => 'Deterministic local test',
        'gates' => ['Root gate verified'],
    ]);
    $c2 = $project->addTodo([
        'title' => 'Human sibling',
        'goal' => 'g', 'requirements' => 'r', 'context' => 'c',
        'gates' => ['g'], 'parent_id' => $root['id'],
    ]);

    // Mark project running and root working with an actual claimed running run.
    Database::execute("UPDATE projects SET status='running' WHERE id=?", [$project->id]);
    $rootCtx = makeWorking($project, $root, $owner, $capturedThreads);
    $rootRev = (int)$project->get()['revision'];

    // 1. Own runtime child: fully specified, immutable creator provenance.
    $child = null;
    $createOk = withContext($rootCtx, $owner, $project, function () use ($wf, &$child) {
        try {
            $child = $wf->addChild([
                'title' => 'Own child deliverable',
                'goal' => 'Child goal',
                'requirements' => 'Child requirements',
                'context' => 'Child context',
                'gates' => ['Child gate'],
            ]);
            return true;
        } catch (\Throwable $e) {
            echo "addChild threw: " . $e->getMessage() . "\n";
            return false;
        }
    });
    record($createOk && $child !== null
        && $child['creator_todo_id'] === $root['id']
        && $child['parent_id'] === $root['id']
        && $child['status'] === 'pending'
        && is_array($child['gates'])
        && $child['gates'] === ['Child gate'],
        'Own runtime child is fully specified with immutable creator provenance');

    // 2. Own(self)/sibling/human revise denials protect direct-child creator provenance.
    $childCtx = makeWorking($project, $child, $owner, $capturedThreads);
    $denied = 0;
    $deniedOk = withContext($childCtx, $owner, $project, function () use ($wf, $child, $c2, $root, &$denied) {
        foreach ([$child['id'], $c2['id'], $root['id']] as $id) {
            try { $wf->reviseChild($id, ['title' => 'tampered']); } catch (\Throwable $e) { $denied++; }
        }
        return true;
    });
    record($deniedOk && $denied === 3, 'Own/self/sibling/human revise denials protect direct-child creator provenance');

    // 3. Child escalates to immediate parent; target parent answers at current revision.
    $childCtx2 = makeWorking($project, $child, $owner, $capturedThreads);
    $decision = null;
    $escOk = withContext($childCtx2, $owner, $project, function () use ($wf, &$decision) {
        try {
            $decision = $wf->escalate('Parent, decide this.', ['evidence' => 'x']);
            return true;
        } catch (\Throwable $e) {
            echo "escalate threw: " . $e->getMessage() . "\n";
            return false;
        }
    });
    $answered = null;
    $rootCtx2 = makeWorking($project, $root, $owner, $capturedThreads);
    $ansOk = withContext($rootCtx2, $owner, $project, function () use ($wf, $decision, &$answered) {
        try {
            $answered = $wf->answer($decision['decision_id'], 'yes');
            return true;
        } catch (\Throwable $e) {
            echo "answer threw: " . $e->getMessage() . "\n";
            return false;
        }
    });
    $decisionId = is_array($decision) ? ($decision['decision_id'] ?? null) : null;
    $escalatedRow = $decisionId !== null ? Database::row('SELECT todo_revision, target_todo_id FROM project_decisions WHERE id = ?', [$decisionId]) : null;
    $childDbRev = (int)Database::row('SELECT revision FROM project_todos WHERE id = ?', [$child['id']])['revision'];
    $escalated = $escOk && $decision !== null && (int)$decision['target_todo_id'] === (int)$root['id'] && $escalatedRow !== null && (int)$escalatedRow['todo_revision'] === $childDbRev && (int)$escalatedRow['target_todo_id'] === (int)$root['id'];
    record($escalated && $ansOk && $answered !== null && $answered['answer'] === 'yes' && $answered['answered_by'] === (string)$root['id'],
        'Child escalates to immediate parent and the target parent answers at current revision');

    // 4. Root question requires an actual human: runtime task cannot answer a root (target null) decision.
    $rootDecision = Database::row("INSERT INTO project_decisions (project_id, todo_id, target_todo_id, todo_revision, question, evidence) VALUES (?, ?, NULL, ?, 'Root question', '{}'::jsonb) RETURNING *", [$project->id, $root['id'], $rootRev]);
    $runtimeRootDenied = assertThrows(function () use ($wf, $rootCtx2, $owner, $project, $rootDecision) {
        withContext($rootCtx2, $owner, $project, function () use ($wf, $rootDecision) { $wf->answer($rootDecision['id'], 'yes'); return true; });
    }, 'Root decisions require a human actor, not a runtime task');
    $humanRootAnswered = false;
    try {
        $answeredByHuman = $wf->answer($rootDecision['id'], 'yes', $owner);
        $humanRootAnswered = $answeredByHuman !== null && $answeredByHuman['answer'] === 'yes' && $answeredByHuman['answered_by'] === $owner;
    } catch (\Throwable $e) {
        echo "human root answer threw: " . $e->getMessage() . "\n";
    }
    record($runtimeRootDenied && $humanRootAnswered, 'Root question requires an actual human, never a runtime task');

    // 5. Stale node run_revision denied by current() revision barrier.
    $staleCtx = makeWorking($project, $root, $owner, $capturedThreads);
    Database::execute("UPDATE project_todos SET run_revision = ? WHERE id=? AND project_id=?", [(int)$project->get()['revision'] - 1, $root['id'], $project->id]);
    $staleDenied = assertThrows(function () use ($wf, $staleCtx, $owner, $project) {
        withContext($staleCtx, $owner, $project, function () use ($wf) { $wf->current(); return true; });
    }, 'Current task is stale against a newer task revision');
    record($staleDenied, 'Stale node run_revision is denied by the current() revision barrier');

} finally {
    // Cleanup only this fixture's own rows, even on assertion failure.
    foreach ($capturedThreads as $tid) {
        Database::execute('DELETE FROM runs WHERE thread_id = ?', [$tid]);
    }
    if ($capturedProjectId) {
        Database::execute('DELETE FROM project_decisions WHERE project_id = ?', [$capturedProjectId]);
        Database::execute('DELETE FROM project_events WHERE project_id = ?', [$capturedProjectId]);
        Database::execute('DELETE FROM project_todos WHERE project_id = ?', [$capturedProjectId]);
        Database::execute('DELETE FROM projects WHERE id = ?', [$capturedProjectId]);
    }
    foreach ($capturedThreads as $tid) {
        Database::execute('DELETE FROM threads WHERE id = ? AND owner_id = ?', [$tid, $owner]);
    }
}

if ($failures) {
    echo "SECURITY TESTS FAILED (" . count($failures) . ")\n";
    exit(1);
}
echo "SECURITY TESTS COMPLETE\n";

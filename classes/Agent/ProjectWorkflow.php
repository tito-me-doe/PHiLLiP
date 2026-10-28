<?php
namespace Agent;

/** Recursive task-agent domain surface over a Project.
 *
 * Owns ownership, revision barriers, yields, escalations and decisions.
 * Execution scheduling, dispatch and readiness checks remain with ProjectRunner.
 */
class ProjectWorkflow
{
    const MAX_DEPTH = 32;
    const MAX_NODES = 256;
    const MAX_CONTINUATIONS = 64;

    private $project;

    public function __construct(Project $project)
    {
        $this->project = $project;
    }

    public function project()
    {
        return $this->project;
    }

    /** Validate the actual ToolContext thread/run against the current working ToDo and its own revision. */
    public function current()
    {
        $threadId = ToolContext::get('thread_id');
        $runId = ToolContext::get('run_id');
        if (!$threadId || !$runId) {
            throw new \RuntimeException('No execution context');
        }
        $row = Database::row(
            "SELECT * FROM project_todos WHERE project_id = ? AND thread_id = ? AND run_id = ? AND status = 'working' FOR UPDATE",
            [$this->project->id, $threadId, $runId]
        );
        if (!$row) {
            throw new \RuntimeException('No current working task');
        }
        $todo = Project::decodeTodo($row);
        if ((int)$todo['run_revision'] !== (int)$todo['revision']) {
            throw new \RuntimeException('Current task is stale against a newer task revision');
        }
        $run = Database::row('SELECT status, thread_id, settings, stop_level FROM runs WHERE id = ?', [$runId]);
        if (!$run || $run['status'] !== 'running' || (string)$run['thread_id'] !== (string)$threadId || (int)$run['stop_level'] > 0) {
            throw new \RuntimeException('Current run is not running');
        }
        $proj = Database::row('SELECT status FROM projects WHERE id = ?', [$this->project->id]);
        if (!$proj || $proj['status'] !== 'running') {
            throw new \RuntimeException('Project is not running');
        }
        $snapshot = json_decode($run['settings'], true);
        if ((isset($snapshot['project_todo_revision']) && (int)$snapshot['project_todo_revision'] !== (int)$todo['revision'])
            || (ToolContext::get('todo_revision') !== null && (int)ToolContext::get('todo_revision') !== (int)$todo['revision'])) throw new \RuntimeException('Current assignment revision is stale');
        return $todo;
    }

    /** Create a fully specified direct child of the current task. */
    public function addChild($input)
    {
        $todo = array_replace(['title' => '', 'goal' => '', 'requirements' => '', 'context' => '', 'gates' => [], 'persona_id' => null, 'max_attempts' => 3], Project::todoPayload($input));
        return State::transaction(function () use ($todo) {
            Database::row('SELECT * FROM projects WHERE id = ? FOR UPDATE', [$this->project->id]);
            $current = $this->current();
            if (array_key_exists('parent_id', $todo)) {
                if ($todo['parent_id'] === null || (string)$todo['parent_id'] !== (string)$current['id']) throw new \InvalidArgumentException('Parent and provenance are forced by the current task');
                unset($todo['parent_id']);
                Log::writeWarning('Normalized redundant runtime child parent_id to the current task');
            }
            $this->validateTask($todo);
            if ($this->depth($current['id']) + 1 > self::MAX_DEPTH) throw new \InvalidArgumentException('Todo nesting is limited to 32 levels');
            if ($this->nodeCount() >= self::MAX_NODES) {
                throw new \RuntimeException('Project node limit reached');
            }
            $inserted = Database::row(
                "INSERT INTO project_todos
                    (project_id, parent_id, creator_todo_id, title, goal, requirements, context, gates, persona_id, max_attempts,
                     status, revision, run_revision, ready_revision, readiness, wait_reason, continuations)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, 'pending', 1, NULL, NULL, '{}'::jsonb, NULL, 0)
                 RETURNING *",
                [$this->project->id, $current['id'], $current['id'],
                 $todo['title'], $todo['goal'], $todo['requirements'], $todo['context'],
                 State::encode($todo['gates']), $todo['persona_id'], $todo['max_attempts']]
            );
            $this->clearAncestorEvidence($current['id']);
            $this->project->event('todo.created', ['parent_id' => $current['id'], 'creator_todo_id' => $current['id']], $inserted['id']);
            return Project::decodeTodo($inserted);
        });
    }

    /** Revise a direct child created by the current task. Returns {pending_stop:true} when live runs remain. */
    public function reviseChild($id, $changes)
    {
        $id = $this->positiveId($id);
        $changes = Project::todoPayload($changes);
        if (array_key_exists('parent_id', $changes) || array_key_exists('creator_todo_id', $changes)) {
            throw new \InvalidArgumentException('Ancestry and provenance are fixed');
        }
        $patch = Project::todoPayload($changes);
        return State::transaction(function () use ($id, $patch) {
            Database::row('SELECT * FROM projects WHERE id = ? FOR UPDATE', [$this->project->id]);
            $current = $this->current();
            $child = Database::row('SELECT * FROM project_todos WHERE id = ? AND project_id = ? FOR UPDATE', [$id, $this->project->id]);
            if (!$child) {
                throw new \RuntimeException('ToDo not found in this project');
            }
            if ((string)$child['parent_id'] !== (string)$current['id'] || (string)$child['creator_todo_id'] !== (string)$current['id']) {
                throw new \RuntimeException('Only a direct child created by the current task may be revised');
            }
            if (!$patch) return Project::decodeTodo($child);
            $this->validateTask(array_replace(Project::decodeTodo($child), $patch));
            $stop = $this->stopSubtreeRuns($id);
            if ($stop['pending']) {
                return ['pending_stop' => true, 'todo_id' => $id];
            }
            $this->resetSubtree($id, $patch);
            $this->clearAncestorEvidence($current['id']);
            $this->project->event('todo.revised', ['fields' => array_keys($patch)], $id);
            return $this->fetchTodo($id);
        });
    }

    /** Record a yield intent while the current run finishes normally; does not stop it mid-protocol. */
    public function waitForChildren()
    {
        return State::transaction(function () {
        Database::row('SELECT id FROM projects WHERE id = ? FOR UPDATE', [$this->project->id]);
        $current = $this->current();
        $threadId = ToolContext::get('thread_id');
        $runId = ToolContext::get('run_id');
        Database::execute(
            "UPDATE project_todos SET wait_reason = 'children', updated_at = NOW()
             WHERE id = ? AND project_id = ? AND thread_id = ? AND run_id = ? AND status = 'working'",
            [$current['id'], $this->project->id, $threadId, $runId]
        );
        $this->project->event('todo.waiting', ['reason' => 'children'], $current['id']);
        return ['waiting' => true, 'reason' => 'children', 'todo_id' => $current['id']];
        });
    }

    /** Record a current-revision question addressed to the immediate parent (null = root/human). */
    public function escalate($question, array $evidence = [])
    {
        if (!is_string($question) || trim($question) === '' || strlen($question) > 131072 || strpos($question, "\0") !== false || preg_match('//u', $question) !== 1) {
            throw new \InvalidArgumentException('A bounded nonempty question is required');
        }
        $threadId = ToolContext::get('thread_id');
        $runId = ToolContext::get('run_id');
        return State::transaction(function () use ($question, $evidence, $threadId, $runId) {
            Database::row('SELECT * FROM projects WHERE id = ? FOR UPDATE', [$this->project->id]);
            $current = $this->current();
            $parentId = $current['parent_id'];
            $inserted = Database::row(
                "INSERT INTO project_decisions
                    (project_id, todo_id, target_todo_id, todo_revision, question, evidence)
                 VALUES (?, ?, ?, ?, ?, ?::jsonb)
                 RETURNING *",
                [$this->project->id, $current['id'], $parentId, (int)$current['revision'], $question, State::encode($evidence)]
            );
            Database::execute(
                "UPDATE project_todos SET wait_reason = 'decision', updated_at = NOW()
                 WHERE id = ? AND project_id = ? AND thread_id = ? AND run_id = ? AND status = 'working'",
                [$current['id'], $this->project->id, $threadId, $runId]
            );
            $this->project->event('decision.created', ['decision_id' => $inserted['id'], 'target_todo_id' => $parentId], $current['id']);
            return ['decision_id' => (int)$inserted['id'], 'target_todo_id' => $parentId, 'waiting' => true];
        });
    }

    /** Answer a decision: runtime by target parent, human only for target null. */
    public function answer($decisionId, $answer, $actorId = null)
    {
        $decisionId = $this->positiveId($decisionId);
        return State::transaction(function () use ($decisionId, $answer, $actorId) {
            Database::row('SELECT * FROM projects WHERE id = ? FOR UPDATE', [$this->project->id]);
            $decision = Database::row('SELECT * FROM project_decisions WHERE id = ? FOR UPDATE', [$decisionId]);
            if (!$decision || (string)$decision['project_id'] !== (string)$this->project->id) {
                throw new \RuntimeException('Decision not found in this project');
            }
            if ($decision['answer'] !== null) {
                throw new \RuntimeException('Decision already answered');
            }
            if (!is_string($answer) || trim($answer) === '' || strlen($answer) > 131072 || strpos($answer, "\0") !== false || preg_match('//u', $answer) !== 1) {
                throw new \InvalidArgumentException('A bounded nonempty answer is required');
            }
            $todo = $this->fetchTodo($decision['todo_id']);
            if ((int)$decision['todo_revision'] !== (int)$todo['revision']) {
                throw new \RuntimeException('Decision is stale against a newer task revision');
            }
            if ($decision['target_todo_id'] !== null) {
                $current = $this->current();
                if ((string)$current['id'] !== (string)$decision['target_todo_id']) {
                    throw new \RuntimeException('Only the target parent may answer this decision');
                }
                $answeredBy = (string)$current['id'];
            } else {
                if (ToolContext::get('run_id')) {
                    throw new \RuntimeException('Root decisions require a human actor, not a runtime task');
                }
                if (!is_scalar($actorId) || trim((string)$actorId) === '') {
                    throw new \InvalidArgumentException('Root decision requires a human actor');
                }
                $answeredBy = (string)$actorId;
            }
            Database::execute(
                'UPDATE project_decisions SET answer = ?, answered_by = ?, answered_at = NOW() WHERE id = ?',
                [$answer, $answeredBy, $decisionId]
            );
            $this->project->event('decision.answered', ['decision_id' => $decisionId, 'answered_by' => $answeredBy], $decision['todo_id']);
            return $this->decision($decisionId);
        });
    }

    /** Project-wide decisions for authorized human/root planning; task-scoped inbound/outbound in execution context. */
    public function decisions($todoId = null)
    {
        $threadId = ToolContext::get('thread_id');
        $runId = ToolContext::get('run_id');
        if ($threadId && $runId && (string)$threadId !== (string)$this->project->get()['thread_id']) {
            $current = $this->current();
            $inbound = Database::rows(
                'SELECT d.* FROM project_decisions d JOIN project_todos td ON td.id = d.todo_id AND td.project_id = d.project_id AND td.revision = d.todo_revision WHERE d.project_id = ? AND d.target_todo_id = ? ORDER BY d.id',
                [$this->project->id, $current['id']]
            );
            $outbound = Database::rows(
                'SELECT * FROM project_decisions WHERE project_id = ? AND todo_id = ? AND todo_revision = ? ORDER BY id',
                [$this->project->id, $current['id'], $current['revision']]
            );
            return ['inbound' => $this->decodeDecisions($inbound), 'outbound' => $this->decodeDecisions($outbound)];
        }
        $rows = Database::rows('SELECT d.* FROM project_decisions d JOIN project_todos td ON td.id = d.todo_id AND td.project_id = d.project_id AND td.revision = d.todo_revision WHERE d.project_id = ? ORDER BY d.id', [$this->project->id]);
        return $this->decodeDecisions($rows);
    }

    /* ---------------- internals ---------------- */

    private function decision($id)
    {
        $row = Database::row('SELECT * FROM project_decisions WHERE id = ?', [$id]);
        if (!$row) {
            return null;
        }
        $row['evidence'] = json_decode($row['evidence'], true);
        return $row;
    }

    private function decodeDecisions(array $rows)
    {
        foreach ($rows as &$row) {
            $row['evidence'] = json_decode($row['evidence'], true);
        }
        unset($row);
        return $rows;
    }

    private function fetchTodo($id)
    {
        $row = Database::row('SELECT * FROM project_todos WHERE project_id = ? AND id = ?', [$this->project->id, $id]);
        if (!$row) {
            throw new \RuntimeException('ToDo not found in this project');
        }
        return Project::decodeTodo($row);
    }

    private function depth($id)
    {
        $depth = -1;
        $seen = [];
        while ($id !== null && !isset($seen[(string)$id])) {
            $seen[(string)$id] = true;
            $row = Database::row('SELECT parent_id FROM project_todos WHERE id = ? AND project_id = ?', [$id, $this->project->id]);
            if (!$row) {
                break;
            }
            $id = $row['parent_id'];
            if (++$depth > self::MAX_DEPTH) {
                break;
            }
        }
        return $depth;
    }

    private function nodeCount()
    {
        $row = Database::row('SELECT COUNT(*) AS c FROM project_todos WHERE project_id = ?', [$this->project->id]);
        return (int)$row['c'];
    }

    private function subtreeIds($id)
    {
        $rows = Database::rows(
            'WITH RECURSIVE subtree AS (
                SELECT id FROM project_todos WHERE id = ? AND project_id = ?
                UNION ALL SELECT td.id FROM project_todos td JOIN subtree s ON td.parent_id = s.id WHERE td.project_id = ?
             ) SELECT id FROM subtree',
            [$id, $this->project->id, $this->project->id]
        );
        return array_map(function ($r) { return (int)$r['id']; }, $rows);
    }

    /** Issue Run::requestStop for running subtree runs and cancel queued runs under thread lock. No waiting. */
    private function stopSubtreeRuns($id)
    {
        $ids = $this->subtreeIds($id);
        if (!$ids) {
            return ['pending' => false];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $threads = Database::rows(
            "SELECT thread_id FROM project_todos WHERE id IN ($placeholders) AND project_id = ? AND thread_id IS NOT NULL ORDER BY thread_id",
            array_merge($ids, [$this->project->id])
        );
        $anyRunning = false;
        foreach ($threads as $thread) {
          Database::row('SELECT id FROM threads WHERE id = ? FOR UPDATE', [$thread['thread_id']]);
          foreach (Database::rows("SELECT id, status FROM runs WHERE thread_id = ? AND status IN ('queued','running') ORDER BY id", [$thread['thread_id']]) as $run) {
            if ($run['status'] === 'running') {
                $anyRunning = true;
                Run::requestStop((int)$run['id']);
            } elseif ($run['status'] === 'queued') {
                Database::execute(
                    "UPDATE runs SET status = 'stopped', error = 'Cancelled for subtree revision', finished_at = NOW()
                     WHERE id = ? AND status = 'queued'",
                    [(int)$run['id']]
                );
                Run::event($run['id'], ['type' => 'run.stopped', 'data' => ['reason' => 'Cancelled for subtree revision']]);
            }
          }
        }
        return ['pending' => $anyRunning];
    }

    /** Reset changed node and all descendants: pending, revision+1, clear run/evidence/review/result/readiness. */
    private function resetSubtree($id, array $patch)
    {
        $ids = $this->subtreeIds($id);
        if (!$ids) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        Database::execute(
            "UPDATE project_todos
             SET status = 'pending', attempts = 0, result = '', evidence = '{}'::jsonb, review = '{}'::jsonb,
                 run_id = NULL, run_revision = NULL, ready_revision = NULL, readiness = '{}'::jsonb,
                 wait_reason = NULL, continuations = 0, revision = revision + 1, updated_at = NOW()
             WHERE id IN ($placeholders) AND project_id = ?",
            array_merge($ids, [$this->project->id])
        );
        $fields = [];
        $values = [];
        foreach (['title', 'goal', 'requirements', 'context', 'gates', 'persona_id', 'max_attempts'] as $field) {
            if (array_key_exists($field, $patch)) {
                $fields[] = $field . ($field === 'gates' ? ' = ?::jsonb' : ' = ?');
                $values[] = $field === 'gates' ? State::encode($patch[$field]) : $patch[$field];
            }
        }
        if ($fields) {
            $values[] = $id;
            $values[] = $this->project->id;
            Database::execute(
                'UPDATE project_todos SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = ? AND project_id = ?',
                $values
            );
        }
    }

    /** Clear stale evidence/review on ancestors without canceling the current parent's run. */
    private function clearAncestorEvidence($id)
    {
        $seen = [];
        while ($id !== null && !isset($seen[(string)$id])) {
            $seen[(string)$id] = true;
            $row = Database::row('SELECT parent_id FROM project_todos WHERE id = ? AND project_id = ?', [$id, $this->project->id]);
            if (!$row) {
                break;
            }
            Database::execute(
                "UPDATE project_todos SET result = '', evidence = '{}'::jsonb, review = '{}'::jsonb, ready_revision = NULL, readiness = '{}'::jsonb, updated_at = NOW()
                 WHERE id = ? AND project_id = ?",
                [$id, $this->project->id]
            );
            $id = $row['parent_id'];
        }
    }

    private function positiveId($id)
    {
        if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]*$/D', (string)$id) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) throw new \InvalidArgumentException('A positive task or decision id is required');
        return (int)$id;
    }

    private function validateTask(array $todo)
    {
        foreach (['title', 'goal', 'requirements', 'context'] as $field) if (!isset($todo[$field]) || !is_string($todo[$field]) || trim($todo[$field]) === '') throw new \InvalidArgumentException('Todo ' . $field . ' is required');
        if (empty($todo['gates']) || !is_array($todo['gates'])) throw new \InvalidArgumentException('At least one acceptance gate is required');
        if ($todo['persona_id'] !== null && !Database::row('SELECT id FROM personas WHERE id = ?', [$todo['persona_id']])) throw new \InvalidArgumentException('Todo persona does not exist');
    }
}

<?php
namespace Agent;

/** Planning threads own project identity and visibility. Execution state is durable in SQL. */
class Project
{
    public $id;

    public function __construct($input)
    {
        if ($input instanceof self) $input = $input->id;
        if ($input instanceof \stdClass) $input = (array)$input;
        if (is_array($input)) $input = isset($input['id']) ? $input['id'] : null;
        if (is_string($input)) $input = trim($input);
        if ((is_int($input) || is_string($input)) && preg_match('/^[1-9][0-9]*$/D', (string)$input)) {
            $id = filter_var($input, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new \InvalidArgumentException('Invalid project id');
            $this->id = $id;
        } elseif (is_string($input) && $input !== '') {
            $rows = Database::rows('SELECT p.id FROM projects p JOIN threads t ON t.id = p.thread_id WHERE t.title = ? ORDER BY p.id', [$input]);
            if (count($rows) !== 1) throw new \RuntimeException('Project name is missing or ambiguous; use its id');
            $this->id = (int)$rows[0]['id'];
        } else throw new \InvalidArgumentException('Project reference requires an id or exact name');
        $this->get();
    }

    public static function create($ownerId, $options = [])
    {
        $options = Config::object($options, 'Project options');
        $allowed = ['thread_id', 'title', 'settings', 'visibility', 'team_id', 'creation_key'];
        foreach ($options as $key => $value) if (!in_array($key, $allowed, true)) throw new \InvalidArgumentException('Unknown project creation field: ' . $key);
        if (!isset($options['title'])) $options['title'] = 'New project';
        return State::transaction(function () use ($ownerId, $options) {
            if (isset($options['thread_id'])) {
                $thread = new Thread($options['thread_id']);
                if ((string)$thread->get()['owner_id'] !== (string)$ownerId) throw new \RuntimeException('Only the thread owner may create its project');
            } else $thread = Thread::create($ownerId, $options);
            $row = Database::row('INSERT INTO projects (thread_id) VALUES (?) ON CONFLICT (thread_id) DO NOTHING RETURNING id', [$thread->id]);
            if (!$row) $row = Database::row('SELECT id FROM projects WHERE thread_id = ?', [$thread->id]);
            $project = new self($row);
            if (!$project->history()) $project->event('project.created', ['owner_id' => (string)$ownerId]);
            if (Runtime::isCli() && !ToolContext::get('frontend')) Log::writeSuccess('Project created', ['project_id' => $project->id, 'thread_id' => $thread->id]);
            return $project;
        });
    }

    public static function forThread($threadId)
    {
        if (!$threadId) return null;
        $threadId = (new Thread($threadId))->id;
        $row = Database::row('SELECT p.id FROM projects p WHERE p.thread_id = ? OR EXISTS (SELECT 1 FROM project_todos td WHERE td.project_id = p.id AND td.thread_id = ?) LIMIT 1', [$threadId, $threadId]);
        return $row ? new self($row) : null;
    }

    public function get()
    {
        $row = Database::row('SELECT p.*, t.title, t.owner_id, t.visibility, t.team_id, t.settings FROM projects p JOIN threads t ON t.id = p.thread_id WHERE p.id = ?', [$this->id]);
        if (!$row) throw new \RuntimeException('Project not found');
        $row['settings'] = json_decode($row['settings'], true);
        $row['preflight_result'] = json_decode($row['preflight_result'], true);
        $row['revision'] = (int)$row['revision'];
        if ($row['preflight_revision'] !== null) $row['preflight_revision'] = (int)$row['preflight_revision'];
        return $row;
    }

    public function todos()
    {
        return array_map([self::class, 'decodeTodo'], Database::rows('SELECT * FROM project_todos WHERE project_id = ? ORDER BY id', [$this->id]));
    }

    public static function decodeTodo(array $row)
    {
        foreach (['gates', 'evidence', 'review', 'readiness'] as $field) if (isset($row[$field]) && is_string($row[$field])) $row[$field] = json_decode($row[$field], true);
        return $row;
    }

    public function snapshot()
    {
        return State::transaction(function () {
            $project = $this->get(); $todos = $this->todos(); $counts = ['total' => count($todos), 'done' => 0, 'pending' => 0, 'working' => 0, 'waiting' => 0, 'review' => 0, 'blocked' => 0];
            foreach ($todos as $todo) $counts[$todo['status']]++;
            return ['project' => $project, 'todos' => $todos, 'progress' => $counts, 'issues' => $this->validate(), 'events' => $this->history(), 'decisions' => $this->workflow()->decisions()];
        }, true);
    }

    private function lock($editable = false)
    {
        $row = Database::row('SELECT * FROM projects WHERE id = ? FOR UPDATE', [$this->id]);
        if (!$row) throw new \RuntimeException('Project not found');
        if ($editable && !in_array($row['status'], ['planning', 'paused', 'blocked'], true)) throw new \RuntimeException('Pause the project before editing its plan');
        if ($editable) $this->requireInactive();
        return $row;
    }

    private function requireInactive()
    {
        if (Database::row("SELECT td.id FROM project_todos td JOIN runs r ON r.thread_id = td.thread_id WHERE td.project_id = ? AND r.status IN ('queued','running') LIMIT 1", [$this->id])) throw new \RuntimeException('Wait for active project work to stop before editing its plan');
    }

    private function invalidate()
    {
        Database::execute("UPDATE projects SET revision = revision + 1, preflight_status = 'unchecked', preflight_revision = NULL, preflight_result = '{}'::jsonb, preflight_started_at = NULL, updated_at = NOW() WHERE id = ?", [$this->id]);
    }

    /** Thread settings/sharing adapters call this inside the same transaction as their change. */
    public function invalidatePlan()
    {
        return State::transaction(function () {
            $this->lock(true); $this->invalidate();
            $this->event('project.plan_changed');
            return $this->get();
        });
    }

    /** Queue ordinary conversation work to flesh out this plan; only a separate human Start authorizes tasks. */
    public function plan($actorId, $message, $frontend = false)
    {
        if (!is_scalar($actorId) || trim((string)$actorId) === '') throw new \InvalidArgumentException('Planning requires an actor');
        $message = Message::normalize($message);
        if (trim(Message::text($message)) === '') throw new \InvalidArgumentException('A nonempty planning message is required');
        $message['submission_key'] = isset($message['submission_key']) ? Submission::key($message['submission_key']) : 'project-plan-' . bin2hex(random_bytes(16));
        return State::transaction(function () use ($actorId, $message, $frontend) {
            $row = $this->lock();
            // Serialize with Start and the shared thread submission path before testing idempotency.
            Database::row('SELECT id FROM threads WHERE id = ? FOR UPDATE', [$row['thread_id']]);
            $existing = Database::row('SELECT id FROM runs WHERE thread_id = ? AND author_id = ? AND submission_key = ?', [$row['thread_id'], (string)$actorId, $message['submission_key']]);
            if (!$existing && in_array($row['status'], ['planning', 'paused', 'blocked'], true)) $this->invalidatePlan();
            $run = (new Thread($row['thread_id']))->enqueue($actorId, $message, $frontend);
            if (!$existing) $this->event('project.planning_queued', ['run_id' => $run['id'], 'actor_id' => (string)$actorId]);
            return $run;
        });
    }

    private static function payload(array $input)
    {
        $allowed = ['title', 'goal', 'requirements', 'context', 'gates', 'parent_id', 'persona_id', 'max_attempts'];
        foreach ($input as $key => $value) {
            if (!in_array($key, $allowed, true)) throw new \InvalidArgumentException('Unknown or protected todo field: ' . $key);
            if (in_array($key, ['title', 'goal', 'requirements', 'context'], true)) {
                if (!is_string($value) || strlen($value) > 131072 || strpos($value, "\0") !== false || preg_match('//u', $value) !== 1) throw new \InvalidArgumentException('Todo ' . $key . ' must be bounded text');
                $input[$key] = trim($value);
            } elseif ($key === 'gates') {
                if (is_string($value)) $value = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value)), function ($line) { return $line !== ''; }));
                if (!is_array($value) || ($value && array_keys($value) !== range(0, count($value) - 1)) || count($value) > 100) throw new \InvalidArgumentException('Todo gates must be a list of strings');
                foreach ($value as $gate) if (!is_string($gate) || trim($gate) === '' || strlen($gate) > 16384 || strpos($gate, "\0") !== false || preg_match('//u', $gate) !== 1) throw new \InvalidArgumentException('Each todo gate must be nonempty text');
                $input[$key] = array_map('trim', $value);
            } elseif ($key === 'max_attempts') {
                if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]) === false) throw new \InvalidArgumentException('Maximum attempts must be 1 to 10');
                $input[$key] = (int)$value;
            } elseif ($value !== null) {
                if (is_string($value)) $value = trim($value);
                if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string)$value) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) throw new \InvalidArgumentException('Todo ' . $key . ' must be a positive id or null');
                $input[$key] = (int)$value;
            }
        }
        return $input;
    }

    private function checkLinks(array $candidate, $id = null)
    {
        $todos = $this->todos(); $map = [];
        foreach ($todos as $todo) $map[(string)$todo['id']] = $todo;
        $parent = $candidate['parent_id']; $visited = []; $depth = 0;
        while ($parent !== null) {
            if ((string)$parent === (string)$id || isset($visited[(string)$parent])) throw new \InvalidArgumentException('Todo parent links must not form a cycle');
            if (!isset($map[(string)$parent])) throw new \InvalidArgumentException('Todo parent must belong to this project');
            $visited[(string)$parent] = true; $parent = $map[(string)$parent]['parent_id'];
            if (++$depth > 32) throw new \InvalidArgumentException('Todo nesting is limited to 32 levels');
        }
        // Moving an existing subtree must preserve the nesting limit for all descendants too.
        if ($id !== null) foreach ($todos as $todo) {
            $cursor = $todo['parent_id']; $below = 0;
            while ($cursor !== null && isset($map[(string)$cursor])) {
                $below++;
                if ((string)$cursor === (string)$id) { if ($depth + $below > 32) throw new \InvalidArgumentException('Todo nesting is limited to 32 levels'); break; }
                if ($below > 32) throw new \InvalidArgumentException('Todo nesting is limited to 32 levels');
                $cursor = $map[(string)$cursor]['parent_id'];
            }
        }
        if ($candidate['persona_id'] !== null && !Database::row('SELECT id FROM personas WHERE id = ?', [$candidate['persona_id']])) throw new \InvalidArgumentException('Todo persona does not exist');
    }

    public function addTodo($input)
    {
        $input = self::todoPayload($input);
        if ($this->executionCaller()) return $this->workflow()->addChild($input);
        return State::transaction(function () use ($input) {
            $this->lock(true);
            $todo = array_replace(['title' => '', 'goal' => '', 'requirements' => '', 'context' => '', 'gates' => [], 'parent_id' => null, 'persona_id' => null, 'max_attempts' => 3], $input);
            $this->checkLinks($todo);
            $row = Database::row('INSERT INTO project_todos (project_id, parent_id, title, goal, requirements, context, gates, persona_id, max_attempts) VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?) RETURNING *', [$this->id, $todo['parent_id'], $todo['title'], $todo['goal'], $todo['requirements'], $todo['context'], State::encode($todo['gates']), $todo['persona_id'], $todo['max_attempts']]);
            $this->resetAncestors($todo['parent_id']); $this->invalidate();
            $this->event('todo.created', [], $row['id']);
            return self::decodeTodo($row);
        });
    }

    private function todo($id)
    {
        $row = Database::row('SELECT * FROM project_todos WHERE project_id = ? AND id = ?', [$this->id, $id]);
        if (!$row) throw new \RuntimeException('Todo not found in this project');
        return self::decodeTodo($row);
    }

    private function resetAncestors($id)
    {
        $seen = [];
        while ($id !== null && !isset($seen[(string)$id])) {
            $seen[(string)$id] = true; $row = $this->todo($id);
            Database::execute("UPDATE project_todos SET status = 'pending', attempts = 0, result = '', evidence = '{}'::jsonb, review = '{}'::jsonb, revision = revision + 1, run_id = NULL, run_revision = NULL, ready_revision = NULL, readiness = '{}'::jsonb, wait_reason = NULL, continuations = 0, updated_at = NOW() WHERE id = ?", [$id]);
            $id = $row['parent_id'];
        }
    }

    public function updateTodo($id, $input)
    {
        $input = self::todoPayload($input);
        if ($this->executionCaller()) return $this->workflow()->reviseChild($id, $input);
        return State::transaction(function () use ($id, $input) {
            $this->lock(true); $old = $this->todo($id); $todo = array_replace($old, $input); $this->checkLinks($todo, $id);
            if (!$input) return $old;
            if ($old['creator_todo_id'] !== null && (string)$old['parent_id'] !== (string)$todo['parent_id']) throw new \InvalidArgumentException('Runtime child ancestry is fixed');
            Database::execute("UPDATE project_todos SET parent_id = ?, title = ?, goal = ?, requirements = ?, context = ?, gates = ?::jsonb, persona_id = ?, max_attempts = ?, status = 'pending', attempts = 0, result = '', evidence = '{}'::jsonb, review = '{}'::jsonb, updated_at = NOW() WHERE id = ? AND project_id = ?", [$todo['parent_id'], $todo['title'], $todo['goal'], $todo['requirements'], $todo['context'], State::encode($todo['gates']), $todo['persona_id'], $todo['max_attempts'], $id, $this->id]);
            $this->resetSubtree($id);
            $this->resetAncestors($old['parent_id']);
            if ((string)$old['parent_id'] !== (string)$todo['parent_id']) $this->resetAncestors($todo['parent_id']);
            $this->invalidate();
            $this->event('todo.updated', ['fields' => array_keys($input)], $id);
            return $this->todo($id);
        });
    }

    public function removeTodo($id, $descendants = false)
    {
        if ($this->executionCaller()) throw new \RuntimeException('Execution agents may only create or revise their own direct children');
        return State::transaction(function () use ($id, $descendants) {
            $this->lock(true); $todo = $this->todo($id);
            if (!$descendants && Database::row('SELECT id FROM project_todos WHERE project_id = ? AND parent_id = ? LIMIT 1', [$this->id, $id])) throw new \RuntimeException('Removing a parent requires explicit descendant deletion');
            $subtree = Database::rows('WITH RECURSIVE subtree AS (SELECT id, thread_id FROM project_todos WHERE id = ? AND project_id = ? UNION ALL SELECT td.id, td.thread_id FROM project_todos td JOIN subtree s ON td.parent_id = s.id WHERE td.project_id = ?) SELECT * FROM subtree', [$id, $this->id, $this->id]);
            foreach ($subtree as $child) if ($child['thread_id'] !== null) Database::execute('DELETE FROM threads WHERE id = ?', [$child['thread_id']]);
            $this->event('todo.deleted', ['deleted_id' => $id, 'descendants' => (bool)$descendants], $id);
            Database::execute('DELETE FROM project_todos WHERE id = ? AND project_id = ?', [$id, $this->id]);
            $this->resetAncestors($todo['parent_id']); $this->invalidate();
            return ['deleted' => true];
        });
    }

    /** Deleting a project also removes its associated task histories; callers enforce ownership. */
    public function delete()
    {
        if (ToolContext::get('run_id')) throw new \RuntimeException('Only a human may delete a project');
        return State::transaction(function () {
            $row = $this->lock();
            if ($row['status'] === 'running') throw new \RuntimeException('Pause the project before deleting it');
            $this->requireInactive();
            Database::row('SELECT id FROM threads WHERE id = ? FOR UPDATE', [$row['thread_id']]);
            if (Database::row("SELECT id FROM runs WHERE thread_id = ? AND status = 'running' LIMIT 1", [$row['thread_id']])) throw new \RuntimeException('Stop the active planning run before deleting the project');
            foreach ($this->todos() as $todo) if ($todo['thread_id'] !== null) Database::execute('DELETE FROM threads WHERE id = ?', [$todo['thread_id']]);
            Database::execute('DELETE FROM threads WHERE id = ?', [$row['thread_id']]);
            Log::writeDebug('Project and execution histories deleted', ['project_id' => $this->id]);
            return ['deleted' => true];
        });
    }

    public function validate()
    {
        $issues = []; $todos = $this->todos();
        if (!$todos) $issues[] = ['todo_id' => null, 'field' => 'todos', 'message' => 'Add at least one fully specified ToDo'];
        foreach ($todos as $todo) {
            foreach (['title', 'goal', 'requirements', 'context'] as $field) if (!is_string($todo[$field]) || trim($todo[$field]) === '') $issues[] = ['todo_id' => $todo['id'], 'field' => $field, 'message' => 'ToDo ' . $field . ' is required'];
            if (!is_array($todo['gates']) || !$todo['gates']) $issues[] = ['todo_id' => $todo['id'], 'field' => 'gates', 'message' => 'At least one acceptance gate is required'];
            else foreach ($todo['gates'] as $gate) if (!is_string($gate) || trim($gate) === '') { $issues[] = ['todo_id' => $todo['id'], 'field' => 'gates', 'message' => 'Each acceptance gate must be nonempty text']; break; }
        }
        return $issues;
    }

    public function requestPreflight()
    {
        return State::transaction(function () {
            $row = $this->lock(true);
            if (in_array($row['preflight_status'], ['queued', 'checking'], true) && (string)$row['preflight_revision'] === (string)$row['revision']) return $this->get();
            $issues = $this->validate(); $status = $issues ? 'gaps' : 'queued';
            Database::execute('UPDATE projects SET preflight_status = ?, preflight_revision = ?, preflight_result = ?::jsonb, preflight_started_at = NULL, updated_at = NOW() WHERE id = ?', [$status, $row['revision'], State::encode(['issues' => $issues]), $this->id]);
            $this->event('preflight.' . $status, ['revision' => (int)$row['revision'], 'issues' => $issues]);
            return $this->get();
        });
    }

    public function start($actorId)
    {
        if (ToolContext::get('run_id')) throw new \RuntimeException('Only a human may Start a project');
        if (!is_scalar($actorId) || trim((string)$actorId) === '') throw new \InvalidArgumentException('Start requires a human actor');
        return State::transaction(function () use ($actorId) {
            $row = $this->lock(true);
            Database::row('SELECT id FROM threads WHERE id = ? FOR UPDATE', [$row['thread_id']]);
            if (Database::row("SELECT id FROM runs WHERE thread_id = ? AND status IN ('queued','running') LIMIT 1", [$row['thread_id']])) throw new \RuntimeException('Finish queued or running planning conversation work before Start');
            if ($this->validate()) throw new \RuntimeException('Every ToDo requires title, goal, requirements, context, and acceptance gates before Start');
            if ($row['preflight_status'] !== 'ready' || (string)$row['preflight_revision'] !== (string)$row['revision']) throw new \RuntimeException('Current project revision requires a ready preflight before Start');
            Database::execute("UPDATE projects SET status = 'running', started_by = ?, started_at = NOW(), updated_at = NOW() WHERE id = ?", [(string)$actorId, $this->id]);
            $this->event('project.started', ['actor_id' => (string)$actorId, 'revision' => (int)$row['revision']]);
            if (Runtime::isCli() && !ToolContext::get('frontend')) Log::writeSuccess('Project started', ['project_id' => $this->id, 'actor_id' => (string)$actorId]);
            return $this->get();
        });
    }

    public function pause($actorId = null)
    {
        if (ToolContext::get('run_id')) throw new \RuntimeException('Only a human may pause a project');
        return State::transaction(function () use ($actorId) {
            $row = $this->lock();
            if ($row['status'] === 'completed') throw new \RuntimeException('Completed project cannot be paused');
            Database::execute("UPDATE projects SET status = 'paused', updated_at = NOW() WHERE id = ?", [$this->id]);
            foreach (Database::rows("SELECT r.id, r.thread_id FROM runs r JOIN project_todos td ON td.run_id = r.id WHERE td.project_id = ? AND r.status IN ('queued','running')", [$this->id]) as $candidate) {
                // Use the same thread lock as Run::claim so queued cancellation cannot race a worker claim.
                Database::row('SELECT id FROM threads WHERE id = ? FOR UPDATE', [$candidate['thread_id']]);
                $run = Run::get($candidate['id']);
                if ($run['status'] === 'running') Run::requestStop($run['id']);
                elseif ($run['status'] === 'queued') {
                    Database::execute("UPDATE runs SET status = 'stopped', error = 'Project paused before execution', finished_at = NOW() WHERE id = ? AND status = 'queued'", [$run['id']]);
                    Run::event($run['id'], ['type' => 'run.stopped', 'data' => ['reason' => 'Project paused before execution']]);
                }
            }
            $this->event('project.paused', ['actor_id' => $actorId]);
            return $this->get();
        });
    }

    public function retryTodo($id)
    {
        if (ToolContext::get('run_id')) throw new \RuntimeException('Only a human may retry a ToDo');
        return State::transaction(function () use ($id) {
            $this->lock(true); $todo = $this->todo($id);
            if ($todo['status'] !== 'blocked') throw new \RuntimeException('Only a blocked ToDo may be retried');
            $this->resetSubtree($id);
            $this->resetAncestors($todo['parent_id']); $this->invalidate();
            $this->event('todo.retry', [], $id);
            return $this->todo($id);
        });
    }

    public function event($type, array $data = [], $todoId = null)
    {
        $row = Database::row('INSERT INTO project_events (project_id, todo_id, type, data) VALUES (?, ?, ?, ?::jsonb) RETURNING id', [$this->id, $todoId, $type, State::encode((object)$data)]);
        Log::writeDebug('Project event', ['project_id' => $this->id, 'todo_id' => $todoId, 'type' => $type]);
        return (int)$row['id'];
    }

    public function history($after = 0)
    {
        $rows = Database::rows('SELECT * FROM project_events WHERE project_id = ? AND id > ? ORDER BY id', [$this->id, max(0, (int)$after)]);
        foreach ($rows as &$row) $row['data'] = json_decode($row['data'], true);
        return $rows;
    }

    /** Task execution evidence for CLI/TUI inspection; authorization belongs to the caller's boundary. */
    public function activity($todoId = null, $after = 0)
    {
        if (filter_var($after, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) throw new \InvalidArgumentException('Activity cursor must be a nonnegative integer');
        $parameters = [$this->id, (int)$after];
        $where = '';
        if ($todoId !== null) {
            if (filter_var($todoId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) throw new \InvalidArgumentException('Activity ToDo must be a positive ID');
            $this->todo($todoId);
            $where = ' AND td.id = ?'; $parameters[] = (int)$todoId;
        }
        $rows = Database::rows("SELECT e.id, e.run_id, td.id AS todo_id, e.type, e.data FROM events e
            JOIN runs r ON r.id = e.run_id JOIN project_todos td ON td.thread_id = r.thread_id
            WHERE td.project_id = ? AND e.id > ?" . $where . "
            AND e.type IN ('tool.start','tool.result','run.failed','run.stopped','run.completed') ORDER BY e.id LIMIT 100", $parameters);
        foreach ($rows as &$row) $row['data'] = json_decode($row['data'], true);
        unset($row);
        return $rows;
    }
    public function workflow()
    {
        return new ProjectWorkflow($this);
    }

    /** An execution tool cannot use the trusted application adapter to escape its child scope. */
    private function executionCaller()
    {
        return ToolContext::get('run_id') !== null && (string)ToolContext::get('thread_id') !== (string)$this->get()['thread_id'];
    }

    private function resetSubtree($id)
    {
        Database::execute("WITH RECURSIVE subtree AS (
            SELECT id FROM project_todos WHERE id = ? AND project_id = ?
            UNION ALL SELECT td.id FROM project_todos td JOIN subtree s ON td.parent_id = s.id WHERE td.project_id = ?)
            UPDATE project_todos SET status = 'pending', attempts = 0, result = '', evidence = '{}'::jsonb, review = '{}'::jsonb,
            revision = revision + 1, run_id = NULL, run_revision = NULL, ready_revision = NULL, readiness = '{}'::jsonb,
            wait_reason = NULL, continuations = 0, updated_at = NOW() WHERE id IN (SELECT id FROM subtree)", [$id, $this->id, $this->id]);
    }

    /** Normalize known model payload shapes into one strictly validated ToDo; all text remains data. */
    public static function todoPayload($input)
    {
        if (is_string($input)) {
            $decoded = json_decode($input);
            if (json_last_error() === JSON_ERROR_NONE) $input = $decoded;
        }
        if (is_array($input) && $input && array_keys($input) === range(0, count($input) - 1)) {
            if (count($input) !== 1 || (!($input[0] instanceof \stdClass) && !(is_array($input[0]) && $input[0] && array_keys($input[0]) !== range(0, count($input[0]) - 1)))) throw new \InvalidArgumentException('ToDo fields require one named object, not a list; multiple ToDos are ambiguous');
            $input = $input[0];
            Log::writeWarning('Normalized singleton ToDo object list');
        }
        $input = Config::object($input, 'ToDo fields');
        foreach (['requirements', 'context'] as $field) if (array_key_exists($field, $input) && is_array($input[$field])) {
            $items = $input[$field];
            if ($items && array_keys($items) !== range(0, count($items) - 1)) throw new \InvalidArgumentException('Todo ' . $field . ' must be text or a flat list of strings');
            foreach ($items as $item) if (!is_string($item)) throw new \InvalidArgumentException('Todo ' . $field . ' must be text or a flat list of strings');
            $input[$field] = implode("\n", $items);
            Log::writeWarning('Normalized ToDo %s string list to newline text', $field);
        }
        return self::payload($input);
    }
}

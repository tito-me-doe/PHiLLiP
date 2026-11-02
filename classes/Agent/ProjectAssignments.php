<?php
namespace Agent;

/** Passive owner-curated links to existing standalone worker runs. */
class ProjectAssignments
{
    private static function identity(Project $project)
    {
        if (ToolContext::get('run_id') !== null || ToolContext::get('agent_id') !== null || ToolContext::get('frontend')) throw new \RuntimeException('Worker assignments require the local operator');
        $identity = ProjectAccess::local($project);
        if ($identity['unix']['root'] || $identity['unix']['uid'] === null) throw new \RuntimeException('Worker assignment changes require an unprivileged local operator');
        return $identity;
    }

    private static function todo(Project $project, $id, $lock = false)
    {
        if (!is_scalar($id) || !preg_match('/^[1-9][0-9]*$/D', (string)$id)) throw new \InvalidArgumentException('ToDo must be a positive integer');
        $row = Database::row('SELECT id, project_id, revision, title FROM project_todos WHERE project_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''), [$project->id, $id]);
        if (!$row) throw new \RuntimeException('ToDo not found in this project');
        return $row;
    }

    public static function link(Project $project, $todoId, $agentRef, $runId)
    {
        $identity = self::identity($project);
        if (!is_scalar($runId) || !preg_match('/^[1-9][0-9]*$/D', (string)$runId)) throw new \InvalidArgumentException('Run must be a positive integer');
        $agent = Agents::get($agentRef);
        if ($agent['parent_id'] !== null || $agent['parent_thread_id'] !== null || (int)$agent['depth'] !== 0) throw new \RuntimeException('Only standalone workers may be linked');
        return State::transaction(function () use ($project, $todoId, $agent, $runId, $identity) {
            $todo = self::todo($project, $todoId, true);
            $thread = Database::row('SELECT owner_id, deletedon FROM threads WHERE id = ?', [$agent['thread_id']]);
            if (!$thread || $thread['deletedon'] !== null || (string)$thread['owner_id'] !== (string)$identity['owner'] || Project::forThread($agent['thread_id'])) throw new \RuntimeException('Worker thread is unavailable or is not a standalone owned thread');
            $run = Database::row('SELECT id FROM runs WHERE id = ? AND thread_id = ?', [$runId, $agent['thread_id']]);
            if (!$run) throw new \RuntimeException('Run does not belong to this worker');
            $row = Database::row('INSERT INTO project_todo_worker_links (project_id, todo_id, todo_revision, agent_id, agent_name, run_id, actor) VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT (todo_id, todo_revision, run_id) DO NOTHING RETURNING *', [$project->id, $todo['id'], $todo['revision'], $agent['id'], $agent['name'], $runId, (string)$identity['owner']]);
            if (!$row) $row = Database::row('SELECT * FROM project_todo_worker_links WHERE todo_id = ? AND todo_revision = ? AND run_id = ?', [$todo['id'], $todo['revision'], $runId]);
            return $row;
        });
    }

    public static function verdict(Project $project, $todoId, $verdict, $evidence)
    {
        $identity = self::identity($project);
        if (!in_array($verdict, ['accept', 'reject'], true)) throw new \InvalidArgumentException('Verdict must be accept or reject');
        if (!is_array($evidence) && !$evidence instanceof \stdClass) throw new \InvalidArgumentException('Evidence must be a JSON object or array');
        return State::transaction(function () use ($project, $todoId, $verdict, $evidence, $identity) {
            $todo = self::todo($project, $todoId, true);
            $link = Database::row('SELECT * FROM project_todo_worker_links WHERE project_id = ? AND todo_id = ? ORDER BY id DESC LIMIT 1', [$project->id, $todo['id']]);
            if (!$link || (string)$link['todo_revision'] !== (string)$todo['revision']) throw new \RuntimeException('No current worker link for this ToDo revision');
            $run = Database::row('SELECT status, error FROM runs WHERE id = ?', [$link['run_id']]);
            if (!$run || !in_array($run['status'], ['completed', 'failed', 'stopped'], true)) throw new \RuntimeException('A terminal worker run is required for a verdict');
            if ($verdict === 'accept' && ($run['status'] !== 'completed' || $run['error'] !== null)) throw new \RuntimeException('Acceptance requires a completed successful worker run');
            $row = Database::row('INSERT INTO project_todo_worker_verdicts (link_id, verdict, evidence, actor) VALUES (?, ?, ?::jsonb, ?) RETURNING *', [$link['id'], $verdict, State::encode($evidence), (string)$identity['owner']]);
            if ($row) $row['evidence'] = json_decode($row['evidence'], false);
            return $row;
        });
    }

    public static function listing(Project $project, $todoId = null, $compact = false)
    {
        self::identity($project);
        if ($todoId !== null) self::todo($project, $todoId);
        $todos = Database::rows('SELECT id, title, revision FROM project_todos WHERE project_id = ?' . ($todoId !== null ? ' AND id = ?' : '') . ' ORDER BY id', $todoId !== null ? [$project->id, $todoId] : [$project->id]);
        $rows = Database::rows('SELECT l.*, r.status AS run_status, r.error AS run_error FROM project_todo_worker_links l LEFT JOIN runs r ON r.id = l.run_id WHERE l.project_id = ?' . ($todoId !== null ? ' AND l.todo_id = ?' : '') . ' ORDER BY l.id DESC', $todoId !== null ? [$project->id, $todoId] : [$project->id]);
        $byTodo = [];
        foreach ($rows as $row) {
            $verdicts = Database::rows('SELECT id, verdict, evidence, actor, created_at FROM project_todo_worker_verdicts WHERE link_id = ? ORDER BY id DESC', [$row['id']]);
            foreach ($verdicts as &$verdict) $verdict['evidence'] = json_decode($verdict['evidence'], false); unset($verdict);
            $row['verdicts'] = $verdicts;
            $byTodo[(string)$row['todo_id']][] = $row;
        }
        $counts = ['total' => count($todos), 'accepted' => 0, 'rejected' => 0, 'pending' => 0, 'stale' => 0, 'unlinked' => 0]; $items = [];
        foreach ($todos as $todo) {
            $history = $byTodo[(string)$todo['id']] ?? [];
            foreach ($history as $i => &$link) { $link['stale'] = (string)$link['todo_revision'] !== (string)$todo['revision']; $link['active'] = $i === 0 && !$link['stale']; } unset($link);
            $active = $history && $history[0]['active'] ? $history[0] : null;
            $category = !$history ? 'unlinked' : (!$active ? 'stale' : (($active['verdicts'][0]['verdict'] ?? null) === 'accept' ? 'accepted' : (($active['verdicts'][0]['verdict'] ?? null) === 'reject' ? 'rejected' : 'pending')));
            $counts[$category]++;
            if ($compact && $active) {
                $summary = array_intersect_key($active, array_flip(['id', 'agent_id', 'agent_name', 'run_id', 'run_status', 'todo_revision', 'created_at']));
                $summary['verdict'] = $active['verdicts'][0]['verdict'] ?? null;
            } else $summary = $active;
            $item = ['todo_id' => (int)$todo['id'], 'title' => $todo['title'], 'revision' => (int)$todo['revision'], 'state' => $category, 'active' => $summary];
            if (!$compact) $item['history'] = $history;
            $items[] = $item;
        }
        return ['project_id' => $project->id, 'counts' => $counts, 'todos' => $items];
    }
}

<?php
namespace Agent\Tools;

/** Scoped planning and execution reports. Project identity is always derived from the current thread. */
class Project
{
    private static function current($planning = false)
    {
        $project = \Agent\Project::forThread(\Agent\ToolContext::get('thread_id'));
        if (!$project) throw new \RuntimeException('Current conversation is not a project thread');
        if (\Agent\ToolContext::get('frontend')) \Agent\ProjectAccess::read(\Agent\ToolContext::get('user_id'), $project);
        if ($planning && (string)$project->get()['thread_id'] !== (string)\Agent\ToolContext::get('thread_id')) throw new \RuntimeException('Plan changes belong in the project planning thread');
        return $project;
    }

    /** Read planning progress; execution threads receive only their own task, direct children, decisions and parent brief. */
    public static function status()
    {
        $project = self::current(); $row = $project->get();
        $threadId = \Agent\ToolContext::get('thread_id');
        if ((string)$row['thread_id'] === (string)$threadId) return $project->snapshot();
        $todos = $project->todos(); $current = null; $done = 0;
        foreach ($todos as $todo) {
            if ((string)$todo['thread_id'] === (string)$threadId) $current = $todo;
            if ($todo['status'] === 'done') $done++;
        }
        if (!$current) throw new \RuntimeException('No current working task');
        $workflow = new \Agent\ProjectWorkflow($project);
        $children = [];
        foreach ($todos as $todo) {
            if ((string)$todo['parent_id'] === (string)$current['id']) {
                $children[] = array_intersect_key($todo, array_flip(['id', 'parent_id', 'creator_todo_id', 'title', 'goal', 'requirements', 'context', 'gates', 'status', 'result', 'evidence', 'review', 'revision']));
            }
        }
        $decisions = $workflow->decisions($current['id']);
        $parent = null;
        if ($current['parent_id'] !== null) {
            foreach ($todos as $todo) {
                if ((string)$todo['id'] === (string)$current['parent_id']) {
                    $parent = array_intersect_key($todo, array_flip(['id', 'title', 'status', 'revision']));
                    break;
                }
            }
        }
        return [
            'project' => array_intersect_key($row, array_flip(['id', 'title', 'status', 'revision'])),
            'todo' => array_intersect_key($current, array_flip(['id', 'parent_id', 'title', 'goal', 'requirements', 'context', 'gates', 'status', 'revision', 'wait_reason'])),
            'parent' => $parent,
            'children' => $children,
            'decisions' => $decisions,
            'progress' => ['total' => count($todos), 'done' => $done],
        ];
    }

    /**
     * Add a draft ToDo in the planning thread, or a direct child in the execution context.
     * @param array $todo title, goal, requirements, context, gates (string list), optional parent_id and persona_id
     */
    public static function addTodo(array $todo)
    {
        $project = self::current();
        if ((string)\Agent\ToolContext::get('thread_id') !== (string)$project->get()['thread_id']) {
            return (new \Agent\ProjectWorkflow($project))->addChild($todo);
        }
        return self::current(true)->addTodo($todo);
    }

    /**
     * Update a ToDo in the planning thread, or revise a direct child in the execution context.
     * @param int $todoId Existing ToDo in the current project
     * @param array $changes Planning fields to update
     */
    public static function updateTodo(int $todoId, array $changes)
    {
        $project = self::current();
        if ((string)\Agent\ToolContext::get('thread_id') !== (string)$project->get()['thread_id']) {
            return (new \Agent\ProjectWorkflow($project))->reviseChild($todoId, $changes);
        }
        return self::current(true)->updateTodo($todoId, $changes);
    }

    /**
     * Save this task's result and supporting evidence. Completion requires an independent gate review.
     * @param string $result What was accomplished and what each gate demonstrated
     * @param array $evidence Evidence, citations, or verification observations
     */
    public static function report(string $result, array $evidence = []) { return self::record($result, $evidence, false); }

    /**
     * Report a concrete blocker on the currently executing task, escalating to the nearest parent.
     * @param string $reason What prevents completion and what information or authority is needed
     * @param array $evidence Supporting observations
     */
    public static function blocked(string $reason, array $evidence = []) { return self::record($reason, $evidence, true); }

    /** Yield the current run while children finish; does not stop it mid-protocol. */
    public static function waitForChildren()
    {
        $project = self::current();
        return (new \Agent\ProjectWorkflow($project))->waitForChildren();
    }

    /** Record a current-revision question addressed to the immediate parent and yield intent. */
    public static function escalate(string $question, array $evidence = [])
    {
        $project = self::current();
        return (new \Agent\ProjectWorkflow($project))->escalate($question, $evidence);
    }

    /** Answer a current question addressed to this executing parent task. Human answers use the project interface. */
    public static function answerDecision(int $decisionId, string $answer)
    {
        $project = self::current();
        return (new \Agent\ProjectWorkflow($project))->answer($decisionId, $answer);
    }

    private static function record($result, array $evidence, $blocked)
    {
        if (trim($result) === '' || strlen($result) > 131072) throw new \InvalidArgumentException('A bounded nonempty task report is required');
        $project = self::current();
        $workflow = new \Agent\ProjectWorkflow($project);
        $threadId = \Agent\ToolContext::get('thread_id');
        $runId = \Agent\ToolContext::get('run_id');
        if (!$runId) throw new \RuntimeException('Task reports require a current execution run');
        return \Agent\State::transaction(function () use ($project, $workflow, $threadId, $runId, $result, $evidence, $blocked) {
            \Agent\Database::row('SELECT id FROM projects WHERE id = ? FOR UPDATE', [$project->id]);
            $todo = \Agent\Database::row("SELECT * FROM project_todos WHERE project_id = ? AND thread_id = ? AND run_id = ? AND status = 'working' FOR UPDATE", [$project->id, $threadId, $runId]);
            if (!$todo) throw new \RuntimeException('Only the currently working task may submit a report');
            $workflow->current(); // validate current revision against the project row
            if ($blocked) {
                return $workflow->escalate($result, $evidence);
            }
            \Agent\Database::execute('UPDATE project_todos SET result = ?, evidence = ?::jsonb, run_revision = ?, updated_at = NOW() WHERE id = ?', [$result, \Agent\State::encode($evidence), (int)$todo['revision'], $todo['id']]);
            $project->event('todo.reported', ['result' => $result, 'evidence' => $evidence], $todo['id']);
            return ['recorded' => true, 'todo_id' => $todo['id'], 'requires_review' => true];
        });
    }
}

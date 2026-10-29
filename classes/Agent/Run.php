<?php
namespace Agent;

class Run
{
    public static function get($id)
    {
        $q = Database::connection()->prepare('SELECT * FROM runs WHERE id = ?');
        $q->execute([$id]);
        $row = $q->fetch();
        if (!$row) throw new \RuntimeException('Run not found');
        return $row;
    }

    public static function claim($threadId = null)
    {
        return State::transaction(function () use ($threadId) {
            $db = Database::connection();
            $sql = "SELECT t.id FROM threads t WHERE t.deletedon IS NULL AND EXISTS (SELECT 1 FROM runs r WHERE r.thread_id = t.id AND r.status = 'queued')
                    AND NOT EXISTS (SELECT 1 FROM runs r WHERE r.thread_id = t.id AND r.status = 'running')";
            $params = [];
            if ($threadId !== null) { $sql .= ' AND t.id = ?'; $params[] = $threadId; }
            else $sql .= " AND COALESCE((t.settings->>'manual')::boolean, FALSE) = FALSE";
            $q = $db->prepare($sql . ' ORDER BY t.id LIMIT 1 FOR UPDATE OF t SKIP LOCKED');
            $q->execute($params);
            $id = $q->fetchColumn();
            if (!$id) return null;
            $q = $db->prepare("UPDATE runs SET status = 'running', started_at = NOW(), heartbeat_at = NOW() WHERE id =
                (SELECT id FROM runs WHERE thread_id = ? AND status = 'queued' ORDER BY id LIMIT 1) RETURNING *");
            $q->execute([$id]);
            $run = $q->fetch();
            self::event($run['id'], ['type' => 'run.started', 'data' => []]);
            return $run;
        });
    }

    public static function event($runId, array $event)
    {
        return self::synchronized($runId, function () use ($runId, $event) {
            $row = Database::row('INSERT INTO events (run_id, type, data) VALUES (?, ?, ?::jsonb) RETURNING id', [$runId, $event['type'], State::encode($event['data'])]);
            $eventId = $row['id'];
            WorkflowState::apply($runId, $event, $eventId);
            if (in_array($event['type'], ['run.completed', 'run.stopped', 'run.failed'], true)) Indexer::enqueue($runId);
            if ($event['type'] === 'run.completed') {
                $run = self::get($runId);
                $thread = (new Thread($run['thread_id']))->get();
                if ($run['status'] === 'completed' && $thread['deletedon'] === null && in_array(trim($thread['title']), ['', 'New conversation'], true)
                    && !Database::row("SELECT id FROM runs WHERE thread_id = ? AND id < ? AND status = 'completed'", [$thread['id'], $runId])
                    && !Project::forThread($thread['id'])) {
                    Database::execute("INSERT INTO operations (type, payload) VALUES ('thread_title', ?::jsonb)", [State::encode(['run_id' => $runId, 'title' => $thread['title']])]);
                }
            }
            return $eventId;
        });
    }

    private static function synchronized($runId, $callback, $allowMissing = false)
    {
        return State::transaction(function () use ($runId, $callback, $allowMissing) {
            // One commit order per thread also prevents event cursors skipping concurrent writes.
            $q = Database::connection()->prepare('SELECT t.id FROM threads t JOIN runs r ON r.thread_id = t.id WHERE r.id = ? FOR UPDATE OF t'); $q->execute([$runId]);
            $threadId = $q->fetchColumn();
            $q = Database::connection()->prepare('SELECT 1 FROM runs WHERE id = ?'); $q->execute([$runId]);
            // A fork can remove the run while the thread lock is being acquired.
            if (!$threadId || !$q->fetchColumn()) {
                if ($allowMissing) return;
                throw new \RuntimeException('Run not found');
            }
            return call_user_func($callback);
        });
    }

    public static function execute($id, $externalControl = null)
    {
        if ($externalControl !== null && !is_callable($externalControl)) throw new \InvalidArgumentException('Execution control must be callable');
        $run = self::get($id);
        if ($run['status'] !== 'running') throw new \RuntimeException('Run must be claimed before execution');
        $request = null;
        try {
            $thread = new Thread($run['thread_id']);
            $project = Project::forThread($thread->id);
            $projectTask = $project && (string)$project->get()['thread_id'] !== (string)$thread->id;
            $snapshot = json_decode($run['settings'], true);
            $config = $snapshot['config'];
            if (isset($snapshot['credentials'])) $config['credentials_ref'] = $snapshot['credentials'];
            $config = Config::resolve($config);
            $config['tools'] = $snapshot['tools'];
            $config['before_round'] = function ($request) use ($id) { Agents::deliverMessages($id, $request); };
            if ($project) $config['tools'] = ProjectRunner::safeTools($config['tools']);
            if ($projectTask && !self::projectActive($project, $run, !empty($snapshot['frontend']))) throw new Cancelled('Project execution is no longer active');
            $todoRevision = null;
            if ($projectTask) {
                $task = Database::row('SELECT revision FROM project_todos WHERE project_id = ? AND thread_id = ? AND run_id = ?', [$project->id, $thread->id, $id]);
                $todoRevision = (int)$task['revision'];
                if (isset($snapshot['project_todo_revision']) && (int)$snapshot['project_todo_revision'] !== $todoRevision) throw new Cancelled('Project assignment revision changed');
                // This marker belongs to the trusted dispatcher, never provider request parameters.
                Database::execute("UPDATE runs SET settings = jsonb_set(settings, '{project_todo_revision}', to_jsonb(?::bigint)) WHERE id = ?", [$todoRevision, $id]);
            }
            $lastCheck = 0;
            $level = 0;
            $config['control'] = function () use ($id, &$lastCheck, &$level, $snapshot, $thread, $run, $externalControl, $project, $projectTask) {
                if ($externalControl && call_user_func($externalControl) >= 2) return 2;
                if (microtime(true) - $lastCheck > 0.15) {
                    if ($projectTask && !self::projectActive($project, $run, !empty($snapshot['frontend']))) return 2;
                    if (!empty($snapshot['frontend'])) Access::read($run['author_id'], $thread);
                    $q = Database::connection()->prepare("UPDATE runs SET heartbeat_at = NOW() WHERE id = ? AND status = 'running' RETURNING stop_level");
                    $q->execute([$id]);
                    $stopLevel = $q->fetchColumn();
                    $level = $stopLevel === false ? 2 : (int)$stopLevel;
                    $lastCheck = microtime(true);
                }
                return $level;
            };
            $config['on_event'] = function ($event) use ($thread, $id) {
                self::synchronized($id, function () use ($thread, $id, $event) {
                    self::event($id, $event);
                    if ($event['type'] === 'message') $thread->append($id, $event['data']);
                    elseif ($event['type'] === 'tool.result') $thread->append($id, ['role' => 'tool', 'tool_call_id' => $event['data']['id'], 'content' => $event['data']['content']] + array_intersect_key($event['data'], ['_tool_error' => true]));
                });
            };
            if (!empty($snapshot['frontend'])) {
                $config['tools'] = Access::tools($run['author_id'], $thread, $config['tools']);
                $config['before_tool'] = function ($call, $registry) use ($run, $thread) {
                    $source = $registry->source($call['function']['name']);
                    if (is_array($source)) {
                        $class = is_object($source[0]) ? get_class($source[0]) : $source[0];
                        if (!Access::tools($run['author_id'], $thread, [$class])) throw new \RuntimeException('Tool permission was revoked');
                    } elseif (is_string($source) && !Access::tools($run['author_id'], $thread, [explode('::', $source)[0]])) throw new \RuntimeException('Tool permission was revoked');
                };
            }
            if ($project) {
                $beforeTool = isset($config['before_tool']) ? $config['before_tool'] : null;
                $config['before_tool'] = function ($call, $registry) use ($project, $projectTask, $run, $snapshot, $beforeTool) {
                    if ($projectTask && !self::projectActive($project, $run, !empty($snapshot['frontend']))) throw new Cancelled('Project was paused or its execution changed');
                    $source = $registry->source($call['function']['name']);
                    $class = is_array($source) ? (is_object($source[0]) ? get_class($source[0]) : $source[0]) : (is_string($source) ? explode('::', $source)[0] : null);
                    $allowed = array_merge(ProjectRunner::safeTools(['SearchWeb', 'Fetch', 'BrowseWeb', 'Memory']), ['Agent\\Tools\\History', 'Agent\\Tools\\Attachments']);
                    if (!in_array($class, $allowed, true)) throw new \RuntimeException('This project has no workspace or local command authority');
                    if ($beforeTool) call_user_func($beforeTool, $call, $registry);
                };
            }
            $request = new Request($config);
            if ($projectTask) $request->yieldAfterTools(function () use ($project, $run, $snapshot, $todoRevision) {
                if (!self::projectActive($project, $run, !empty($snapshot['frontend']))) throw new Cancelled('Project was paused or its execution changed');
                $todo = Database::row("SELECT wait_reason FROM project_todos WHERE project_id = ? AND thread_id = ? AND run_id = ? AND status = 'working' AND revision = ? AND run_revision = ?", [$project->id, $run['thread_id'], $run['id'], $todoRevision, $todoRevision]);
                return $todo && $todo['wait_reason'] !== null ? 'Project ToDo yielded: waiting for ' . $todo['wait_reason'] . '.' : null;
            });
            $cancelled = [];
            foreach (Database::rows("SELECT id FROM runs WHERE thread_id = ? AND status = 'stopped' AND started_at IS NULL", [$thread->id]) as $omitted) $cancelled[(string)$omitted['id']] = true;
            $currentRevisionRuns = null;
            if ($projectTask) {
                $currentRevisionRuns = [];
                foreach (Database::rows("SELECT id FROM runs WHERE thread_id = ? AND (settings->>'project_todo_revision' = ? OR (settings->>'project_todo_revision' IS NULL AND ?::bigint = 1))", [$thread->id, (string)$todoRevision, $todoRevision]) as $previous) $currentRevisionRuns[(string)$previous['id']] = true;
            }
            foreach ($thread->messages($id) as $message) {
                // Never-executed cancelled tasks remain in durable history, but must not become future instructions.
                if ($message['run_id'] !== null && isset($cancelled[(string)$message['run_id']])) continue;
                if ($currentRevisionRuns !== null && $message['run_id'] !== null && !isset($currentRevisionRuns[(string)$message['run_id']])) continue;
                $raw = Message::fromRow($message);
                if ($message['run_id'] === null && isset($snapshot['system_prompt'])) $raw['content'] = $snapshot['system_prompt'];
                if ($project && !$projectTask && $message['run_id'] === null && $message['role'] === 'system') $raw['content'] .= "\n\nThis conversation plans Project " . $project->id . ". Use the scoped Project tools to inspect and flesh out nested ToDos with clear goals, requirements, context and acceptance gates. Planning and preflight never authorize execution: only an explicit human Start Project action can start it. If work is running, plan changes require pausing first. Never claim a ToDo or project is complete merely because an answer was generated.";
                $request->addMessage($raw);
            }
            $context = isset($snapshot['execution_context']) ? $snapshot['execution_context'] : [];
            $context = array_replace($context, ['thread_id' => $thread->id, 'run_id' => $id, 'frontend' => !empty($snapshot['frontend'])]);
            if ($project) $context['project_id'] = $project->id;
            if ($todoRevision !== null) $context['todo_revision'] = $todoRevision;
            if ($project) {
                $origin = $project->get();
                $context['memory_restricted'] = true;
                $context['memory_owner'] = $origin['owner_id'];
                $mapped = !empty($snapshot['frontend']) ? null : Identity::user(['id' => $origin['owner_id']]);
                $context['user_id'] = !empty($snapshot['frontend']) ? $run['author_id'] : ($mapped && $mapped['active'] ? $mapped['id'] : null);
            }
            if (!array_key_exists('user_id', $context)) $context['user_id'] = $run['author_id'];
            $answer = ToolContext::withValues($context, function () use ($request) { return $request->send(); });
            self::synchronized($id, function () use ($request, $id, $answer) {
                if (self::get($id)['status'] !== 'running') return;
                Database::connection()->prepare("UPDATE runs SET status = CASE WHEN stop_level > 0 THEN 'stopped' ELSE 'completed' END, result = ?::jsonb, finished_at = NOW() WHERE id = ?")
                    ->execute([json_encode($request->response), $id]);
                self::event($id, ['type' => 'run.completed', 'data' => ['content' => $answer]]);
            });
            return $answer;
        } catch (\Throwable $e) {
            if ($e instanceof Cancelled) { self::finishStopped($id, $e->getMessage()); return null; }
            self::synchronized($id, function () use ($id, $request, $e) {
                if (self::get($id)['status'] !== 'running') return;
                self::cleanupPending($id);
                Database::connection()->prepare("UPDATE runs SET status = 'failed', error = ?, result = ?::jsonb, finished_at = NOW() WHERE id = ?")
                    ->execute([$e->getMessage(), json_encode($request ? $request->response : []), $id]);
                self::event($id, ['type' => 'run.failed', 'data' => ['error' => $e->getMessage()]]);
            }, true);
            throw $e;
        }
    }

    private static function projectActive(Project $project, array $run, $frontend)
    {
        $origin = $project->get();
        if ($frontend) {
            ProjectAccess::read($run['author_id'], $project);
            ProjectAccess::read($origin['owner_id'], $project);
        }
        if ($origin['status'] !== 'running') return false;
        $todo = Database::row('SELECT run_id, status, revision, run_revision FROM project_todos WHERE project_id = ? AND thread_id = ?', [$project->id, $run['thread_id']]);
        return $todo && (string)$todo['run_id'] === (string)$run['id'] && $todo['status'] === 'working'
            && $todo['run_revision'] !== null && (string)$todo['revision'] === (string)$todo['run_revision'];
    }

    public static function requestStop($id)
    {
        return self::synchronized($id, function () use ($id) {
            $q = Database::connection()->prepare("UPDATE runs SET stop_level = LEAST(3, stop_level + 1) WHERE id = ? AND status = 'running' RETURNING stop_level");
            $q->execute([$id]); $level = (int)$q->fetchColumn();
            if ($level) self::event($id, ['type' => 'run.stop_requested', 'data' => ['level' => $level]]);
            return $level;
        });
    }

    public static function finishStopped($id, $reason)
    {
        return self::synchronized($id, function () use ($id, $reason) {
        $run = self::get($id);
        if (!in_array($run['status'], ['queued', 'running'], true)) return;
        $q = Database::connection()->prepare('SELECT type, data FROM events WHERE run_id = ? ORDER BY id');
        $q->execute([$id]);
        $partial = '';
        $reasoning = '';
        foreach ($q->fetchAll() as $event) {
            $data = json_decode($event['data'], true);
            if ($event['type'] === 'message') { $partial = ''; $reasoning = ''; }
            elseif ($event['type'] === 'text.delta') $partial .= $data['content'];
            elseif ($event['type'] === 'reasoning.delta') $reasoning .= $data['content'];
        }
        if ($partial !== '' || $reasoning !== '') (new Thread($run['thread_id']))->append($id, ['role' => 'assistant', 'content' => $partial, 'reasoning' => $reasoning, '_interrupted' => true]);
        self::cleanupPending($id);
        Database::connection()->prepare("UPDATE runs SET status = 'stopped', error = ?, finished_at = NOW() WHERE id = ?")->execute([$reason, $id]);
        self::event($id, ['type' => 'run.stopped', 'data' => ['reason' => $reason]]);
        }, true);
    }

    public static function cleanupPending($id)
    {
        $run = self::get($id);
        $thread = new Thread($run['thread_id']);
        $pending = [];
        foreach ($thread->messages($id) as $message) {
            if ($message['run_id'] != $id) continue;
            foreach (isset($message['raw']['tool_calls']) ? $message['raw']['tool_calls'] : [] as $call) $pending[$call['id']] = true;
            if ($message['role'] === 'tool') unset($pending[$message['raw']['tool_call_id']]);
        }
        foreach ($pending as $callId => $unused) $thread->append($id, ['role' => 'tool', 'tool_call_id' => $callId, 'content' => 'Interrupted before a confirmed tool result was available. Do not assume the operation succeeded.']);
    }
}

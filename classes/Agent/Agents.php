<?php
namespace Agent;

/** Local, durable agent orchestration. Agents share code and tools, never raw parent history. */
class Agents
{
    /** Operator shortcut: prepare an owned worker and explicit grants, then enqueue after commit. */
    public static function work($reference, $task, $options = [])
    {
        if (ToolContext::get('run_id') !== null || ToolContext::get('agent_id') !== null) throw new \RuntimeException('Agent work setup requires a local operator; agents cannot approve their own access');
        $identity = self::identity();
        if ($identity['unix']['root'] || $identity['unix']['uid'] === null) throw new \RuntimeException('Agent work requires an unprivileged identified Unix operator');
        $options = Config::object($options, 'Agent work options');
        foreach ($options as $key => $unused) if (!in_array($key, ['persona', 'profile', 'tools', 'contract', 'team', 'workspace', 'files', 'commands', 'key'], true)) throw new \InvalidArgumentException('Unknown agent work option: ' . $key);
        $message = Message::normalize($task);
        if (trim(Message::text(['content' => $message['content']])) === '') throw new \InvalidArgumentException('An agent task must contain text');
        // Stable retries are opt-in at this boundary, never inferred from task metadata.
        unset($message['submission_key']);
        State::encode($message);
        $key = array_key_exists('key', $options) ? Submission::key($options['key']) : null;
        if (is_array($reference)) $reference = isset($reference['id']) ? $reference['id'] : null;
        if ($reference instanceof \stdClass) $reference = isset($reference->id) ? $reference->id : null;
        if (!is_int($reference) && !is_string($reference)) throw new \InvalidArgumentException('Agent reference requires an ID or unique name');
        $reference = trim((string)$reference);
        $numeric = preg_match('/^[1-9][0-9]*$/D', $reference);
        if (!$numeric) $reference = self::label($reference, 'Agent name');
        $team = array_key_exists('team', $options) ? self::label($options['team'], 'Agent team') : null;
        $files = array_key_exists('files', $options) ? $options['files'] : [];
        if (is_string($files)) $files = [$files];
        if (!is_array($files) || ($files && array_keys($files) !== range(0, count($files) - 1))) throw new \InvalidArgumentException('Files must be a path or list of workspace-relative paths');
        foreach ($files as $file) if (!is_string($file) || preg_match('/[*?\[\]]/', $file)) throw new \InvalidArgumentException('File grants require explicit paths without wildcards');
        $commands = array_key_exists('commands', $options) ? $options['commands'] : [];
        if (!is_array($commands) || ($commands && array_keys($commands) !== range(0, count($commands) - 1))) throw new \InvalidArgumentException('Commands must be a list of JSON argv commands');
        foreach ($commands as &$command) $command = WorkspaceAccess::command($command);
        unset($command);
        // A nested transaction cannot guarantee either rollback or visibility to detached workers.
        if (Database::connection()->inTransaction()) throw new \RuntimeException('Agent work must be called outside an existing transaction');
        $settings = array_intersect_key($options, array_flip(['persona', 'profile', 'tools', 'contract']));
        $prepared = State::transaction(function () use ($reference, $numeric, $team, $identity, $options, $settings, $files, $commands) {
            $selectedTeam = $team === null ? 'default' : $team;
            Database::row('SELECT pg_advisory_xact_lock(hashtext(?))', ['agents:' . gethostname() . ':' . $identity['unix']['uid'] . ':' . $identity['owner'] . ':' . $selectedTeam]);
            $sql = 'SELECT id FROM agents WHERE hostname = ? AND unix_uid = ? AND owner_id = ? AND ' . ($numeric ? 'id = ?' : 'name = ?');
            $params = [gethostname(), $identity['unix']['uid'], (string)$identity['owner'], $reference];
            if ($team !== null) { $sql .= ' AND team = ?'; $params[] = $team; }
            $rows = Database::rows($sql, $params);
            if (count($rows) > 1) throw new \RuntimeException('Ambiguous agent name; select its ID or team');
            if ($numeric && !$rows) throw new \RuntimeException('Agent not found or not owned by this local identity');
            $agent = $rows ? self::get($rows[0]['id']) : null;
            $workspace = $agent ? $agent['workspace'] : (array_key_exists('workspace', $options) && is_string($options['workspace']) ? realpath($options['workspace']) : realpath(getcwd()));
            if (array_key_exists('workspace', $options)) {
                $requested = is_string($options['workspace']) ? realpath($options['workspace']) : false;
                if (!$requested || ($agent && $requested !== $workspace)) throw new \InvalidArgumentException('Agent workspace must match its existing specific directory');
                $workspace = $requested;
            }
            if (!$workspace || $workspace === DIRECTORY_SEPARATOR || !is_dir($workspace) || !is_readable($workspace) || realpath($workspace) !== $workspace) throw new \InvalidArgumentException('Agent workspace must be a readable, specific directory');
            if ($agent) {
                Database::row('SELECT id FROM threads WHERE id = ? FOR UPDATE', [$agent['thread_id']]);
                if (($settings || $files || $commands) && Database::row("SELECT id FROM runs WHERE thread_id = ? AND status IN ('queued', 'running') LIMIT 1", [$agent['thread_id']])) throw new \RuntimeException('Wait for or stop all tasks before changing agent settings or workspace grants');
            }
            $previous = getcwd();
            if (!chdir($workspace)) throw new \RuntimeException('Agent workspace is unavailable');
            try {
                // Validate every path before creating/configuring anything or granting any access.
                foreach ($files as $file) WorkspaceAccess::path($file);
                $created = !$agent;
                if ($created) $agent = self::create($reference, array_replace($settings, ['team' => $selectedTeam, 'workspace' => $workspace]));
                elseif ($settings) $agent = self::configure($agent['id'], $settings);
                $approvals = ['files' => [], 'commands' => []];
                foreach (array_values(array_unique($files)) as $file) {
                    WorkspaceAccess::approve($agent['thread_id'], 'patch', $file);
                    $approvals['files'][] = $file;
                }
                foreach ($commands as $command) {
                    $grant = WorkspaceAccess::approve($agent['thread_id'], 'command', $command);
                    if (!in_array($grant['target'], $approvals['commands'], true)) $approvals['commands'][] = $grant['target'];
                }
                return ['agent' => $agent, 'created' => $created, 'approvals' => $approvals];
            } finally {
                if ($previous !== false) chdir($previous);
            }
        });
        $agent = $prepared['agent'];
        $assignment = self::assign($agent['id'], $message, $key);
        return ['agent_id' => $agent['id'], 'thread_id' => $agent['thread_id'], 'name' => $agent['name'], 'created' => $prepared['created'],
            'run_id' => $assignment['run_id'], 'status' => $assignment['status'], 'approvals' => $prepared['approvals']];
    }

    /** Concise durable evidence for the active response, or the latest assignment when idle. */
    public static function peek($reference)
    {
        $agent = self::get($reference);
        $run = Database::row('SELECT id, status, error FROM runs WHERE thread_id = ?' . ($agent['active_run_id'] !== null ? ' AND id = ?' : '') . ' ORDER BY id DESC LIMIT 1',
            $agent['active_run_id'] !== null ? [$agent['thread_id'], $agent['active_run_id']] : [$agent['thread_id']]);
        $cursor = Database::row('SELECT COALESCE(MAX(e.id), 0) AS cursor FROM events e JOIN runs r ON r.id = e.run_id WHERE r.thread_id = ?', [$agent['thread_id']]);
        $result = ['agent_id' => $agent['id'], 'name' => $agent['name'], 'team' => $agent['team'], 'status' => $agent['status'], 'queued' => $agent['queued'],
            'active_run_id' => $agent['active_run_id'], 'run' => null, 'changes' => [], 'checks' => [], 'tool_errors' => [], 'cursor' => (int)$cursor['cursor']];
        if (!$run) return $result;
        $answer = Database::row("SELECT data->>'content' AS content FROM events WHERE run_id = ? AND type = 'run.completed' ORDER BY id DESC LIMIT 1", [$run['id']]);
        $result['run'] = ['id' => (int)$run['id'], 'status' => $run['status'], 'error' => $run['error'] === null ? null : self::preview($run['error'], 400), 'answer' => $answer ? self::preview($answer['content'], 1200) : null];
        $events = Database::rows("SELECT data FROM events WHERE run_id = ? AND type = 'tool.result' ORDER BY id DESC LIMIT 100", [$run['id']]);
        $changes = [];
        foreach (array_reverse($events) as $event) {
            $data = json_decode($event['data'], true);
            if (!is_array($data) || !isset($data['name']) || !is_string($data['name'])) continue;
            if (!empty($data['_tool_error'])) {
                $result['tool_errors'][] = ['name' => $data['name'], 'message' => isset($data['content']) && is_string($data['content']) ? self::preview($data['content'], 400) : 'Tool failed'];
                $result['tool_errors'] = array_slice($result['tool_errors'], -3);
                continue;
            }
            if (!isset($data['content']) || !is_string($data['content'])) continue;
            if (!preg_match('/^Agent_Tools_Workspace_(create|write|replace|replaceLines|run)$/D', $data['name'], $match)) continue;
            $content = json_decode($data['content'], true);
            if (!is_array($content)) continue;
            if ($match[1] !== 'run') {
                if (!isset($content['path'], $content['sha256'], $content['bytes']) || !is_string($content['path']) || !is_string($content['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $content['sha256']) || !is_int($content['bytes']) || $content['bytes'] < 0) continue;
                $changes[$content['path']] = array_intersect_key($content, array_flip(['path', 'sha256', 'bytes']));
            } else {
                if (!isset($content['command']) || !is_array($content['command']) || !array_key_exists('exit_code', $content)) continue;
                $check = array_intersect_key($content, array_flip(['command', 'exit_code', 'timed_out', 'truncated']));
                if (($content['exit_code'] !== 0 || !empty($content['timed_out'])) && isset($content['stderr']) && is_string($content['stderr'])) $check['stderr'] = self::preview($content['stderr'], 600, true);
                $result['checks'][] = $check;
            }
        }
        $result['changes'] = array_values($changes);
        $result['check_count'] = count($result['checks']);
        $result['checks'] = array_slice($result['checks'], -5);
        return $result;
    }

    private static function preview($value, $length, $tail = false)
    {
        return mb_substr((string)$value, $tail ? -$length : 0, $length, 'UTF-8');
    }

    public static function create($name, $options = [])
    {
        $identity = self::identity();
        $options = Config::object($options, 'Agent options');
        $name = self::label($name, 'Agent name');
        $parent = self::current();
        $modelContext = ToolContext::get('run_id') !== null;
        $parentThread = $parent ? ($parent['parent_thread_id'] ?: $parent['thread_id']) : ToolContext::get('thread_id');
        if ($modelContext && !$parentThread) throw new \RuntimeException('Delegation requires a saved parent thread');
        if ($parent && (int)$parent['depth'] >= 2) throw new \RuntimeException('Agent delegation depth limit is 2');
        $team = $parent ? $parent['team'] : ($modelContext ? 'thread:' . $parentThread : self::label(isset($options['team']) ? $options['team'] : 'default', 'Agent team'));
        $workspace = $parent ? $parent['workspace'] : realpath(isset($options['workspace']) ? $options['workspace'] : getcwd());
        if (!$workspace || !is_dir($workspace) || !is_readable($workspace) || $workspace === DIRECTORY_SEPARATOR) throw new \InvalidArgumentException('Agent workspace must be a readable, specific directory');
        if ($modelContext && !array_key_exists('profile', $options)) {
            $parentRun = Run::get(ToolContext::get('run_id'));
            $parentSnapshot = json_decode($parentRun['settings'], true);
            $options['profile'] = $parentSnapshot['config'];
        }
        $arguments = array_replace(Cli::parse([]), $options);
        $arguments['profile_requested'] = array_key_exists('profile', $options);
        $arguments['tools_requested'] = array_key_exists('tools', $options);
        $arguments['tools'] = ToolRegistry::names($arguments['tools']);
        $persona = $arguments['persona'];
        if ($persona !== null) {
            // Trusted inline definitions are a local-code convenience, not a frontend bypass.
            if ($persona instanceof \stdClass || (is_string($persona) && substr(ltrim($persona), 0, 1) === '{')) $persona = Config::object($persona, 'Persona');
            if (is_array($persona) && !isset($persona['prompt']) && isset($persona['system_prompt'])) $persona['prompt'] = $persona['system_prompt'];
            $inline = is_array($persona) && !isset($persona['id']) && isset($persona['prompt']);
            $arguments['persona'] = Personas::resolve($persona, $inline ? null : $identity['owner']);
        }
        $config = Cli::configuration($arguments);
        Cli::authorizeTools($config, $identity);
        if ($modelContext) self::ceiling($config['tools']);
        $context = ['memory_restricted' => true, 'memory_owner' => $identity['owner'], 'user_id' => $identity['user'] ? $identity['user']['id'] : null];
        $agent = State::transaction(function () use ($identity, $name, $team, $workspace, $parent, $parentThread, $modelContext, $config, $context) {
            // Serialize per-owner creation so concurrent delegates cannot exceed the bounded team size.
            Database::row('SELECT pg_advisory_xact_lock(hashtext(?))', ['agents:' . gethostname() . ':' . $identity['unix']['uid'] . ':' . $identity['owner'] . ':' . $team]);
            $params = [gethostname(), $identity['unix']['uid'], (string)$identity['owner'], $team];
            $count = Database::row('SELECT COUNT(*) AS count FROM agents WHERE hostname = ? AND unix_uid = ? AND owner_id = ? AND team = ?', $params);
            if ((int)$count['count'] >= 16) throw new \RuntimeException('Agent team limit is 16; reuse an existing agent');
            if (Database::row('SELECT id FROM agents WHERE hostname = ? AND unix_uid = ? AND owner_id = ? AND team = ? AND name = ?', array_merge($params, [$name]))) throw new \RuntimeException('An agent with this name already exists in this team');
            $settings = ['profile' => $config, 'tools' => $config['tools'], 'contract' => isset($config['contract']) ? $config['contract'] : null,
                'manual' => true, 'parameters' => [], 'execution_context' => $context];
            if (isset($config['system_prompt'])) $settings['system_prompt'] = $config['system_prompt'];
            if (isset($config['assistant_name'])) $settings['assistant_name'] = $config['assistant_name'];
            $settings['persona_defaults'] = ['tools' => $config['tools'], 'contract' => isset($config['contract']) ? $config['contract'] : null];
            $thread = Thread::create($identity['owner'], $name, $settings);
            $row = Database::row('INSERT INTO agents (name, team, owner_id, unix_uid, hostname, thread_id, workspace, parent_id, parent_thread_id, depth) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING *',
                [$name, $team, (string)$identity['owner'], $identity['unix']['uid'], gethostname(), $thread->id, $workspace, $parent ? $parent['id'] : null, $parentThread, $parent ? (int)$parent['depth'] + 1 : ($modelContext ? 1 : 0)]);
            $context['agent_id'] = (int)$row['id'];
            $thread->configure(['execution_context' => $context]);
            return self::describe($row);
        });
        Events::emit('agent.created', ['agent_id' => $agent['id'], 'name' => $agent['name'], 'team' => $agent['team']]);
        Log::writeSuccess('Agent created: %s (#%d)', $agent['name'], $agent['id']);
        return $agent;
    }

    public static function get($reference, $details = false)
    {
        $identity = self::identity();
        if (is_array($reference)) $reference = isset($reference['id']) ? $reference['id'] : null;
        if ($reference instanceof \stdClass) $reference = isset($reference->id) ? $reference->id : null;
        if (!is_int($reference) && !is_string($reference)) throw new \InvalidArgumentException('Agent reference requires an ID or unique name');
        $reference = trim((string)$reference);
        $params = [gethostname(), $identity['unix']['uid'], (string)$identity['owner']];
        $sql = 'SELECT * FROM agents WHERE hostname = ? AND unix_uid = ? AND owner_id = ? AND ';
        if (preg_match('/^[1-9][0-9]*$/D', $reference)) $sql .= 'id = ?';
        else $sql .= 'name = ?';
        $rows = Database::rows($sql, array_merge($params, [$reference]));
        $rows = array_values(array_filter($rows, [self::class, 'visible']));
        if (!$rows) throw new \RuntimeException('Agent not found or not owned by this local identity');
        if (count($rows) !== 1) throw new \RuntimeException('Ambiguous agent name; select its ID');
        $agent = self::describe($rows[0]);
        if ($details) {
            $prompt = Database::row("SELECT raw->>'content' AS content FROM messages WHERE thread_id = ? AND role = 'system' AND run_id IS NULL", [$agent['thread_id']]);
            $agent['system_prompt'] = $prompt ? $prompt['content'] : null;
            $state = State::thread($agent['thread_id'])->read()['value'];
            $agent['approvals'] = isset($state['approvals']) ? array_values($state['approvals']) : [];
            $agent['runs'] = Database::rows("SELECT r.id, r.status, r.error, r.created_at, r.finished_at,
                (SELECT LEFT(m.raw->>'content', 300) FROM messages m WHERE m.run_id = r.id AND m.role = 'user' ORDER BY m.id LIMIT 1) AS task
                FROM runs r WHERE r.thread_id = ? ORDER BY r.id DESC LIMIT 30", [$agent['thread_id']]);
        }
        return $agent;
    }

    public static function all($team = null)
    {
        $identity = self::identity();
        $sql = 'SELECT * FROM agents WHERE hostname = ? AND unix_uid = ? AND owner_id = ?';
        $params = [gethostname(), $identity['unix']['uid'], (string)$identity['owner']];
        if ($team !== null) { $sql .= ' AND team = ?'; $params[] = self::label($team, 'Agent team'); }
        return array_map([self::class, 'describe'], array_values(array_filter(Database::rows($sql . ' ORDER BY id', $params), [self::class, 'visible'])));
    }

    /** Change copied defaults only between tasks, never halfway through a response or queued workload. */
    public static function configure($reference, $options)
    {
        $agent = self::get($reference); $identity = self::identity();
        $options = Config::object($options, 'Agent options');
        foreach ($options as $key => $unused) if (!in_array($key, ['persona', 'profile', 'tools', 'contract'], true)) throw new \InvalidArgumentException('Unknown agent setting: ' . $key);
        $thread = new Thread($agent['thread_id']);
        State::transaction(function () use ($agent, $identity, $options, $thread) {
            Database::row('SELECT id FROM threads WHERE id = ? FOR UPDATE', [$agent['thread_id']]);
            if (Database::row("SELECT id FROM runs WHERE thread_id = ? AND status IN ('queued', 'running') LIMIT 1", [$agent['thread_id']])) throw new \RuntimeException('Wait for or stop all tasks before changing agent settings');
            $arguments = array_replace(Cli::parse([]), $options);
            $arguments['profile_requested'] = array_key_exists('profile', $options);
            $arguments['tools_requested'] = array_key_exists('tools', $options);
            $arguments['tools'] = ToolRegistry::names($arguments['tools']);
            if ($arguments['persona'] !== null) {
                $persona = $arguments['persona'];
                if ($persona instanceof \stdClass || (is_string($persona) && substr(ltrim($persona), 0, 1) === '{')) $persona = Config::object($persona, 'Persona');
                if (is_array($persona) && !isset($persona['prompt']) && isset($persona['system_prompt'])) $persona['prompt'] = $persona['system_prompt'];
                $inline = is_array($persona) && !isset($persona['id']) && isset($persona['prompt']);
                $arguments['persona'] = Personas::resolve($persona, $inline ? null : $identity['owner']);
            }
            $config = Cli::configuration($arguments, $thread);
            Cli::authorizeTools($config, $identity);
            if (ToolContext::get('run_id')) self::ceiling($config['tools']);
            $updates = ['profile' => $config, 'parameters' => [], 'tools' => $config['tools'], 'contract' => isset($config['contract']) ? $config['contract'] : null,
                'persona_defaults' => ['tools' => $config['tools'], 'contract' => isset($config['contract']) ? $config['contract'] : null]];
            if (isset($config['assistant_name'])) $updates['assistant_name'] = $config['assistant_name'];
            $thread->configure($updates, $arguments['persona'] !== null ? $config['system_prompt'] : null);
        });
        Events::emit('agent.configured', ['agent_id' => $agent['id']]);
        Log::writeSuccess('Agent settings saved: %s', $agent['name']);
        return self::get($agent['id']);
    }

    /** Explicitly remove idle agents and their owned descendants, including their private conversations. */
    public static function remove($reference)
    {
        $agent = self::get($reference);
        return State::transaction(function () use ($agent) {
            Database::row('SELECT pg_advisory_xact_lock(hashtext(?))', ['agents:' . gethostname() . ':' . $agent['unix_uid'] . ':' . $agent['owner_id'] . ':' . $agent['team']]);
            $rows = Database::rows('WITH RECURSIVE descendants AS (SELECT id, thread_id FROM agents WHERE id = ? UNION ALL SELECT a.id, a.thread_id FROM agents a JOIN descendants d ON a.parent_id = d.id) SELECT * FROM descendants ORDER BY thread_id', [$agent['id']]);
            foreach ($rows as $row) {
                Database::row('SELECT id FROM threads WHERE id = ? FOR UPDATE', [$row['thread_id']]);
                if (Database::row("SELECT id FROM runs WHERE thread_id = ? AND status IN ('queued', 'running') LIMIT 1", [$row['thread_id']])) throw new \RuntimeException('Stop all tasks before removing an agent or its descendants');
            }
            foreach (array_reverse($rows) as $row) Database::execute('DELETE FROM threads WHERE id = ?', [$row['thread_id']]);
            Log::writeSuccess('Agent removed: %s', $agent['name']);
            return ['agent_id' => $agent['id'], 'removed' => true, 'agents_removed' => count($rows)];
        });
    }

    public static function assign($reference, $task, $key = null)
    {
        $agent = self::get($reference);
        if (ToolContext::get('run_id')) self::ceiling((new Thread($agent['thread_id']))->get()['settings']['tools']);
        $message = Message::normalize($task);
        if (trim(Message::text($message)) === '') throw new \InvalidArgumentException('An agent task must contain text');
        if ($key !== null) $message['submission_key'] = Submission::key($key);
        $run = State::transaction(function () use ($agent, $message) {
            Database::row('SELECT id FROM threads WHERE id = ? FOR UPDATE', [$agent['thread_id']]);
            return (new Thread($agent['thread_id']))->enqueue($agent['owner_id'], $message);
        });
        // Always nudge: pause/resume may race an assignment; the worker checks durable pause under the thread lock.
        self::start($agent);
        Events::emit('agent.assigned', ['agent_id' => $agent['id'], 'run_id' => (int)$run['id']]);
        Log::writeSuccess('Task queued for %s (run #%d)', $agent['name'], $run['id']);
        return ['agent_id' => $agent['id'], 'run_id' => (int)$run['id'], 'status' => $run['status']];
    }

    /** Feedback belongs to the active assignment, never a new task or an access grant. */
    public static function message($reference, $text)
    {
        $agent = self::get($reference);
        if (!is_string($text) || trim($text) === '' || strlen($text) > 65536 || strpos($text, "\0") !== false || preg_match('//u', $text) !== 1) throw new \InvalidArgumentException('Agent message requires nonempty UTF-8 text of at most 64 KiB');
        return State::transaction(function () use ($agent, $text) {
            Database::row('SELECT id FROM threads WHERE id = ? FOR UPDATE', [$agent['thread_id']]);
            $run = Database::row("SELECT id FROM runs WHERE thread_id = ? AND status IN ('queued','running') ORDER BY CASE WHEN status = 'running' THEN 0 ELSE 1 END, id DESC LIMIT 1", [$agent['thread_id']]);
            if (!$run) throw new \RuntimeException('Agent has no queued or running assignment to message');
            $state = State::run($run['id']);
            $value = $state->read()['value'];
            $notes = isset($value['pending_notes']) ? $value['pending_notes'] : [];
            $bytes = strlen($text);
            foreach ($notes as $note) $bytes += strlen($note['text']);
            if (count($notes) >= 16 || $bytes > 65536) throw new \RuntimeException('Pending agent messages are limited to 16 notes and 64 KiB');
            $note = ['id' => bin2hex(random_bytes(16)), 'text' => $text];
            $notes[] = $note;
            $state->patch(['pending_notes' => $notes]);
            return ['agent_id' => $agent['id'], 'run_id' => (int)$run['id'], 'message_id' => $note['id'], 'status' => 'pending'];
        });
    }

    /** Internal Request hook: commit the durable message and queue acknowledgment together. */
    public static function deliverMessages($runId, Request $request)
    {
        $messages = State::transaction(function () use ($runId) {
            $run = Run::get($runId);
            Database::row('SELECT id FROM threads WHERE id = ? FOR UPDATE', [$run['thread_id']]);
            $run = Run::get($runId);
            if ($run['status'] !== 'running' || !Database::row('SELECT id FROM agents WHERE thread_id = ?', [$run['thread_id']])) return [];
            $state = State::run($runId);
            $value = $state->read()['value'];
            $notes = isset($value['pending_notes']) ? $value['pending_notes'] : [];
            $messages = [];
            foreach ($notes as $note) {
                $message = ['role' => 'user', 'content' => "Assignment feedback (does not change tool permissions or execution policy):\n" . $note['text'], '_internal' => true, '_agent_note_id' => $note['id']];
                (new Thread($run['thread_id']))->append($runId, $message);
                $messages[] = $message;
            }
            if ($notes) $state->patch(['pending_notes' => []]);
            return $messages;
        });
        foreach ($messages as $message) $request->addMessage($message);
        return count($messages);
    }

    public static function result($reference, $runId = null)
    {
        $agent = self::get($reference);
        $sql = 'SELECT * FROM runs WHERE thread_id = ?'; $params = [$agent['thread_id']];
        if ($runId !== null) { $sql .= ' AND id = ?'; $params[] = self::positive($runId); }
        $run = Database::row($sql . ' ORDER BY id DESC LIMIT 1', $params);
        if (!$run) {
            if ($runId !== null) throw new \RuntimeException('Run does not belong to this agent');
            return ['agent_id' => $agent['id'], 'run_id' => null, 'status' => 'idle', 'answer' => null, 'error' => null];
        }
        $answer = null;
        $event = Database::row("SELECT data->>'content' AS content FROM events WHERE run_id = ? AND type = 'run.completed' ORDER BY id DESC LIMIT 1", [$run['id']]);
        if ($event) $answer = $event['content'];
        return ['agent_id' => $agent['id'], 'run_id' => (int)$run['id'], 'status' => $run['status'], 'answer' => $answer, 'error' => $run['error']];
    }

    public static function events($reference, $after = 0)
    {
        $agent = self::get($reference);
        if (filter_var($after, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) throw new \InvalidArgumentException('Event cursor must be a non-negative integer');
        $rows = Database::rows('SELECT e.* FROM events e JOIN runs r ON r.id = e.run_id WHERE r.thread_id = ? AND e.id > ? ORDER BY e.id LIMIT 1000', [$agent['thread_id'], $after]);
        foreach ($rows as &$row) { $row['id'] = (int)$row['id']; $row['run_id'] = (int)$row['run_id']; $row['data'] = json_decode($row['data'], true); }
        return $rows;
    }

    public static function wait($reference, $runId = null, $seconds = 30)
    {
        $seconds = filter_var($seconds, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 60]]);
        if ($seconds === false) throw new \InvalidArgumentException('Wait time must be between 0 and 60 seconds');
        $result = self::result($reference, $runId);
        $runId = $result['run_id']; $until = microtime(true) + $seconds;
        while (in_array($result['status'], ['queued', 'running'], true) && microtime(true) < $until) {
            $control = ToolContext::get('control');
            if ($control && call_user_func($control) >= 2) throw new Cancelled('Agent wait interrupted');
            usleep(150000); $result = self::result($reference, $runId);
        }
        return $result;
    }

    public static function pause($reference)
    {
        $agent = self::get($reference);
        State::transaction(function () use ($agent) {
            Database::row('SELECT id FROM threads WHERE id = ? FOR UPDATE', [$agent['thread_id']]);
            Database::execute('UPDATE agents SET paused = TRUE WHERE id = ?', [$agent['id']]);
        });
        Events::emit('agent.paused', ['agent_id' => $agent['id']]);
        Log::writeSuccess('Agent paused: %s', $agent['name']);
        return self::get($agent['id']);
    }

    public static function resume($reference)
    {
        $agent = self::get($reference);
        if (ToolContext::get('run_id')) {
            foreach (Database::rows("SELECT settings FROM runs WHERE thread_id = ? AND status = 'queued'", [$agent['thread_id']]) as $run) {
                $snapshot = json_decode($run['settings'], true); self::ceiling($snapshot['tools']);
            }
        }
        State::transaction(function () use ($agent) {
            Database::row('SELECT id FROM threads WHERE id = ? FOR UPDATE', [$agent['thread_id']]);
            Database::execute('UPDATE agents SET paused = FALSE WHERE id = ?', [$agent['id']]);
        });
        self::start($agent);
        Events::emit('agent.resumed', ['agent_id' => $agent['id']]);
        Log::writeSuccess('Agent resumed: %s', $agent['name']);
        return self::get($agent['id']);
    }

    public static function stop($reference)
    {
        $agent = self::get($reference);
        $level = State::transaction(function () use ($agent) {
            Database::row('SELECT id FROM threads WHERE id = ? FOR UPDATE', [$agent['thread_id']]);
            Database::execute('UPDATE agents SET paused = TRUE WHERE id = ?', [$agent['id']]);
            $runs = Database::rows("UPDATE runs SET status = 'stopped', error = 'Cancelled while queued', finished_at = NOW() WHERE thread_id = ? AND status = 'queued' RETURNING id", [$agent['thread_id']]);
            foreach ($runs as $run) Run::event($run['id'], ['type' => 'run.stopped', 'data' => ['reason' => 'Cancelled while queued']]);
            $running = Database::row("SELECT id FROM runs WHERE thread_id = ? AND status = 'running'", [$agent['thread_id']]);
            return $running ? Run::requestStop($running['id']) : 0;
        });
        Events::emit('agent.stop_requested', ['agent_id' => $agent['id'], 'level' => $level]);
        Log::writeSuccess('Agent stop requested: %s (level %d)', $agent['name'], $level);
        return ['agent_id' => $agent['id'], 'stop_level' => $level, 'paused' => true];
    }

    /** Private executable entry: one supervisor per agent, each response uses a fresh shared Run process. */
    public static function supervise($reference)
    {
        $agent = self::get($reference);
        $lock = Database::row('SELECT pg_try_advisory_lock(74296, ?::integer) AS locked', [$agent['id']]);
        if (!$lock || !self::truth($lock['locked'])) return;
        $locked = true; $process = null; $run = null; $stopping = false;
        Database::execute('UPDATE agents SET worker_pid = ?, worker_at = NOW() WHERE id = ?', [getmypid(), $agent['id']]);
        if (function_exists('pcntl_signal')) foreach ([SIGINT, SIGTERM] as $signal) pcntl_signal($signal, function () use (&$stopping) { $stopping = true; });
        try {
            while (true) {
                if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
                if ($process) {
                    $status = proc_get_status($process);
                    $current = Run::get($run['id']);
                    if ($status['running'] && ($stopping || $current['status'] !== 'running' || (int)$current['stop_level'] >= 3)) {
                        self::terminate($process, $run['id']); proc_close($process); $process = null;
                        Run::finishStopped($run['id'], $stopping ? 'Agent supervisor shutdown' : 'Forced agent stop');
                    } elseif (!$status['running']) {
                        proc_close($process); $process = null;
                        if ($current['status'] === 'running') self::failed($run['id'], 'Agent execution process exited unexpectedly');
                    }
                    if ($process) { usleep(150000); continue; }
                }
                if ($stopping) break;
                $run = State::transaction(function () use ($agent, &$locked) {
                    Database::row('SELECT id FROM threads WHERE id = ? FOR UPDATE', [$agent['thread_id']]);
                    $fresh = Database::row('SELECT paused FROM agents WHERE id = ?', [$agent['id']]);
                    $running = Database::row("SELECT id, COALESCE(heartbeat_at, started_at, created_at) < NOW() - INTERVAL '90 seconds' AS stale FROM runs WHERE thread_id = ? AND status = 'running'", [$agent['thread_id']]);
                    if ($running && self::truth($running['stale'])) { self::failed($running['id'], 'Agent heartbeat expired; execution outcome may be incomplete'); $running = null; }
                    if ($running) return ['id' => null];
                    $next = !$fresh || self::truth($fresh['paused']) ? null : Run::claim($agent['thread_id']);
                    if (!$next) {
                        // Assignment takes this same thread lock. Unlock before committing to avoid a lost wakeup.
                        Database::execute('UPDATE agents SET worker_pid = NULL WHERE id = ? AND worker_pid = ?', [$agent['id'], getmypid()]);
                        Database::row('SELECT pg_advisory_unlock(74296, ?::integer)', [$agent['id']]); $locked = false;
                    }
                    return $next;
                });
                if (!$run) break;
                if (!$run['id']) { usleep(150000); continue; }
                $command = 'exec ' . self::command($agent['id'], $run['id']);
                $process = proc_open($command, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'a'], ['file', '/dev/null', 'a']], $pipes);
                if (!is_resource($process)) { $process = null; self::failed($run['id'], 'Agent execution process could not be started'); }
            }
        } catch (\Throwable $error) {
            if ($run && !empty($run['id']) && !$process) self::failed($run['id'], $error->getMessage());
            throw $error;
        } finally {
            if ($process) { self::terminate($process, $run['id']); proc_close($process); Run::finishStopped($run['id'], 'Agent supervisor shutdown'); }
            if ($locked) {
                Database::execute('UPDATE agents SET worker_pid = NULL WHERE id = ? AND worker_pid = ?', [$agent['id'], getmypid()]);
                Database::row('SELECT pg_advisory_unlock(74296, ?::integer)', [$agent['id']]);
            }
        }
    }

    /** Private child entry; validates identity and association before any tool can run. */
    public static function execute($reference, $runId)
    {
        $agent = self::get($reference);
        $run = Run::get(self::positive($runId));
        if ((string)$run['thread_id'] !== (string)$agent['thread_id']) throw new \RuntimeException('Run does not belong to this agent');
        if (realpath($agent['workspace']) !== $agent['workspace'] || !chdir($agent['workspace'])) throw new \RuntimeException('Agent workspace is unavailable or its path changed');
        $settings = json_decode($run['settings'], true);
        Cli::authorizeTools(['tools' => $settings['tools']], self::identity());
        return Run::execute($run['id']);
    }

    private static function start(array $agent)
    {
        $before = self::worker($agent['id']);
        if (!$before) throw new \RuntimeException('Agent no longer exists');
        if (self::truth($before['paused']) || self::truth($before['alive'])) return;
        if (!function_exists('exec') || !function_exists('proc_open') || !is_executable('/usr/bin/setsid') || !is_executable('/usr/bin/nohup') || !is_executable('/bin/bash')) throw new \RuntimeException('Agent workers require PHP exec/proc_open, /usr/bin/setsid, /usr/bin/nohup and /bin/bash');
        // nohup ignores hangups, but does not escape a terminal/tool process group's cleanup signals.
        $command = '/usr/bin/setsid /usr/bin/nohup ' . self::command($agent['id']) . ' </dev/null >/dev/null 2>&1 & echo $!';
        $output = []; $status = 0; exec($command, $output, $status);
        if ($status !== 0 || !$output || !preg_match('/^[1-9][0-9]*$/D', trim(end($output)))) throw new \RuntimeException('Could not launch the agent worker; the task remains queued');
        $until = microtime(true) + 3;
        do {
            $worker = self::worker($agent['id']);
            if ($worker && (self::truth($worker['paused']) || self::truth($worker['alive']) || $worker['worker_at'] !== $before['worker_at'])) return;
            usleep(20000);
        } while (microtime(true) < $until);
        throw new \RuntimeException('Agent worker did not initialize within 3 seconds. The task remains queued; inspect ' . Log::$file . ' and run ok-phill agents resume ' . $agent['id'] . ' after fixing the error.');
    }

    private static function worker($id)
    {
        return Database::row("SELECT a.paused, a.worker_at, EXISTS (SELECT 1 FROM pg_locks l WHERE l.locktype = 'advisory' AND l.database = (SELECT oid FROM pg_database WHERE datname = current_database()) AND l.classid = 74296 AND l.objid = a.id::oid AND l.objsubid = 2 AND l.granted AND l.pid <> pg_backend_pid()) AS alive FROM agents a WHERE a.id = ?", [$id]);
    }

    private static function identity()
    {
        if (!Runtime::isCli() || ToolContext::get('frontend')) throw new \RuntimeException('Agent teams currently require a local CLI identity');
        $identity = Cli::identity();
        if ($identity['unix']['uid'] === null) throw new \RuntimeException('Agent teams require an identified Unix user');
        return $identity;
    }

    private static function logFlags()
    {
        return Log::$minConsolePriority <= Log::VERBOSE ? ' --verbose' : (Log::$minConsolePriority <= Log::DEBUG ? ' --debug' : '');
    }

    private static function command($agentId, $runId = null)
    {
        // Redirecting 0/1/2 alone leaves Terminal's duplicate pipe handles inherited and keeps callers blocked.
        // Close every other inherited descriptor before PHP boots (also prevents leaking the supervisor's DB socket).
        $launch = 'for phill_fd_path in /proc/self/fd/*; do phill_fd=${phill_fd_path##*/}; if [[ $phill_fd =~ ^[0-9]+$ ]] && (( phill_fd > 2 )); then exec {phill_fd}>&-; fi; done; exec "$@"';
        return '/bin/bash -c ' . escapeshellarg($launch) . ' phill-agent ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(Runtime::root() . '/bin/phill-agent-worker')
            . self::logFlags() . ' ' . (int)$agentId . ($runId !== null ? ' ' . (int)$runId : '');
    }

    private static function terminate($process, $runId)
    {
        $status = proc_get_status($process);
        if (!$status['running']) return;
        WorkspaceAccess::stopProcesses($runId);
        proc_terminate($process, 9);
    }

    private static function current()
    {
        $id = ToolContext::get('agent_id');
        return $id ? Database::row('SELECT * FROM agents WHERE id = ?', [self::positive($id)]) : null;
    }

    private static function visible(array $row)
    {
        $current = self::current();
        if (!$current) return !ToolContext::get('run_id') || (ToolContext::get('thread_id') && (string)$row['parent_thread_id'] === (string)ToolContext::get('thread_id'));
        if ((int)$row['id'] === (int)$current['id']) return false;
        $parent = $row['parent_id'];
        for ($depth = 0; $parent !== null && $depth < 2; $depth++) {
            if ((int)$parent === (int)$current['id']) return true;
            $ancestor = Database::row('SELECT parent_id FROM agents WHERE id = ?', [$parent]);
            $parent = $ancestor ? $ancestor['parent_id'] : null;
        }
        return false;
    }

    private static function ceiling(array $tools)
    {
        $runId = ToolContext::get('run_id');
        if (!$runId) throw new \RuntimeException('Delegation requires a running parent tool context');
        $run = Run::get($runId); $snapshot = json_decode($run['settings'], true);
        $allowed = isset($snapshot['tools']) ? $snapshot['tools'] : [];
        foreach ($tools as $tool) {
            $permitted = false;
            foreach ($allowed as $grant) {
                if (strcasecmp(ltrim($grant, '\\'), ltrim($tool, '\\')) === 0 || (strpos($grant, '::') === false && strcasecmp(ltrim($grant, '\\'), ltrim(explode('::', $tool)[0], '\\')) === 0)) { $permitted = true; break; }
            }
            if (!$permitted) throw new \RuntimeException('Delegated tool exceeds the parent grant: ' . $tool);
        }
    }

    private static function failed($id, $message)
    {
        State::transaction(function () use ($id, $message) {
            $run = Run::get($id);
            Database::row('SELECT id FROM threads WHERE id = ? FOR UPDATE', [$run['thread_id']]);
            if (Run::get($id)['status'] !== 'running') return;
            WorkspaceAccess::stopProcesses($id);
            Run::cleanupPending($id);
            Database::execute("UPDATE runs SET status = 'failed', error = ?, finished_at = NOW() WHERE id = ?", [$message, $id]);
            Run::event($id, ['type' => 'run.failed', 'data' => ['error' => $message]]);
        });
    }

    private static function describe(array $row)
    {
        foreach (['id', 'thread_id', 'unix_uid', 'depth'] as $key) $row[$key] = (int)$row[$key];
        $row['parent_id'] = $row['parent_id'] === null ? null : (int)$row['parent_id'];
        $row['parent_thread_id'] = $row['parent_thread_id'] === null ? null : (int)$row['parent_thread_id'];
        $row['paused'] = self::truth($row['paused']);
        $settings = (new Thread($row['thread_id']))->get()['settings'];
        $profile = isset($settings['profile']) && is_array($settings['profile']) ? $settings['profile'] : [];
        $row['assistant_name'] = isset($settings['assistant_name']) ? $settings['assistant_name'] : null;
        $row['model'] = isset($profile['model']) ? $profile['model'] : (isset($profile['body']['model']) ? $profile['body']['model'] : null);
        $row['tools'] = isset($settings['tools']) ? $settings['tools'] : [];
        $row['contract'] = isset($settings['contract']) ? $settings['contract'] : null;
        $runs = Database::rows("SELECT id, status FROM runs WHERE thread_id = ? AND status IN ('queued', 'running') ORDER BY id", [$row['thread_id']]);
        $row['status'] = $row['paused'] ? 'paused' : 'idle'; $row['queued'] = 0; $row['active_run_id'] = null;
        foreach ($runs as $run) {
            if ($run['status'] === 'running') { $row['status'] = 'running'; $row['active_run_id'] = (int)$run['id']; }
            else { $row['queued']++; if (!$row['paused'] && $row['status'] !== 'running') $row['status'] = 'queued'; }
        }
        return $row;
    }

    private static function label($value, $description)
    {
        if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > 160 || preg_match('/[\x00-\x1f\x7f]/', $value) || !preg_match('//u', $value)) throw new \InvalidArgumentException($description . ' must be 1..160 UTF-8 bytes without control characters');
        return trim($value);
    }
    private static function positive($value)
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($number === false) throw new \InvalidArgumentException('A positive integer ID is required');
        return $number;
    }
    private static function truth($value) { return $value === true || $value === 1 || $value === '1' || $value === 't'; }
}

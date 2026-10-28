<?php
namespace Agent;

/** Thin machine-readable adapter for the durable project service used by the future TUI. */
class ProjectCli
{
    const ACTIONS = ['list', 'create', 'show', 'plan', 'result', 'add', 'update', 'remove-todo', 'preflight', 'prepare', 'start', 'pause', 'retry', 'delete', 'events', 'trace', 'wait', 'answer', 'decisions', 'help', '--help', '-h'];
    const MUTATIONS = ['create', 'plan', 'add', 'update', 'remove-todo', 'preflight', 'prepare', 'start', 'pause', 'retry', 'delete', 'answer'];

    public static function run(array $args)
    {
        Log::configure(['cli_stream' => 'stderr']);
        try {
            $command = self::parse($args);
            $action = $command['action'];
            $words = $command['words'];
            $options = $command['options'];
            if ($action === 'help') {
                Terminal::write(self::help(), 'stdout');
                return 0;
            }
            $unix = Runtime::unixIdentity();
            if (in_array($action, self::MUTATIONS, true) && ($unix['root'] || $unix['uid'] === null)) {
                throw new \RuntimeException('Project mutations require an unprivileged Unix identity; root may read only');
            }
            $identity = Cli::identity();
            switch ($action) {
                case 'list':
                    $result = self::listProjects($identity, !empty($options['compact']));
                    break;
                case 'create':
                    $result = self::createProject($words[0], $options, $identity);
                    break;
                case 'show':
                    $project = self::existingProject($words[0], $identity);
                    $result = !empty($options['compact']) ? self::summary($project) : $project->snapshot();
                    break;
                case 'plan':
                    $project = self::existingProject($words[0], $identity);
                    $task = Cli::input(implode(' ', array_slice($words, 1)));
                    $run = $project->plan($identity['owner'], $task);
                    $result = ['project_id' => $project->id, 'thread_id' => $run['thread_id'], 'run_id' => $run['id'], 'status' => $run['status']];
                    break;
                case 'result':
                    $project = self::existingProject($words[0], $identity);
                    $result = self::planningResult($project);
                    break;
                case 'add':
                    $project = self::existingProject($words[0], $identity);
                    $result = $project->addTodo(self::jsonPayload(array_slice($words, 1)));
                    break;
                case 'update':
                    $project = self::existingProject($words[0], $identity);
                    $result = $project->updateTodo((int)$words[1], self::jsonPayload(array_slice($words, 2)));
                    break;
                case 'remove-todo':
                    $project = self::existingProject($words[0], $identity);
                    $project->removeTodo((int)$words[1], !empty($options['descendants']));
                    $result = ['deleted' => true];
                    break;
                case 'preflight':
                    $project = self::existingProject($words[0], $identity);
                    $result = $project->requestPreflight();
                    break;
                case 'prepare':
                    $project = self::existingProject($words[0], $identity);
                    $project->requestPreflight();
                    $result = self::waitProject($project, isset($options['timeout']) ? $options['timeout'] : 30, !empty($options['compact']), 'ready');
                    break;
                case 'start':
                    $project = self::existingProject($words[0], $identity);
                    $result = $project->start($identity['owner']);
                    break;
                case 'pause':
                    $project = self::existingProject($words[0], $identity);
                    $result = $project->pause($identity['owner']);
                    break;
                case 'retry':
                    $project = self::existingProject($words[0], $identity);
                    $result = $project->retryTodo((int)$words[1]);
                    break;
                case 'delete':
                    if (empty($options['confirm'])) throw new \InvalidArgumentException('Deleting project history requires --confirm');
                    $project = self::existingProject($words[0], $identity);
                    $project->delete();
                    $result = ['deleted' => true, 'id' => $project->id];
                    break;
                case 'events':
                    $project = self::existingProject($words[0], $identity);
                    $result = $project->history(isset($options['after']) ? (int)$options['after'] : 0);
                    break;
                case 'trace':
                    $project = self::existingProject($words[0], $identity);
                    $result = self::trace($project, isset($words[1]) ? $words[1] : null, isset($options['after']) ? $options['after'] : 0);
                    break;
                case 'wait':
                    $project = self::existingProject($words[0], $identity);
                    $result = self::waitProject($project, isset($options['timeout']) ? (int)$options['timeout'] : 30, !empty($options['compact']), isset($options['until']) ? $options['until'] : 'completed');
                    break;
                case 'answer':
                    $project = self::existingProject($words[0], $identity);
                    $result = (new ProjectWorkflow($project))->answer($words[1], $words[2], $identity['owner']);
                    break;
                case 'decisions':
                    $project = self::existingProject($words[0], $identity);
                    $result = (new \Agent\ProjectWorkflow($project))->decisions();
                    break;
            }
            self::output($result);
            if ($action === 'wait' || $action === 'prepare') {
                $row = $project->get();
                if (!self::reached($row, $action === 'prepare' ? 'ready' : (isset($options['until']) ? $options['until'] : 'completed'))) return 3;
            }
            if ($action === 'result' && $result['status'] !== 'completed') return in_array($result['status'], ['failed', 'stopped'], true) ? 1 : 3;
            Log::writeSuccess('Project %s completed', $action);
            return 0;
        } catch (\InvalidArgumentException $error) {
            Log::writeError($error->getMessage());
            self::output(['error' => $error->getMessage()]);
            return 2;
        } catch (\Throwable $error) {
            Log::writeError($error->getMessage());
            self::output(['error' => $error->getMessage()]);
            return 1;
        }
    }

    /** Parsing is strict at this boundary: no command silently ignores a conflicting flag. */
    public static function parse(array $args)
    {
        $action = $args ? array_shift($args) : 'help';
        if (!in_array($action, self::ACTIONS, true)) throw new \InvalidArgumentException('Unknown project action. Use ok-phill projects help');
        if ($action === '--help' || $action === '-h') $action = 'help';
        $allowed = [
            'list' => ['compact'], 'create' => ['profile', 'tool'], 'show' => ['compact'], 'plan' => [], 'result' => [], 'add' => [], 'update' => [],
            'remove-todo' => ['descendants'], 'preflight' => [], 'start' => [], 'pause' => [], 'retry' => [],
            'prepare' => ['compact', 'timeout'],
            'events' => ['after'], 'trace' => ['after'], 'wait' => ['compact', 'timeout', 'until'], 'delete' => ['confirm'], 'answer' => [], 'decisions' => [], 'help' => []
        ];
        $minimum = ['list' => 0, 'create' => 1, 'show' => 1, 'plan' => 1, 'result' => 1, 'add' => 1, 'update' => 2,
            'remove-todo' => 2, 'preflight' => 1, 'start' => 1, 'pause' => 1, 'retry' => 2,
            'events' => 1, 'trace' => 1, 'prepare' => 1, 'wait' => 1, 'delete' => 1, 'answer' => 3, 'decisions' => 1, 'help' => 0];
        $exact = ['list', 'create', 'show', 'result', 'remove-todo', 'preflight', 'prepare', 'start', 'pause', 'retry', 'delete', 'events', 'wait', 'answer', 'decisions', 'help'];
        $names = ['-p' => 'profile', '--profile' => 'profile', '-t' => 'tool', '--tool' => 'tool',
            '--compact' => 'compact', '--confirm' => 'confirm', '--descendants' => 'descendants', '--after' => 'after', '--timeout' => 'timeout', '--until' => 'until'];
        $options = [];
        $words = [];
        $literal = false;
        for ($i = 0; $i < count($args); $i++) {
            $argument = $args[$i];
            if ($literal) { $words[] = $argument; continue; }
            if ($argument === '--') { $literal = true; continue; }
            if ($argument === '' || $argument[0] !== '-') { $words[] = $argument; continue; }
            $parts = explode('=', $argument, 2);
            $option = $parts[0];
            if (!isset($names[$option]) || !in_array($names[$option], $allowed[$action], true)) {
                throw new \InvalidArgumentException('Unsupported option ' . $option . ' for projects ' . $action);
            }
            $name = $names[$option];
            if (array_key_exists($name, $options)) throw new \InvalidArgumentException('Repeated option ' . $option);
            if (in_array($name, ['descendants', 'compact', 'confirm'], true)) {
                if (isset($parts[1])) throw new \InvalidArgumentException($option . ' does not take a value');
                $options[$name] = true;
                continue;
            }
            $value = isset($parts[1]) ? $parts[1] : (isset($args[$i + 1]) && substr($args[$i + 1], 0, 1) !== '-' ? $args[++$i] : null);
            if ($value === null || trim($value) === '') throw new \InvalidArgumentException('Missing value for ' . $option);
            if ($name === 'timeout') {
                $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 30]]);
                if ($value === false) throw new \InvalidArgumentException('Timeout must be an integer from 0 through 30');
            } elseif ($name === 'after') {
                $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($value === false) throw new \InvalidArgumentException('After must be an integer of at least 0');
            } elseif ($name === 'until' && !in_array($value, ['ready', 'completed'], true)) {
                throw new \InvalidArgumentException('Wait until must be ready or completed');
            }
            $options[$name] = $value;
        }
        if (count($words) < $minimum[$action]) throw new \InvalidArgumentException('projects ' . $action . ' requires at least ' . $minimum[$action] . ' argument(s)');
        if (in_array($action, $exact, true) && count($words) !== $minimum[$action]) {
            throw new \InvalidArgumentException('projects ' . $action . ' requires exactly ' . $minimum[$action] . ' argument(s)');
        }
        if ($action === 'trace') {
            if (count($words) > 2) throw new \InvalidArgumentException('projects trace requires PROJECT and optional TODO');
            if (isset($words[1]) && (!preg_match('/^[1-9][0-9]*$/D', $words[1]) || filter_var($words[1], FILTER_VALIDATE_INT) === false)) throw new \InvalidArgumentException('Trace ToDo must be a positive integer');
        }
        return ['action' => $action, 'words' => $words, 'options' => $options];
    }

    /** Bound diagnostics to durable tool outcomes and run lifecycle, without arguments or reasoning. */
    private static function trace(Project $project, $todoId, $after)
    {
        $result = [];
        foreach ($project->activity($todoId, $after) as $event) {
            $data = is_array($event['data']) ? $event['data'] : json_decode($event['data'], true);
            $content = isset($data['content']) ? $data['content'] : (isset($data['error']) ? $data['error'] : (isset($data['reason']) ? $data['reason'] : ''));
            if (!is_string($content)) $content = State::encode($content);
            $result[] = [
                'id' => (int)$event['id'], 'run_id' => (int)$event['run_id'], 'todo_id' => $event['todo_id'] === null ? null : (int)$event['todo_id'],
                'type' => $event['type'], 'name' => isset($data['name']) ? $data['name'] : null,
                '_tool_error' => !empty($data['_tool_error']), 'content' => mb_strcut($content, 0, 2000, 'UTF-8'),
            ];
        }
        return $result;
    }

    private static function existingProject($ref, array $identity)
    {
        $project = new Project($ref);
        ProjectAccess::local($project);
        return $project;
    }

    private static function listProjects(array $identity, $compact)
    {
        $rows = Database::rows('SELECT p.id, p.thread_id, p.status, p.revision, p.preflight_status, p.preflight_revision, p.started_by, p.started_at, p.created_at, p.updated_at, t.title, t.owner_id, t.visibility, t.team_id, t.settings FROM projects p JOIN threads t ON t.id = p.thread_id WHERE t.owner_id = ? ORDER BY p.id', [(string)$identity['owner']]);
        if ($compact) {
            $summaries = [];
            foreach ($rows as $row) {
                $project = new Project($row);
                $summaries[] = self::summary($project);
            }
            return $summaries;
        }
        foreach ($rows as &$row) {
            $row = array_intersect_key($row, array_flip(['id', 'thread_id', 'title', 'status', 'revision', 'preflight_status', 'created_at']));
        }
        unset($row);
        return $rows;
    }

    private static function createProject($name, array $options, array $identity)
    {
        if (!is_string($name) || trim($name) === '') throw new \InvalidArgumentException('Project name is required');
        $profile = isset($options['profile']) && is_string($options['profile']) ? $options['profile'] : 'default';
        Config::resolve($profile);
        $tools = [];
        if (isset($options['tool'])) {
            $requested = ToolRegistry::names($options['tool']);
            $tools = ProjectRunner::safeTools($requested);
            array_pop($tools); // Scoped Project tools are supplied by the runner, never a user grant.
            if ($tools !== $requested) throw new \InvalidArgumentException('Projects currently support web and memory tools only; workspace authority is not configured');
        }
        $settings = ['profile' => $profile, 'tools' => $tools, 'project_frontend' => false];
        $project = Project::create($identity['owner'], ['title' => trim($name), 'settings' => $settings]);
        return $project->get();
    }

    private static function summary(Project $project)
    {
        $row = $project->get();
        $todos = $project->todos();
        $progress = ['total' => count($todos), 'done' => 0, 'pending' => 0, 'working' => 0, 'review' => 0, 'blocked' => 0, 'waiting' => 0];
        foreach ($todos as $todo) $progress[$todo['status']]++;
        $questions = [];
        foreach ($todos as $todo) if (!empty($todo['wait_reason']) && $todo['wait_reason'] === 'decision') $questions[] = ['todo_id' => $todo['id'], 'title' => $todo['title'], 'wait_reason' => $todo['wait_reason']];
        $events = $project->history();
        $lastEvent = $events ? end($events) : null;
        $planning = self::planningResult($project);
        if ($planning['answer'] !== null) $planning['answer'] = mb_strcut($planning['answer'], 0, 1000, 'UTF-8');
        return [
            'id' => (string)$row['id'],
            'title' => $row['title'],
            'status' => $row['status'],
            'preflight' => $row['preflight_status'],
            'revision' => (int)$row['revision'],
            'readiness' => $row['preflight_result'],
            'progress' => $progress,
            'planning' => $planning,
            'activeTodo' => array_values(array_map(function ($todo) { return array_intersect_key($todo, array_flip(['id', 'title', 'status', 'attempts'])); }, array_filter($todos, function ($todo) { return in_array($todo['status'], ['working', 'review'], true); }))),
            'blockedTodos' => array_values(array_map(function ($todo) { return ['id' => $todo['id'], 'title' => $todo['title'], 'reason' => mb_strcut(isset($todo['review']['blocker']) ? $todo['review']['blocker'] : $todo['result'], 0, 500, 'UTF-8')]; }, array_filter($todos, function ($todo) { return $todo['status'] === 'blocked'; }))),
            'questions' => $questions,
            'lastEvent' => $lastEvent ? array_intersect_key($lastEvent, array_flip(['id', 'type', 'created_at'])) : null,
        ];
    }

    private static function planningResult(Project $project)
    {
        $run = Database::row('SELECT id, status, result, error FROM runs WHERE thread_id = ? ORDER BY id DESC LIMIT 1', [$project->get()['thread_id']]);
        $response = $run && $run['result'] ? json_decode($run['result'], true) : [];
        return ['project_id' => $project->id, 'run_id' => $run ? $run['id'] : null, 'status' => $run ? $run['status'] : 'idle', 'answer' => isset($response['content']) ? $response['content'] : null, 'error' => $run ? $run['error'] : null];
    }

    private static function jsonPayload(array $words)
    {
        $text = $words ? implode(' ', $words) : '';
        if ($text === '') {
            if (!Terminal::isTTY('stdin')) $text = Cli::input('');
            if ($text === '') throw new \InvalidArgumentException('JSON payload is required as an argument or piped stdin');
        }
        return Config::object($text, 'ToDo payload');
    }

    private static function reached(array $row, $until)
    {
        return $until === 'ready' ? ($row['preflight_status'] === 'ready' && $row['preflight_revision'] === $row['revision']) : $row['status'] === 'completed';
    }

    private static function waitProject(Project $project, $timeout, $compact, $until)
    {
        $deadline = microtime(true) + (float)$timeout;
        do {
            $row = $project->get();
            if (self::reached($row, $until) || $row['status'] === 'blocked' || ($until === 'ready' && in_array($row['preflight_status'], ['gaps', 'error'], true))) {
                return $compact ? self::summary($project) : $project->snapshot();
            }
            usleep(250000);
        } while (microtime(true) < $deadline);
        $result = $compact ? self::summary($project) : $project->snapshot();
        return $result;
    }

    private static function output($value)
    {
        $flags = JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= constant('JSON_INVALID_UTF8_SUBSTITUTE');
        $json = json_encode($value, $flags);
        if ($json === false) throw new \RuntimeException('Could not encode project result as JSON: ' . json_last_error_msg());
        Terminal::write($json . "\n", 'stdout');
    }

    public static function help()
    {
        return "Usage: ok-phill projects ACTION [options]\n\n"
            . "list                                  List your local projects\n"
            . "create NAME [-p PROFILE] [-t TOOL_CSV]  Create a project; tools are limited to SearchWeb, Fetch, BrowseWeb, Memory\n"
            . "show PROJECT                          Inspect a project\n"
            . "plan PROJECT [PROMPT]                 Queue planning/check-in chat; stdin is supported\n"
            . "result PROJECT                        Read the latest planning/check-in response\n"
            . "add PROJECT JSON                      Add a draft ToDo (JSON argument or piped stdin)\n"
            . "update PROJECT TODO JSON              Update a ToDo (JSON argument or piped stdin)\n"
            . "remove-todo PROJECT TODO [--descendants]  Remove a ToDo and optionally its descendants\n"
            . "preflight PROJECT                     Request a preflight check\n"
            . "prepare PROJECT [--compact] [--timeout 0..30]  Request readiness and wait; never starts work\n"
            . "start PROJECT                         Start a project (requires a ready preflight)\n"
            . "pause PROJECT                         Pause a project\n"
            . "retry PROJECT TODO                    Retry a blocked ToDo\n"
            . "wait PROJECT [--until ready|completed] [--timeout 0..30]  Wait up to 30 seconds\n"
            . "answer PROJECT DECISION 'ANSWER'      Answer a root question as the local owner; quote multiword text\n"
            . "decisions PROJECT                     List current project decisions as the local owner\n"
            . "events PROJECT [--after ID]           Read incremental project history\n"
            . "trace PROJECT [TODO] [--after ID]     Read up to 100 tool/run outcomes; content bounded to 2000 bytes\n"
            . "\n"
            . "Exit codes: 0 success/completed, 1 execution error, 2 invalid usage, 3 wait not completed.\n"
            . "JSON on stdout; diagnostics on stderr. Root may read only; mutations require an unprivileged Unix identity.\n"
            . "No frontend sessions are accepted. Use -- before a literal project name beginning with a dash.\n"
            . "--compact returns an actionable summary for list, show and wait.\n";
    }
}

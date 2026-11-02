<?php
namespace Agent;

/**
 * Recursive language-model orchestration: a coordinator that can only create and message experts.
 * Experts are durable local Agents workers (separate processes), so one batch of assignments runs in parallel.
 * The public static methods are the coordinator's complete tool surface; the instance method run() is the entry point.
 */
class Rlm
{
    const ALLOWED_TOOLS = ['Agent\\Tools\\Source', 'Agent\\Tools\\Patch', 'Agent\\Tools\\Verify', 'Agent\\Tools\\SearchWeb', 'Agent\\Tools\\Fetch', 'Agent\\Tools\\BrowseWeb'];
    const WRITE_TOOLS = ['Agent\\Tools\\Patch'];
    private static $profile = 'default';
    private static $team = '';
    private static $experts = [];

    private static $coordinator = <<<'TXT'
You are Agent A, an orchestrator. You cannot read files, see the prompt, use any tool except the three provided, or do the work yourself. You may only create experts and assign work to experts; an expert returns only its final response, never its reasoning or tool calls.

Your prompt is in a file whose path is given below; you never read it. Typical flow: create an expert (with file-reading tools) to read the prompt file, take notes and return a task breakdown. Create expert teams for the parallel tasks in that breakdown. As results return, create and assign testers, reviewers, aggregators, polishers, fact checkers and others as the task requires.

Rules:
- Experts share nothing with each other except the workspace. Write complete briefs: exact workspace-relative paths, needed evidence, expected deliverable. Experts can pass large artifacts to one another by writing files and naming the path.
- message_expert takes a list of assignments and runs them in parallel; batch independent work into one call. An expert's own tasks run in order.
- Expert names: letters, digits, dashes and underscores. At most 16 experts exist, so reuse them with follow-up assignments.
- Grant the minimum tools, as a CSV of Class::method names in allowed_tools. Tools you may grant:
  Source::files (find files), Source::read (open a file), Source::search (grep): read-only workspace access.
  Patch::replace (exact single-occurrence text edit), Patch::replaceLines (replace a line range; the expert passes the sha256 from Source::read, best for multi-line edits: tell writers to Source::read with numbered=true first), Patch::create (new file): these WRITE files. Give them ONLY to an expert whose task explicitly requires creating or modifying files, and name the exact paths it may touch in its system prompt. Readers, reviewers, testers and fact checkers get read-only tools.
  Verify::testUnits (run repository tests/*.php by name, up to 10 per call), Verify::lint (syntax-check one .php/.js/.py file): verification only; give them to testers and reviewers, with Source::read so they can interpret failures. There is no other way to run commands.
  SearchWeb::search, Fetch::fetch (read a URL), and the whole BrowseWeb class for JavaScript pages: web access.
  Nothing else exists. An expert with no tools can only reason over what you put in its brief.
- Every expert reply enters your context permanently. Never ask an expert to return file contents or long reports verbatim: ask for conclusions and short findings (a few hundred words), and have experts exchange bulk material through files they write, naming the path. Only the final deliverable may be long.
- Use a response contract (JSON schema) when you need structured output.

When the work is finished, reply with the final deliverable verbatim as your last message, with no tool calls. Do not summarize away content the prompt asked for: assign an expert to produce the complete final text, and return it.
TXT;

    public function run($promptFile, $profile = 'default')
    {
        self::$profile = $profile;
        self::$team = 'rlm-' . getmypid() . '-' . time();
        $path = realpath($promptFile);
        if ($path === false || !is_file($path) || !is_readable($path)) throw new \InvalidArgumentException('Prompt file is not a readable file: ' . $promptFile);
        // Source and Patch are bound to the current directory; never switch it silently.
        $cwd = realpath(getcwd());
        if (strpos($path, $cwd . '/') !== 0) throw new \InvalidArgumentException("Prompt file must be inside the current workspace ($cwd): run from a directory that contains it");
        $relative = substr($path, strlen($cwd) + 1);
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM] as $signal) pcntl_signal($signal, function () { self::cleanup(); exit(130); });
        }
        try {
            $config = Cli::configuration(['profile' => $profile, 'profile_requested' => true, 'persona' => null, 'tools' => [], 'tools_requested' => true, 'contract' => null]);
            $config = array_replace($config, ['system_prompt' => self::$coordinator, 'assistant_name' => 'Agent A', 'max_rounds' => 500, 'tools' => [self::class], 'tools_enabled' => true]);
            $request = new Request($config);
            $unavailable = Requirements::unavailableSummary(self::ALLOWED_TOOLS);
            $request->addMessage(['role' => 'user', 'content' => "The prompt is in the file `$relative` (workspace-relative; " . filesize($path) . " bytes)."
                . ($unavailable ? ' These tools are currently unavailable and cannot be granted: ' . implode('; ', $unavailable) . '.' : '') . ' Begin.']);
            $recoveries = 0;
            for (;;) {
                try {
                    return $request->send();
                } catch (\RuntimeException $e) {
                    if ($recoveries >= 2 || strpos($e->getMessage(), 'Model output token limit reached') !== 0) {
                        throw $e;
                    }
                    Log::writeWarning('Model output truncated by token limit; recovery %d/2: %s', $recoveries + 1, $e->getMessage());
                    $request->addMessage(['role' => 'user', 'content' => 'Your previous reply was cut off by the output limit. Do not repeat it. Continue: either call a tool with short arguments, or give the final answer concisely.']);
                    $recoveries++;
                }
            }
        } finally { self::cleanup(); }
    }

    private static function cleanup()
    {
        $deadline = microtime(true) + 20.0;
        // 1. Request stop for every expert first.
        foreach (self::$experts as $name => $expert) {
            try { Agents::stop($expert['id']); } catch (\Throwable $e) { /* nothing running */ }
        }
        $lastStop = microtime(true);
        // 2. Poll until no latest run is queued/running; re-stop stragglers ~every 3s.
        while (microtime(true) < $deadline) {
            $active = false;
            $now = microtime(true);
            foreach (self::$experts as $name => $expert) {
                try { $status = Agents::result($expert['id'])['status'] ?? null; } catch (\Throwable $e) { $status = null; }
                if ($status === 'queued' || $status === 'running') {
                    $active = true;
                    if ($now - $lastStop >= 3.0) {
                        try { Agents::stop($expert['id']); } catch (\Throwable $e) { /* nothing running */ }
                    }
                }
            }
            if (!$active) {
                break;
            }
            if (microtime(true) - $lastStop >= 3.0) {
                $lastStop = microtime(true);
            }
            usleep(500000);
        }
        // 3. Then remove each expert; actionable warning on failure.
        foreach (self::$experts as $name => $expert) {
            try { Agents::remove($expert['id']); } catch (\Throwable $e) {
                Log::writeWarning('Could not remove expert %s: %s. Try: ok-phill agents remove %s', $name, $e->getMessage(), $name);
            }
        }
        self::$experts = [];
    }

    /**
     * Create a named expert agent. It has its own system prompt and only the allowed tools; nothing is shared with other experts but the workspace.
     * @param string $name Unique expert name: letters, digits, dashes and underscores
     * @param string $system_prompt Complete system prompt defining the expert's role and rules
     * @param string $allowed_tools Optional CSV of Class::method names from your system prompt's tool list; empty means no tools. Write tools only when the expert's task requires writing files
     * @param string $response_contract Optional JSON schema the expert's final answer must match
     * @param string $sampling_overrides Optional JSON object of sampling parameters such as {"temperature":0.2,"top_p":0.9,"max_tokens":4000}
     */
    public static function create_expert(string $name, string $system_prompt, string $allowed_tools = '', string $response_contract = '', string $sampling_overrides = '')
    {
        $name = trim($name);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $name) || isset(self::$experts[$name])) throw new \InvalidArgumentException('Expert name must be unique, 1-64 letters, digits, dashes or underscores');
        if (trim($system_prompt) === '') throw new \InvalidArgumentException('Expert system prompt is required');
        $tools = ToolRegistry::names($allowed_tools);
        $canonical = self::canonicalTools($tools);
        $tools = $canonical['tools'];
        $writes = $canonical['writes'];
        $profile = self::$profile;
        $sampling_overrides = self::optionalJson($sampling_overrides);
        if ($sampling_overrides !== null) {
            $sampling = json_decode($sampling_overrides, true);
            if (!is_array($sampling)) throw new \InvalidArgumentException('Sampling overrides must be a JSON object');
            unset($sampling['messages'], $sampling['tools'], $sampling['tool_choice'], $sampling['response_format'], $sampling['stream'], $sampling['model']);
            $profile = Config::protect(array_replace_recursive(Config::resolve($profile), ['body' => $sampling]));
        }
        $options = ['persona' => ['name' => $name, 'prompt' => $system_prompt], 'tools' => $tools, 'profile' => $profile, 'team' => self::$team, 'workspace' => getcwd()];
        $response_contract = self::optionalJson($response_contract);
        if ($response_contract !== null) $options['contract'] = Schema::input($response_contract);
        $agent = Agents::create($name, $options);
        self::$experts[$name] = ['id' => $agent['id'], 'tools' => $tools, 'tasks' => 0];
        Log::writeInfo('Expert created: %s [%s]%s', $name, implode(',', $tools) ?: 'no tools', $writes ? ' WRITE' : '');
        return "Created expert '$name'.";
    }

    private static function optionalJson(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || in_array(strtolower($value), ['{}', '[]', 'null', 'none', '""'], true)) {
            return null;
        }
        return $value;
    }
    private static function canonicalTools(array $tools): array
    {
        $allowedBasenames = array_map(function ($t) { return basename(str_replace('\\', '/', $t)); }, self::ALLOWED_TOOLS);
        $canonical = [];
        $writes = false;
        foreach ($tools as $tool) {
            $parts = explode('::', $tool, 2);
            $class = $parts[0];
            $method = isset($parts[1]) ? $parts[1] : null;
            $classKey = basename(str_replace('\\', '/', $class));
            $canonicalClass = null;
            foreach (self::ALLOWED_TOOLS as $i => $allowed) {
                if (strcasecmp($classKey, $allowedBasenames[$i]) === 0) { $canonicalClass = $allowed; break; }
            }
            if ($canonicalClass === null) {
                throw new \InvalidArgumentException("Tool not available to experts: $tool. Allowed: " . implode(', ', $allowedBasenames));
            }
            $unmet = Requirements::tool($canonicalClass);
            if (!$unmet['available']) throw new \InvalidArgumentException("Tool unavailable until configured: $tool (" . implode('; ', array_column($unmet['missing'], 'reason')) . ')');
            if ($method !== null) {
                $reflection = new \ReflectionClass($canonicalClass);
                $canonicalMethod = null;
                foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                    if ($m->isStatic() && strcasecmp($m->getName(), $method) === 0) { $canonicalMethod = $m->getName(); break; }
                }
                if ($canonicalMethod === null) throw new \InvalidArgumentException("Unknown tool method: $tool");
                $canonicalTool = $canonicalClass . '::' . $canonicalMethod;
            } else {
                $canonicalTool = $canonicalClass;
            }
            if (in_array($canonicalClass, self::WRITE_TOOLS, true)) $writes = true;
            $canonical[] = $canonicalTool;
        }
        $canonical = array_values(array_unique($canonical));
        return ['tools' => $canonical, 'writes' => $writes];
    }

    /** List the experts created so far, with their tools and how many tasks each has been given. */
    public static function list_experts()
    {
        $list = [];
        foreach (self::$experts as $name => $expert) $list[] = ['name' => $name, 'tools' => $expert['tools'], 'tasks' => $expert['tasks']];
        return $list;
    }

    /**
     * Assign work to one or more experts, run all assignments in parallel, and return only each expert's final response. An expert remembers its earlier tasks, so follow-ups are allowed; it knows nothing else.
     * @param array $assignments List of objects, each {"expert": "name", "message": "complete task brief: exact paths, evidence needed, expected deliverable"}
     * @param string $expert Shortcut for a single assignment: the expert name (use with message instead of assignments)
     * @param string $message Shortcut for a single assignment: the complete task brief
     */
    public static function message_expert(array $assignments = [], string $expert = '', string $message = '')
    {
        // Forgiving shapes: flat expert/message arguments, or one assignment object instead of a list.
        if ($expert !== '' || $message !== '') $assignments[] = ['expert' => $expert, 'message' => $message];
        elseif (isset($assignments['expert']) || isset($assignments['message'])) $assignments = [$assignments];
        if (!$assignments) throw new \InvalidArgumentException('Provide at least one assignment');
        foreach ($assignments as $i => &$a) {
            if (is_object($a)) $a = (array)$a;
            if (!is_array($a) || !isset($a['expert'], $a['message']) || !is_string($a['expert']) || !is_string($a['message']) || trim($a['message']) === '') throw new \InvalidArgumentException("Assignment $i needs string 'expert' and nonempty 'message'");
            if (!isset(self::$experts[$a['expert']])) throw new \InvalidArgumentException("Unknown expert '{$a['expert']}'. Use list_experts.");
        }
        unset($a);
        $pending = $results = [];
        foreach (array_values($assignments) as $i => $a) {
            $expert = $a['expert'];
            Log::writeInfo('Assigning to %s: %s', $expert, mb_substr(preg_replace('/\s+/', ' ', $a['message']), 0, 120));
            try { $queued = Agents::assign(self::$experts[$expert]['id'], $a['message']); self::$experts[$expert]['tasks']++; $pending[$i] = [$expert, (int)$queued['run_id']]; }
            catch (\Throwable $e) { $results[$i] = ['expert' => $expert, 'status' => 'failed', 'error' => 'ASSIGN FAILED: ' . $e->getMessage()]; }
        }
        $lastNudge = [];
        while ($pending) {
            $now = microtime(true);
            foreach ($pending as $i => list($expert, $run)) {
                $r = Agents::wait(self::$experts[$expert]['id'], $run, 2);
                if (in_array($r['status'], ['queued', 'running'], true)) {
                    if (!isset($lastNudge[$i]) || $now - $lastNudge[$i] >= 30) {
                        try {
                            $row = Run::get($run);
                            $stamp = 0;
                            foreach (['heartbeat_at', 'started_at', 'created_at'] as $field) {
                                $t = strtotime($row[$field] ?? '');
                                if ($t !== false && $t > $stamp) $stamp = $t;
                            }
                            if ($stamp > 0 && $now - $stamp > 45) {
                                $lastNudge[$i] = $now;
                                try { Agents::resume(self::$experts[$expert]['id']); } catch (\Throwable $e) { /* never throw */ }
                                Log::writeWarning('Expert %s looks stalled; restarting its supervisor', $expert);
                            }
                        } catch (\Throwable $e) { /* never throw */ }
                    }
                    continue;
                }
                Log::writeInfo('%s finished: %s', $expert, $r['status']);
                $results[$i] = $r['status'] === 'completed' ? ['expert' => $expert, 'status' => 'completed', 'answer' => $r['answer']]
                    : ['expert' => $expert, 'status' => $r['status'], 'error' => 'EXPERT FAILED: ' . ($r['error'] ?: $r['status'])];
                unset($pending[$i]);
            }
        }
        ksort($results);
        return array_values($results);
    }
}

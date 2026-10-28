<?php
namespace Agent;

/** Small command adapter. Requests, tools, contracts and persisted runs remain shared classes. */
class Cli
{
    const LOG_OPTIONS = ['verbose' => -4, 'debug' => -3];

    /** Remove process-wide logging switches before entrypoint-specific argument parsing. */
    public static function consumeLoggingOptions()
    {
        if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true) || !isset($GLOBALS['argv']) || !is_array($GLOBALS['argv'])) return null;
        $arguments = $GLOBALS['argv'];
        $clean = [];
        if ($arguments) $clean[] = $arguments[0];
        $priority = null;
        for ($i = 1; $i < count($arguments); $i++) {
            $argument = $arguments[$i];
            if ($argument === '--') {
                $clean = array_merge($clean, array_slice($arguments, $i));
                break;
            }
            $option = strpos($argument, '--') === 0 ? substr($argument, 2) : '';
            if (isset(self::LOG_OPTIONS[$option])) {
                $candidate = self::LOG_OPTIONS[$option];
                if ($priority !== -4 || $candidate === -4) $priority = $candidate;
                continue;
            }
            $clean[] = $argument;
        }
        $GLOBALS['argv'] = $clean;
        $GLOBALS['argc'] = count($clean);
        $_SERVER['argv'] = $clean;
        $_SERVER['argc'] = count($clean);
        return $priority;
    }

    public static function parse(array $args)
    {
        $result = ['profile' => 'default', 'profile_requested' => false, 'persona' => null, 'thread' => null, 'tools' => [], 'contract' => null, 'session' => null, 'prompt' => '', 'help' => false, 'tools_requested' => false];
        $words = []; $literal = false;
        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            if ($literal) { $words[] = $arg; continue; }
            if ($arg === '--') { $literal = true; continue; }
            if ($arg === '--help' || $arg === '-h') { $result['help'] = true; continue; }
            if ($arg === '' || $arg[0] !== '-') { $words[] = $arg; continue; }
            $parts = explode('=', $arg, 2); $option = $parts[0];
            $names = ['-p' => 'profile', '--profile' => 'profile', '--persona' => 'persona', '-T' => 'thread', '--thread-id' => 'thread', '-t' => 'tools', '--tool' => 'tools', '-c' => 'contract', '--contract' => 'contract', '-s' => 'session', '--session-id' => 'session'];
            if (!isset($names[$option])) throw new \InvalidArgumentException('Unknown option ' . $option . '. Use --help or -- before a prompt starting with a dash.');
            $value = isset($parts[1]) ? $parts[1] : (isset($args[$i + 1]) ? $args[++$i] : null);
            if ($value === null || $value === '') throw new \InvalidArgumentException('Missing value for ' . $option);
            $key = $names[$option];
            if ($key === 'thread') $value = trim($value);
            if ($key === 'profile') $result['profile_requested'] = true;
            if ($key === 'thread' && $value !== 'new' && (!preg_match('/^[1-9][0-9]*$/D', $value) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) throw new \InvalidArgumentException('Thread ID must be new or a positive integer');
            if ($key === 'tools') {
                $result['tools_requested'] = true;
                $result['tools'] = array_merge($result['tools'], ToolRegistry::names($value));
            } else $result[$key] = $value;
        }
        $result['tools'] = array_values(array_unique($result['tools'])); $result['prompt'] = implode(' ', $words);
        return $result;
    }
    public static function identity($session = null)
    {
        $unix = Runtime::unixIdentity();
        if ($session !== null && ($unix['root'] || $unix['uid'] === null)) throw new \RuntimeException('Root or unidentified processes cannot supply sessions');
        $user = null;
        if ($session !== null) $user = Auth::fromSession($session);
        elseif (!$unix['root'] && $unix['username'] !== null) {
            try {
                $user = Identity::user(['username' => $unix['username']]);
                if ($user && !$user['active']) $user = null;
            } catch (\Throwable $e) { /* No database or mapping means organization-only memory, never full access. */ }
        }
        Runtime::bindIdentity($user);
        return ['unix' => $unix, 'user' => $user, 'frontend' => $session !== null,
            'owner' => $user ? $user['id'] : 'cli:' . substr(hash('sha256', gethostname()), 0, 12) . ':' . ($unix['uid'] === null ? 'unknown' : $unix['uid'])];
    }
    public static function run(array $args)
    {
        // Management waits retain ordinary process signal handling; model requests use cooperative cancellation below.
        if (isset($args[0]) && $args[0] === 'agents') return AgentCli::run(array_slice($args, 1));
        if (isset($args[0]) && $args[0] === 'profiles') return ProfileCli::run(array_slice($args, 1));
        if (isset($args[0]) && $args[0] === 'workflows') return WorkflowCli::run(array_slice($args, 1));
        if (isset($args[0]) && $args[0] === 'projects') return ProjectCli::run(array_slice($args, 1));
        if (isset($args[0], $args[1]) && $args[0] === 'personas' && in_array($args[1], ['list', 'show', 'save', 'delete', 'help', '--help', '-h'], true)) return PersonaCli::run(array_slice($args, 1));
        $signal = 0;
        $control = function () use (&$signal) { if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch(); return $signal ? 2 : 0; };
        if (function_exists('pcntl_signal')) foreach ([SIGINT, SIGTERM] as $number) pcntl_signal($number, function ($received) use (&$signal) { $signal = $received; });
        try {
            Log::configure(['cli_stream' => 'stderr']);
            $options = self::parse($args);
            $profileSource = is_string($options['profile']) && substr(ltrim($options['profile']), 0, 1) === '{' ? 'inline-json' : 'reference';
            Log::writeDebug('CLI options parsed', ['profile_source' => $profileSource, 'tools' => count($options['tools']),
                'contract' => $options['contract'] !== null, 'session_requested' => $options['session'] !== null]);
            if ($options['help']) { Terminal::write(self::help(), 'stdout'); return 0; }
            $unix = Runtime::unixIdentity();
            if (($unix['root'] || $unix['uid'] === null) && ($options['tools'] || $options['session'] !== null)) throw new \RuntimeException('Root or unidentified processes cannot use tools or sessions. Run as an unprivileged Unix user.');
            $identity = self::identity($options['session']);
            Log::writeDebug('CLI identity mapped', ['identity_kind' => $identity['frontend'] ? 'session' : ($identity['user'] ? 'unix-mapped' : 'local-only'),
                'user_id' => $identity['user'] ? (string)$identity['user']['id'] : null, 'unix_uid' => $identity['unix']['uid']]);
            $thread = null;
            if ($options['thread'] !== null && $options['thread'] !== 'new') {
                $thread = new Thread($options['thread']);
                self::ownThread($thread, $identity);
            }
            if ($options['persona'] !== null) $options['persona'] = Personas::resolve($options['persona'], $identity['owner']);
            $savedSettings = $thread !== null ? $thread->get()['settings'] : [];
            $requiresContract = $options['contract'] !== null || !empty($options['persona']['contract']) || !empty($savedSettings['persona_defaults']['contract']);
            $prompt = $options['prompt'];
            $readStdin = false;
            if (!Terminal::isTTY('stdin')) {
                $readStdin = true;
                $prompt = self::input($prompt);
            }
            if ($control() >= 2) throw new Cancelled('Interrupted');
            $health = trim($prompt) === '';
            Log::writeDebug('CLI input prepared', ['prompt_bytes' => strlen($prompt), 'stdin' => $readStdin, 'health_request' => $health]);
            try { $config = self::configuration($options, $thread); }
            catch (\Throwable $e) {
                if (!$health || $requiresContract || $options['persona'] !== null) throw $e;
                Log::writeWarning($e->getMessage()); $config = null;
            }
            if ($health) {
                $facts = ToolContext::withValues(['control' => $control], function () use ($config) { return SystemInfo::collect($config); });
                $prompt = "Greet the operator and briefly report server/service concerns using only these health facts. Do not claim an unavailable or unconfigured check is healthy. Keep the answer under 150 words. Usage guidance: " . self::hint() . "\n" . json_encode($facts);
                if ($config === null) {
                    Terminal::write("Hello! PHiLLiP has no usable model profile yet. Configure a default profile through onboarding or profile JSON.\n" . self::healthSummary($facts) . "\n\n" . self::hint() . "\n", 'stdout'); return 0;
                }
            }
            if ($config !== null) self::authorizeTools($config, $identity);
            $needsThread = $identity['frontend'] || $options['thread'] !== null;
            foreach (isset($config['tools']) ? $config['tools'] : [] as $tool) if (in_array(explode('::', $tool)[0], ['Agent\\Tools\\Memory', 'Agent\\Tools\\Delegate'], true)) $needsThread = true;
            Log::writeDebug('CLI request starting', ['request_mode' => $needsThread ? 'thread' : 'stateless',
                'profile_configured' => $config !== null, 'tool_count' => isset($config['tools']) ? count($config['tools']) : 0, 'contract' => !empty($config['contract'])]);
            $bufferLevel = ob_get_level();
            ob_start(function ($output) { if ($output !== '') Log::writeDebug('Callable output: %s', $output); return ''; });
            try {
                if ($needsThread) {
                    $run = State::transaction(function () use (&$thread, $identity, $options, $config, $prompt) {
                        if ($thread === null) $thread = Thread::create($identity['owner'], 'CLI: ' . mb_substr($options['prompt'] ?: 'Server health', 0, 70), ['profile' => $config, 'manual' => false]);
                        $db = Database::connection();
                        $db->prepare('SELECT id FROM threads WHERE id = ? FOR UPDATE')->execute([$thread->id]);
                        self::ownThread($thread, $identity);
                        $q = $db->prepare("SELECT 1 FROM runs WHERE thread_id = ? AND status IN ('queued', 'running') LIMIT 1");
                        $q->execute([$thread->id]);
                        if ($q->fetchColumn()) throw new \RuntimeException('Thread is busy with a queued or running response. Wait for it to finish or stop it before continuing.');
                        // Claim under the row lock before commit: no temporary manual flag is necessary.
                        if ($options['thread'] !== null && $options['thread'] !== 'new' && !$options['profile_requested']) $config = self::configuration($options, $thread);
                        self::authorizeTools($config, $identity);
                        $updates = ['profile' => $config, 'tools' => $config['tools'], 'contract' => isset($config['contract']) ? $config['contract'] : null, 'parameters' => [],
                            'execution_context' => ['memory_restricted' => true, 'user_id' => $identity['user'] ? $identity['user']['id'] : null, 'memory_owner' => $identity['owner']]];
                        if ($options['persona'] !== null) $updates += ['assistant_name' => $config['assistant_name'], 'persona_defaults' => ['tools' => $options['persona']['tools'], 'contract' => $options['persona']['contract']]];
                        $thread->configure($updates, $options['persona'] !== null ? $config['system_prompt'] : null);
                        $run = $thread->enqueue($identity['owner'], $prompt, $identity['frontend']);
                        $claimed = Run::claim($thread->id);
                        if (!$claimed || (string)$claimed['id'] !== (string)$run['id']) throw new \RuntimeException('Could not claim this CLI response safely; retry after checking the thread queue.');
                        return $run;
                    });
                    // Auto-persisted Memory/Delegate conversations need the same continuation handle as -T.
                    Log::with(['console' => Log::INFO], function () use ($thread) {
                        Log::writeInfo('Thread %d', $thread->id);
                    });
                    $answer = Run::execute($run['id'], $control);
                    Log::writeDebug('Saved CLI conversation %d', $thread->id);
                } else {
                    $config['control'] = $control;
                    $request = new Request($config); $request->addMessage($prompt);
                    $answer = ToolContext::withValues(['memory_restricted' => true, 'user_id' => $identity['user'] ? $identity['user']['id'] : null, 'memory_owner' => $identity['owner']], function () use ($request) { return $request->send(); });
                }
            } finally { while (ob_get_level() > $bufferLevel) ob_end_flush(); }
            if ($signal) throw new Cancelled('Interrupted');
            Log::writeDebug('CLI request completed', ['answer_bytes' => strlen((string)$answer), 'health_request' => $health, 'thread_saved' => $thread !== null]);
            Log::writeSuccess('Response completed');
            Terminal::clearProgress(); Terminal::write(Terminal::strip($answer) . ($health && $options['contract'] === null && strpos($answer, "ok-phill") === false ? "\n\n" . self::hint() : '') . "\n", 'stdout');
            return 0;
        } catch (Cancelled $e) { Terminal::clearProgress(); Log::writeWarning('Interrupted'); return $signal ? 128 + $signal : 130; }
        catch (\Throwable $e) {
            Terminal::clearProgress();
            if ($e instanceof \PDOException && (!empty($needsThread) || !empty($options['session']) || isset($options['thread']))) Log::writeError('Saved conversations, memory, and session-backed execution require a reachable PostgreSQL database and completed onboarding/migrations. %s', $e->getMessage());
            else Log::writeError($e->getMessage());
            if (isset($health, $facts) && $health && !$requiresContract) { Terminal::write("Hello! The selected model could not produce a health greeting.\n" . self::healthSummary($facts) . "\n\n" . self::hint() . "\n", 'stdout'); return 0; }
            return $e instanceof \InvalidArgumentException ? 2 : 1;
        }
    }
    private static function ownThread(Thread $thread, array $identity)
    {
        if ((string)$thread->get()['owner_id'] !== (string)$identity['owner']) throw new \RuntimeException('Thread continuation requires a thread owned by the current CLI identity');
        if ($identity['frontend']) Access::owner($identity['user']['id'], $thread);
    }
    public static function configuration(array $options, $thread = null)
    {
        $settings = $thread !== null ? $thread->get()['settings'] : [];
        if ($thread !== null && !$options['profile_requested']) {
            $config = array_replace(Config::resolve($settings['profile']), Config::resolve($settings['parameters']));
        } else $config = Config::resolve($options['profile']);
        // Each invocation grants tools afresh; a saved profile cannot inject controls or history.
        unset($config['tools'], $config['control'], $config['on_event'], $config['before_tool'], $config['contract'], $config['persona'], $config['persona_defaults'], $config['system_prompt'], $config['assistant_name']);
        foreach (['tools', 'tool_choice', 'functions', 'function_call', 'messages', 'response_format'] as $key) if (isset($config['body'])) unset($config['body'][$key]);
        unset($config['tool_choice'], $config['functions'], $config['function_call'], $config['messages'], $config['response_format']);
        $defaults = isset($settings['persona_defaults']) ? $settings['persona_defaults'] : [];
        if (isset($settings['assistant_name'])) $config['assistant_name'] = $settings['assistant_name'];
        if ($options['persona'] !== null) {
            $defaults = Personas::settings($options['persona']);
            $config['system_prompt'] = $defaults['system_prompt']; $config['assistant_name'] = $defaults['assistant_name'];
        }
        $config['tools'] = $options['tools_requested'] ? $options['tools'] : ToolRegistry::names(isset($defaults['tools']) ? $defaults['tools'] : []);
        $config['tools_enabled'] = (bool)$config['tools'];
        if (array_key_exists('contract', $defaults)) $config['contract'] = $defaults['contract'];
        $config['stream'] = false;
        if (isset($config['body']['stream'])) $config['body']['stream'] = false;
        if ($options['contract'] !== null) $config['contract'] = Schema::input($options['contract']);
        return $config;
    }
    public static function authorizeTools(array $config, array $identity)
    {
        if (($identity['unix']['root'] || $identity['unix']['uid'] === null) && !empty($config['tools'])) throw new \RuntimeException('Root or unidentified processes cannot use tools, including persona defaults');
        foreach (isset($config['tools']) ? $config['tools'] : [] as $tool) {
            if ($identity['frontend'] && !Access::tool($identity['user']['id'], explode('::', $tool)[0])) throw new \RuntimeException('Tool permission denied: ' . $tool);
        }
    }
    private static function healthSummary(array $facts)
    {
        $parts = [];
        foreach ($facts['checks'] as $name => $check) $parts[] = $name . ': ' . $check['status'];
        return implode('; ', $parts) . '.';
    }
    /** Shared bounded terminal input; management commands and ordinary prompts accept the same data. */
    public static function input($prompt = '')
    {
        if (Terminal::isTTY('stdin')) return $prompt;
        $input = stream_get_contents(Terminal::stream('stdin'), 16777217);
        if ($input === false) throw new \RuntimeException('Could not read piped input');
        if (strlen($input) > 16777216) throw new \InvalidArgumentException('Piped input exceeds 16 MiB; supply a smaller excerpt');
        if (!mb_check_encoding($input, 'UTF-8')) { Log::writeWarning('Piped input is not UTF-8; interpreting it as Windows-1252 text'); $input = mb_convert_encoding($input, 'UTF-8', 'Windows-1252'); }
        return $input === '' ? $prompt : ($prompt === '' ? $input : $prompt . "\n\n" . $input);
    }
    private static function hint()
    {
        return is_executable(Runtime::root() . '/bin/phill') ? "Run 'ok-phill <PROMPT>' to talk or just run 'phill' to chat and do tasks."
            : "Run 'ok-phill <PROMPT>' to talk. The 'phill' chat/task interface is coming next.";
    }
    public static function help()
    {
        return "Usage: ok-phill [options] [--] [PROMPT]\n\n-p, --profile     Profile ID, name, or inline JSON configuration\n--persona         Saved persona ID or unambiguous visible name\n-T, --thread-id   new to save a conversation, or its ID to continue\n-t, --tool        Named tools as CSV; none disables persona defaults\n-c, --contract    JSON contract or path; {} disables the default\n-s, --session-id  Existing authenticated PHP session (not allowed as root)\n--verbose         Show verbose logging for this invocation\n--debug           Show debug logging for this invocation\n-h, --help        Show this help\n\nPiped stdin is input; with a prompt it is appended as data. No input produces a server-health greeting.\nThread IDs are reported on stderr. Continuations reuse the saved profile unless -p is supplied.\nPersona defaults are copied into the thread; -t/-c override them for this invocation.\nWithout a persona, tools require -t on every invocation. Busy threads cannot be continued.\nOnly the final answer goes to stdout; diagnostics go to stderr. Root cannot use tools or sessions.\n\nUse 'ok-phill agents help' to manage durable workers and queued assignments.\nUse 'ok-phill profiles help' to inspect available model profiles.\nUse 'ok-phill personas help' to manage personas without the web interface.\nUse 'ok-phill projects help' to manage durable projects and completion gates.\nUse 'ok-phill workflows help' to inspect and run saved workflows.\n";
    }
}

<?php
namespace Agent;

/** Thin machine-readable adapter for the durable agent service used by the future TUI. */
class AgentCli
{
    const ACTIONS = ['create', 'configure', 'remove', 'assign', 'message', 'list', 'show', 'result', 'wait', 'events', 'pause', 'resume', 'stop', 'approve', 'revoke', 'work', 'peek', 'help', '--help', '-h'];

    public static function run(array $args)
    {
        Log::configure(['cli_stream' => 'stderr']);
        try {
            $command = self::parse($args);
            $action = $command['action']; $words = $command['words']; $options = $command['options'];
            if ($action === 'help') { Terminal::write(self::help(), 'stdout'); return 0; }
            Log::writeDebug('Agent management action', ['action' => $action]);
            switch ($action) {
                case 'create':
                case 'configure':
                    if (isset($options['tools'])) $options['tools'] = ToolRegistry::names($options['tools']);
                    if (isset($options['contract'])) $options['contract'] = Schema::input($options['contract']);
                    $result = $action === 'create' ? Agents::create($words[0], $options) : Agents::configure($words[0], $options); break;
                case 'assign':
                    $task = Cli::input(implode(' ', array_slice($words, 1)));
                    if (trim($task) === '') throw new \InvalidArgumentException('Assignment requires a task or piped input');
                    $result = Agents::assign($words[0], $task, isset($options['key']) ? $options['key'] : null); break;
                case 'message':
                    $result = Agents::message($words[0], Cli::input(implode(' ', array_slice($words, 1)))); break;
                case 'list': $result = Agents::all(isset($options['team']) ? $options['team'] : null); break;
                case 'show': $result = Agents::get($words[0], true); break;
                case 'result': $result = Agents::result($words[0], isset($options['run']) ? $options['run'] : null); break;
                case 'wait': $result = Agents::wait($words[0], isset($options['run']) ? $options['run'] : null, isset($options['timeout']) ? $options['timeout'] : 30); break;
                case 'events': $result = Agents::events($words[0], isset($options['after']) ? $options['after'] : 0); break;
                case 'pause': $result = Agents::pause($words[0]); break;
                case 'resume': $result = Agents::resume($words[0]); break;
                case 'remove': $result = Agents::remove($words[0]); break;
                case 'approve':
                case 'revoke':
                    $agent = Agents::get($words[0]);
                    if (isset($options['command'])) {
                        $target = WorkspaceAccess::command($options['command']);
                        $permission = 'command';
                    } else { $target = $options['file']; $permission = isset($options['read']) ? 'read' : 'patch'; }
                    $previous = getcwd(); $workspace = realpath($agent['workspace']);
                    if ($workspace === false || !chdir($workspace)) throw new \RuntimeException('Could not enter the agent workspace');
                    try { $result = $action === 'approve' ? WorkspaceAccess::approve($agent['thread_id'], $permission, $target) : WorkspaceAccess::revoke($agent['thread_id'], $permission, $target); }
                    finally { if ($previous !== false) chdir($previous); }
                    break;
                case 'stop':
                    if (isset($options['team'])) {
                        $result = [];
                        foreach (Agents::all($options['team']) as $agent) $result[] = Agents::stop($agent['id']);
                    } else $result = Agents::stop($words[0]);
                    break;
                case 'work':
                    $task = Cli::input(implode(' ', array_slice($words, 1)));
                    $files = isset($options['file']) ? array_values(array_unique((array)$options['file'])) : [];
                    $commands = [];
                    foreach (isset($options['command']) ? (array)$options['command'] : [] as $command) {
                        $commands[] = WorkspaceAccess::command($command);
                    }
                    $workOptions = [];
                    foreach (['persona', 'profile', 'tools', 'contract', 'team', 'workspace', 'key'] as $key) {
                        if (array_key_exists($key, $options)) $workOptions[$key] = $options[$key];
                    }
                    $workOptions['files'] = $files;
                    $workOptions['commands'] = $commands;
                    $result = Agents::work($words[0], $task, $workOptions);
                    if (isset($options['timeout'])) {
                        $result = Agents::wait($result['agent_id'], $result['run_id'], $options['timeout']);
                    }
                    break;
                case 'peek':
                    $result = Agents::peek($words[0]);
                    break;
            }
            self::output($result);
            $workWaited = $action === 'work' && isset($options['timeout']);
            if ($action === 'result' || $action === 'wait' || $workWaited) {
                $status = isset($result['status']) ? $result['status'] : null;
                if (in_array($status, ['failed', 'stopped', 'cancelled'], true)) {
                    Log::writeError('Agent assignment %s%s', $status, !empty($result['error']) ? ': ' . $result['error'] : '');
                    return 1;
                }
                if ($status !== 'completed') { Log::writeInfo('Agent assignment status: %s', $status); return 3; }
            }
            Log::writeSuccess('Agent %s completed', $action);
            return 0;
        } catch (\Throwable $error) {
            Log::writeError($error->getMessage());
            Log::writeVerbose($error);
            self::output(['error' => $error->getMessage()]);
            return $error instanceof \InvalidArgumentException ? 2 : 1;
        }
    }

    /** Parsing is strict at this boundary: no command silently ignores a conflicting flag. */
    public static function parse(array $args)
    {
        $action = $args ? array_shift($args) : 'help';
        if (!in_array($action, self::ACTIONS, true)) throw new \InvalidArgumentException('Unknown agent action. Use ok-phill agents help');
        if ($action === '--help' || $action === '-h') $action = 'help';
        $allowed = [
            'create' => ['persona', 'team', 'profile', 'tools', 'contract', 'workspace'],
            'configure' => ['persona', 'profile', 'tools', 'contract'],
            'assign' => ['key'], 'message' => [], 'list' => ['team'], 'show' => [],
            'result' => ['run'], 'wait' => ['run', 'timeout'], 'events' => ['after'],
            'pause' => [], 'resume' => [], 'remove' => [], 'stop' => ['team'],
            'approve' => ['file', 'command', 'read', 'patch'], 'revoke' => ['file', 'command', 'read', 'patch'],
            'work' => ['persona', 'profile', 'tools', 'contract', 'team', 'workspace', 'file', 'command', 'key', 'timeout'],
            'peek' => [], 'help' => []
        ];
        $names = ['--persona' => 'persona', '--team' => 'team', '-p' => 'profile', '--profile' => 'profile',
            '-t' => 'tools', '--tool' => 'tools', '-c' => 'contract', '--contract' => 'contract', '--workspace' => 'workspace',
            '--key' => 'key', '--run' => 'run', '--timeout' => 'timeout', '--after' => 'after',
            '--file' => 'file', '--command' => 'command', '--read' => 'read', '--patch' => 'patch'];
        $options = []; $words = []; $literal = false;
        for ($i = 0; $i < count($args); $i++) {
            $argument = $args[$i];
            if ($literal) { $words[] = $argument; continue; }
            if ($argument === '--') { $literal = true; continue; }
            if ($argument === '--debug' || $argument === '--verbose') {
                Log::configure(['console' => $argument === '--verbose' ? Log::VERBOSE : Log::DEBUG]);
                continue;
            }
            if ($argument === '' || substr($argument, 0, 1) !== '-') { $words[] = $argument; continue; }
            $parts = explode('=', $argument, 2); $option = $parts[0];
            if (!isset($names[$option]) || !in_array($names[$option], $allowed[$action], true)) throw new \InvalidArgumentException('Unsupported option ' . $option . ' for agents ' . $action);
            $name = $names[$option];
            if ($name === 'read' || $name === 'patch') {
                if (isset($parts[1])) throw new \InvalidArgumentException($option . ' does not take a value');
                $options[$name] = true; continue;
            }
            $value = isset($parts[1]) ? $parts[1] : (isset($args[$i + 1]) && substr($args[$i + 1], 0, 1) !== '-' ? $args[++$i] : null);
            if ($value === null || trim($value) === '') throw new \InvalidArgumentException('Missing value for ' . $option);
            if (in_array($name, ['run', 'timeout', 'after'], true)) {
                $minimum = $name === 'run' ? 1 : 0;
                $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]);
                $maximum = ($action === 'work' && $name === 'timeout') ? 30 : ($name === 'timeout' ? 60 : null);
                if ($value === false || ($maximum !== null && $value > $maximum)) throw new \InvalidArgumentException('Invalid ' . $name . '; expected an integer' . ($name === 'timeout' ? ' from 0 through ' . $maximum : ' of at least ' . $minimum));
            }
            if (array_key_exists($name, $options)) {
                if (!($action === 'work' && in_array($name, ['file', 'command'], true))) throw new \InvalidArgumentException('Repeated option ' . $option);
                $options[$name] = is_array($options[$name]) ? $options[$name] : [$options[$name]];
                $options[$name][] = $value;
            } else $options[$name] = $value;
        }
        if ($action === 'approve' || $action === 'revoke') {
            if (isset($options['file']) === isset($options['command'])) throw new \InvalidArgumentException('Workspace permission changes require exactly one of --file or --command');
            if ((isset($options['read']) && isset($options['patch'])) || (isset($options['command']) && (isset($options['read']) || isset($options['patch'])))) throw new \InvalidArgumentException('Use --read or --patch only with --file, not both');
        }
        if ($action === 'stop' && isset($options['team'])) {
            if ($words) throw new \InvalidArgumentException('Stop accepts either an agent or --team, not both');
        } elseif ($action === 'help' || $action === 'list') {
            if ($words) throw new \InvalidArgumentException('Unexpected argument for agents ' . $action);
        } elseif ($action === 'assign' || $action === 'message') {
            if (!$words || trim($words[0]) === '') throw new \InvalidArgumentException('Assignment requires an agent name or ID');
        } elseif ($action === 'work') {
            if (!$words || trim($words[0]) === '') throw new \InvalidArgumentException('Work requires an agent name or ID');
        } elseif ($action === 'peek') {
            if (count($words) !== 1 || trim($words[0]) === '') throw new \InvalidArgumentException('Peek requires exactly one agent name or ID');
        } elseif (count($words) !== 1 || trim($words[0]) === '') throw new \InvalidArgumentException('agents ' . $action . ' requires exactly one agent name or ID');
        return ['action' => $action, 'words' => $words, 'options' => $options];
    }

    private static function output($value)
    {
        // Keep Unicode escaped too: even C1 controls/bidi markers remain inert on a terminal.
        // Consumers still recover the exact answer by decoding JSON.
        $flags = JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= constant('JSON_INVALID_UTF8_SUBSTITUTE');
        $json = json_encode($value, $flags);
        if ($json === false) throw new \RuntimeException('Could not encode agent result as JSON: ' . json_last_error_msg());
        Terminal::write($json . "\n", 'stdout');
    }

    public static function help()
    {
        return "Usage: ok-phill agents ACTION [options]\n\n"
            . "create NAME [--persona ID/NAME/JSON] [--team TEAM] [-p PROFILE]\n"
            . "            [-t TOOL_CSV] [-c CONTRACT_JSON_OR_PATH] [--workspace PATH]\n"
            . "configure NAME [--persona ID/NAME/JSON] [-p PROFILE] [-t TOOL_CSV] [-c CONTRACT]\n"
            . "assign NAME [TASK] [--key IDEMPOTENCY_KEY]  Queue work; piped input is appended\n"
            . "message NAME [TEXT]                      Send a note between rounds; piped input is appended\n"
            . "list [--team TEAM]                       List your local workers\n"
            . "show NAME                               Inspect a worker\n"
            . "result NAME [--run ID]                   Read latest or specific assignment\n"
            . "wait NAME [--run ID] [--timeout SECONDS]  Wait up to 30 seconds by default\n"
            . "events NAME [--after ID]                 Read incremental progress\n"
            . "pause NAME | resume NAME                 Control the assignment queue\n"
            . "stop NAME | stop --team TEAM             Stop active and queued work\n\n"
            . "remove NAME                             Delete an idle worker, descendants and their history\n\n"
            . "work NAME [TASK] [--persona NAME] [-p PROFILE] [-t TOOLS] [-c CONTRACT] [--team TEAM]\n"
            . "            [--workspace PATH] [--file RELPATH] [--command JSON_ARGV] [--key KEY] [--timeout 0..30]\n"
            . "            Create/reuse, grant explicit access, queue work; piped input is appended\n"
            . "            Repeat --file/--command for grants; --timeout waits for that run\n"
            . "peek NAME                               Bounded evidence for the active or latest run\n\n"
            . "approve NAME --file RELPATH [--read|--patch]  Grant one file (patch includes read)\n"
            . "approve NAME --command JSON_ARGV         Grant a command's first three words\n\n"
            . "revoke NAME --file RELPATH | --command JSON_ARGV  Remove a workspace grant\n"
            . "Revocation prevents future operations; it does not kill an accepted command.\n\n"
            . "Names or numeric IDs identify agents. Management results are JSON on stdout; diagnostics use stderr.\n"
            . "Exit codes: 0 success/completed, 1 error/failed/stopped, 2 invalid usage, 3 pending/timeout.\n"
            . "No frontend sessions are accepted. Use -- before a literal task beginning with a dash.\n"
            . "Use ok-phill -- agents ... to send those words as an ordinary prompt.\n";
    }
}

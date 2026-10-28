<?php
namespace Agent;

/** Machine-readable adapter for the engineering workflow service. CLI only; no frontend bypass route. */
class WorkflowCli
{
    const ACTIONS = ['list', 'show', 'install', 'run', 'help', '--help', '-h'];

    public static function run(array $args)
    {
        Log::configure(['cli_stream' => 'stderr']);
        try {
            $command = self::parse($args);
            $action = $command['action'];
            $words = $command['words'];
            $options = $command['options'];
            if ($action === 'help') { Terminal::write(self::help(), 'stdout'); return 0; }
            Log::writeDebug('Workflow action', ['action' => $action]);
            switch ($action) {
                case 'list':
                    $result = self::listWorkflows(); break;
                case 'show':
                    $result = self::showWorkflow($words[0]); break;
                case 'install':
                    $result = Engineering::install(isset($options['replace'])); break;
                case 'run':
                    $workflow = $words[0];
                    $task = count($words) > 1 ? implode(' ', array_slice($words, 1)) : null;
                    if ($task === null || trim($task) === '') {
                        // Allow a workflow ID with the task supplied on bounded stdin when no argv task is given.
                        $task = Cli::input('');
                        if (trim($task) === '') throw new \InvalidArgumentException('workflows run requires a task (argument or piped stdin)');
                    }
                    $result = Engineering::start($workflow, $task, $options);
                    break;
            }
            self::output($result);
            Log::writeSuccess('Workflow %s completed', $action);
            return 0;
        } catch (\Throwable $error) {
            Log::writeError($error->getMessage());
            Log::writeVerbose($error);
            self::output(['error' => $error->getMessage()]);
            return $error instanceof \InvalidArgumentException ? 2 : 1;
        }
    }

    /** Strict parsing: unsupported flags are rejected, never silently ignored. */
    private static function parse(array $args)
    {
        $action = $args ? array_shift($args) : 'help';
        if (!in_array($action, self::ACTIONS, true)) throw new \InvalidArgumentException('Unknown workflow action. Use ok-phill workflows help');
        if ($action === '--help' || $action === '-h') $action = 'help';
        $allowed = [
            'list' => [], 'show' => [], 'install' => ['replace'],
            'run' => ['profile', 'name', 'workspace'], 'help' => []
        ];
        $names = ['--replace' => 'replace', '-p' => 'profile', '--profile' => 'profile',
            '--name' => 'name', '--workspace' => 'workspace'];
        $options = []; $words = []; $literal = false;
        for ($i = 0; $i < count($args); $i++) {
            $argument = $args[$i];
            if ($literal) { $words[] = $argument; continue; }
            if ($argument === '--') { $literal = true; continue; }
            if ($argument === '' || substr($argument, 0, 1) !== '-') { $words[] = $argument; continue; }
            $parts = explode('=', $argument, 2); $option = $parts[0];
            if (!isset($names[$option]) || !in_array($names[$option], $allowed[$action], true)) throw new \InvalidArgumentException('Unsupported option ' . $option . ' for workflows ' . $action);
            $name = $names[$option];
            if (array_key_exists($name, $options)) throw new \InvalidArgumentException('Repeated option ' . $option);
            if ($name === 'replace') {
                if (isset($parts[1])) throw new \InvalidArgumentException($option . ' does not take a value');
                $options[$name] = true; continue;
            }
            $value = isset($parts[1]) ? $parts[1] : (isset($args[$i + 1]) && substr($args[$i + 1], 0, 1) !== '-' ? $args[++$i] : null);
            if ($value === null || trim($value) === '') throw new \InvalidArgumentException('Missing value for ' . $option);
            $options[$name] = $value;
        }
        if ($action === 'list' || $action === 'help') {
            if ($words) throw new \InvalidArgumentException('Unexpected argument for workflows ' . $action);
        } elseif ($action === 'show') {
            if (count($words) !== 1 || trim($words[0]) === '') throw new \InvalidArgumentException('workflows show requires exactly one workflow ID');
        } elseif ($action === 'install') {
            if ($words) throw new \InvalidArgumentException('Unexpected argument for workflows install');
        } elseif ($action === 'run') {
            if (count($words) < 1 || trim($words[0]) === '') throw new \InvalidArgumentException('workflows run requires a workflow ID');
        }
        return ['action' => $action, 'words' => $words, 'options' => $options];
    }

    private static function listWorkflows()
    {
        $workflows = Engineering::workflows();
        $catalog = Engineering::personas();
        $out = [];
        foreach ($workflows as $id => $wf) {
            $roles = [];
            foreach ($wf['roles'] as $roleId) {
                $roles[] = isset($catalog[$roleId]) ? $catalog[$roleId]['name'] : $roleId;
            }
            $out[] = ['id' => $id, 'name' => $wf['name'], 'roles' => $roles, 'phases' => $wf['phases']];
        }
        return ['workflows' => $out];
    }

    private static function showWorkflow($id)
    {
        $workflows = Engineering::workflows();
        if (!isset($workflows[$id])) throw new \InvalidArgumentException('Unknown workflow: ' . $id);
        $wf = $workflows[$id];
        $catalog = Engineering::personas();
        $roles = [];
        foreach ($wf['roles'] as $roleId) {
            if (isset($catalog[$roleId])) $roles[] = ['id' => $roleId, 'name' => $catalog[$roleId]['name'], 'tools' => $catalog[$roleId]['tools']];
            else $roles[] = ['id' => $roleId, 'name' => null, 'tools' => []];
        }
        return ['id' => $id, 'name' => $wf['name'], 'roles' => $roles, 'phases' => $wf['phases'], 'coordinator' => $wf['coordinator']];
    }

    private static function output($value)
    {
        $flags = JSON_UNESCAPED_SLASHES;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= constant('JSON_INVALID_UTF8_SUBSTITUTE');
        $json = json_encode($value, $flags);
        if ($json === false) throw new \RuntimeException('Could not encode workflow result as JSON: ' . json_last_error_msg());
        Terminal::write($json . "\n", 'stdout');
    }

    public static function help()
    {
        return "Usage: ok-phill workflows ACTION [options]\n\n"
            . "list                           List available engineering workflows\n"
            . "show ID                        Show one workflow and its roles\n"
            . "install [--replace]            Install the shipped persona catalog as organization defaults\n"
            . "run ID TASK [-p PROFILE] [--name NAME] [--workspace PATH]  Create coordinator and assign a workflow\n"
            . "                               TASK may be omitted and supplied via piped stdin instead\n\n"
            . "Workflow IDs: web-research, code-research, database-research, implement, review, triage\n"
            . "Results are JSON on stdout; diagnostics use stderr. Exit codes: 0 success, 1 error, 2 invalid usage.\n"
            . "Engineering workflows are CLI only; there is no frontend bypass route. Install is fixed to the shipped catalog.\n";
    }
}

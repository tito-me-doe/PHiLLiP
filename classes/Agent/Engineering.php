<?php
namespace Agent;

/** Reusable engineering personas and workflows backed by the durable agent service. */
class Engineering
{
    const CATALOG_PATH = '/resources/engineering/personas.json';
    const WORKFLOWS_PATH = '/resources/engineering/workflows.json';
    const DEFAULT_PROFILE = 'nemotron-vl-work';
    const COORDINATOR_MAX_ROUNDS = 32;
    const TEAM_PREFIX = 'engineering';
    const COORDINATOR_NAME = 'Engineering Coordinator';

    /** Validated persona catalog keyed by stable role ID. */
    public static function personas()
    {
        $path = Runtime::root() . self::CATALOG_PATH;
        if (!is_file($path) || !is_readable($path)) throw new \RuntimeException('Engineering persona catalog is not readable: ' . $path);
        $data = json_decode(file_get_contents($path), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) throw new \RuntimeException('Engineering persona catalog is not a JSON object');
        $catalog = [];
        foreach ($data as $id => $entry) {
            if (!is_string($id) || $id === '') throw new \InvalidArgumentException('Catalog role IDs must be non-empty strings');
            $catalog[$id] = Personas::normalize($entry);
        }
        if ($catalog === []) throw new \RuntimeException('Engineering persona catalog is empty');
        return $catalog;
    }

    /** Workflow definitions keyed by stable workflow ID. */
    public static function workflows()
    {
        $path = Runtime::root() . self::WORKFLOWS_PATH;
        if (!is_file($path) || !is_readable($path)) throw new \RuntimeException('Engineering workflow catalog is not readable: ' . $path);
        $data = json_decode(file_get_contents($path), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) throw new \RuntimeException('Engineering workflow catalog is not a JSON object');
        return $data;
    }

    /** True when every catalog role is installed as an organization default. */
    public static function installed()
    {
        $catalog = self::personas();
        $names = array_values(array_unique(array_column($catalog, 'name')));
        $db = Database::connection();
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $q = $db->prepare("SELECT COUNT(DISTINCT name) AS count FROM personas WHERE visibility = 'organization' AND owner_id IS NULL AND is_default = TRUE AND name IN ($placeholders)");
        $q->execute($names);
        return (int)$q->fetchColumn() === count($names);
    }

    /** Install the shipped catalog as organization-visible owned NULL defaults. CLI only; root or catalog owner. */
    public static function install($replace = false)
    {
        $identity = Cli::identity();
        if (!Runtime::isCli() || $identity['frontend'] || ToolContext::get('run_id')) throw new \RuntimeException('Engineering catalog install requires a local operator.');
        $catalogPath = Runtime::root() . self::CATALOG_PATH;
        $ownerUid = @fileowner($catalogPath);
        if ($ownerUid === false || $identity['unix']['uid'] === null || (!$identity['unix']['root'] && $identity['unix']['uid'] !== $ownerUid)) {
            throw new \RuntimeException('Installing engineering personas requires root or the repository/catalog owner Unix UID; run as an unprivileged mapped user.');
        }
        $catalog = self::personas();
        return State::transaction(function () use ($catalog, $replace) {
        $installed = [];
        foreach ($catalog as $id => $persona) {
            $name = $persona['name'];
            $tools = json_encode($persona['tools']);
            $contract = $persona['contract'] === null ? null : json_encode((object)$persona['contract']);
            if ($replace) {
                $sql = "INSERT INTO personas (owner_id, name, prompt, visibility, team_id, tools, contract, is_default)
                        VALUES (NULL, ?, ?, 'organization', NULL, ?::jsonb, ?::jsonb, TRUE)
                        ON CONFLICT (name) WHERE is_default = TRUE
                        DO UPDATE SET prompt = EXCLUDED.prompt, tools = EXCLUDED.tools, contract = EXCLUDED.contract";
            } else {
                $sql = "INSERT INTO personas (owner_id, name, prompt, visibility, team_id, tools, contract, is_default)
                        VALUES (NULL, ?, ?, 'organization', NULL, ?::jsonb, ?::jsonb, TRUE)
                        ON CONFLICT (name) WHERE is_default = TRUE DO NOTHING";
            }
            Database::execute($sql, [$name, $persona['prompt'], $tools, $contract]);
            $row = Database::row("SELECT id FROM personas WHERE owner_id IS NULL AND visibility = 'organization' AND is_default = TRUE AND name = ?", [$name]);
            if (!$row) throw new \RuntimeException('A default persona has a conflicting scope; review it before replacing the catalog.');
            $installed[$id] = (int)$row['id'];
        }
        return ['installed' => $installed, 'replaced' => (bool)$replace, 'count' => count($installed)];
        });
    }

    /** Create the coordinator and assign the workflow task; returns create+assign handles, never a completion claim. */
    public static function start($workflow, $task, $options = [])
    {
        $identity = Cli::identity();
        if ($identity['frontend']) throw new \RuntimeException('Engineering workflows are CLI-only; no frontend bypass route.');
        if (!is_string($task) || trim($task) === '') throw new \InvalidArgumentException('A workflow task is required');
        if (!is_array($options)) $options = [];
        $options = Config::object($options, 'Workflow options');
        $catalog = self::personas();
        $workflows = self::workflows();
        if (!isset($workflows[$workflow])) throw new \InvalidArgumentException('Unknown workflow: ' . $workflow);
        if (!self::installed()) throw new \RuntimeException('Engineering catalog is not installed; run ok-phill workflows install');
        $wf = $workflows[$workflow];

        // Select the actual installed organization-default coordinator by ID so admin-edited
        // prompt/tools are honored, never a private catalog copy resolved by ambiguous name.
        $coordinatorRow = Database::row(
            "SELECT id FROM personas WHERE owner_id IS NULL AND visibility = 'organization' AND is_default = TRUE AND name = ?",
            [self::COORDINATOR_NAME]
        );
        if (!$coordinatorRow) {
            throw new \RuntimeException('Engineering Coordinator is not installed as an organization default; run ok-phill workflows install');
        }
        $coordinator = Personas::resolve((int)$coordinatorRow['id'], $identity['owner']);

        // Resolve each required role to the actual saved organization-default persona by
        // catalog name, honoring admin-edited prompt/tools from the database row.
        $roles = [];
        $tools = ['Agent\\Tools\\Delegate', 'Agent\\Tools\\Source'];
        foreach ($wf['roles'] as $roleId) {
            if (!isset($catalog[$roleId])) throw new \InvalidArgumentException('Unknown role: ' . $roleId);
            $name = $catalog[$roleId]['name'];
            $row = Database::row(
                "SELECT id FROM personas WHERE owner_id IS NULL AND visibility = 'organization' AND is_default = TRUE AND name = ?",
                [$name]
            );
            if (!$row) {
                throw new \RuntimeException('Role is not installed as an organization default: ' . $name);
            }
            $persona = Personas::resolve((int)$row['id'], $identity['owner']);
            $roles[] = [
                'slug' => $roleId,
                'persona_id' => (int)$persona['id'],
                'name' => $persona['name'],
                'tools' => $persona['tools'],
            ];
            $tools = array_merge($tools, $persona['tools']);
        }
        $tools = array_values(array_unique($tools));

        // max_rounds travels inside the profile array so Config::resolve keeps it; it is not
        // a separate Agents::create parameter that Cli::configuration would drop. The profile
        // option accepts an array, JSON, or profile name through Config::resolve.
        $profileValue = isset($options['profile']) ? $options['profile'] : self::DEFAULT_PROFILE;
        $profile = Config::resolve($profileValue);
        $profile['max_rounds'] = self::COORDINATOR_MAX_ROUNDS;

        // Unique team and coordinator name every run so concurrent runs cannot collide or
        // burn through the 16-agent team cap by reusing a single team/name.
        $suffix = uniqid(self::TEAM_PREFIX . '_', true);
        $team = self::TEAM_PREFIX . '-' . $suffix;
        $coordinatorName = (isset($options['name']) && is_string($options['name']) && $options['name'] !== '' ? $options['name'] : self::COORDINATOR_NAME . ' - ' . $wf['name']) . ' ' . $suffix;

        $createOptions = ['team' => $team, 'profile' => $profile, 'tools' => $tools, 'persona' => $coordinator];
        if (isset($options['workspace']) && is_string($options['workspace']) && $options['workspace'] !== '') {
            $createOptions['workspace'] = $options['workspace'];
        }

        $agent = Agents::create($coordinatorName, $createOptions);
        $brief = self::brief($wf, $roles, $task, $options);
        $handle = Agents::assign($agent['id'], $brief, isset($options['key']) ? $options['key'] : null);
        return [
            'workflow' => $workflow,
            'workflow_name' => $wf['name'],
            'coordinator' => $agent['id'],
            'coordinator_name' => $agent['name'],
            'team' => $agent['team'],
            'phases' => $wf['phases'],
            'roles' => array_values(array_map(function ($role) {
                return ['slug' => $role['slug'], 'persona_id' => $role['persona_id'], 'name' => $role['name'], 'tools' => $role['tools']];
            }, $roles)),
            'agent' => $agent,
            'run' => $handle,
            'status' => $handle['status'],
            'message' => 'Coordinator created and assigned. It delegates to isolated child roles; handles are not a completion claim.'
        ];
    }

    private static function brief(array $wf, array $roles, $task, array $options)
    {
        $lines = [];
        $lines[] = 'Engineering workflow: ' . $wf['name'];
        $lines[] = 'Coordinator instructions: ' . $wf['coordinator'];
        $lines[] = 'Create each child with Delegate.create using the exact numeric persona_id for its slug, never the catalog slug alone.';
        $lines[] = 'Phases in order: ' . implode(' -> ', $wf['phases']);
        $lines[] = 'Required roles (slug, numeric persona ID, exact name, tools):';
        foreach ($roles as $role) {
            $lines[] = '  - ' . $role['slug'] . ' (persona_id ' . $role['persona_id'] . '): ' . $role['name'] . ' [' . implode(', ', $role['tools']) . ']';
        }
        $lines[] = 'Task: ' . $task;
        if (isset($options['source']) && is_string($options['source'])) $lines[] = 'Source paths/URLs: ' . $options['source'];
        $lines[] = '';
        $lines[] = 'Work through the phases in order. Give each isolated child the full brief with exact source paths/URLs. Reuse existing child agents on follow-ups and bound every wait.';
        $lines[] = 'When a child needs a file or command approval, stop and return the child IDs, the exact operator CLI approval commands, and resumable follow-up instructions:';
        $lines[] = '  ok-phill agents approve <child-id> --file <relative-path> --patch   (grant a file write; exactly one --file flag)';
        $lines[] = '  ok-phill agents approve <child-id> --command \'["php","tests/example.php"]\'   (grant a command; exactly one --command flag, single shell-quoted JSON argv; replace argv with the actual needed command)';
        $lines[] = 'Never self-approve or busy-loop; approvals are not inherited. Never claim a workflow complete merely because a handle was created. Report only verified evidence.';
        return implode("\n", $lines);
    }
}

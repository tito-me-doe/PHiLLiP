<?php
namespace Agent;

/** Profile discovery and explicit operator saves through the shared configuration contracts. */
class ProfileCli
{
    public static function run(array $args)
    {
        Log::configure(['cli_stream' => 'stderr']);
        try {
            $command = $args ? array_shift($args) : 'help';
            if (!in_array($command, ['list', 'show', 'save', 'defaults', 'default', 'help', '--help', '-h'], true)) throw new \InvalidArgumentException('Unknown profiles command; use ok-phill profiles help');
            Log::writeDebug('Profile management action', ['command' => $command]);
            if (in_array($command, ['help', '--help', '-h'], true)) {
                if ($args) throw new \InvalidArgumentException('Profile help does not accept arguments');
                Terminal::write(self::help(), 'stdout'); return 0;
            }
            if ($command === 'save') {
                if (count($args) !== 2 && !(count($args) === 3 && $args[2] === '--enable')) throw new \InvalidArgumentException('Use profiles save NAME JSON_OR_- [--enable]');
                $result = self::save($args[0], $args[1], count($args) === 3);
                self::output($result); Log::writeSuccess('Profile save completed'); return 0;
            }
            if($command==='defaults') {
                if($args)throw new \InvalidArgumentException('Use profiles defaults without arguments');
                self::output(Database::rows('SELECT d.default_for,d.profileid,p.name FROM profile_defaults d LEFT JOIN profiles p ON p.id=d.profileid ORDER BY d.default_for'));Log::writeSuccess('Profile defaults listing completed');return 0;
            }
            if($command==='default') {
                if(count($args)!==2)throw new \InvalidArgumentException('Use profiles default TASK PROFILE_OR_none');
                self::authorizeWrite();Profiles::setDefault($args[0],$args[1]==='none'?null:$args[1]);
                self::output(['default_for'=>$args[0],'profile'=>$args[1]==='none'?null:$args[1]]);return 0;
            }
            if (($command === 'list' && $args) || ($command === 'show' && count($args) !== 1)) throw new \InvalidArgumentException('Use profiles list or profiles show ID_OR_NAME');
            if ($command === 'show' && (!is_string($args[0]) || trim($args[0]) === '' || $args[0][0] === '-' || preg_match('/[\x00-\x1f\x7f]/', $args[0]))) throw new \InvalidArgumentException('Profile show requires an ID or name');
            $result = $command === 'list' ? self::all() : self::show($args[0]);
            self::output($result); Log::writeSuccess('Profile %s completed', $command); return 0;
        } catch (\Throwable $error) {
            // Configuration and database exception details may contain authentication material.
            $safeRuntime = in_array($error->getMessage(), ['Saving profiles requires a local operator', 'Saving profiles requires root or the profile source owner Unix UID'], true);
            $message = $error instanceof \InvalidArgumentException || $safeRuntime ? $error->getMessage() : 'Could not read or save the requested profile configuration';
            Log::writeError($message);
            self::output(['error' => $message]);
            return $error instanceof \InvalidArgumentException ? 2 : 1;
        }
    }

    public static function all()
    {
        $rows = []; $names = [];
        try { $rows = (new Registry())->profiles(); }
        catch (\PDOException $error) { /* Config::profile also supports database-free JSON fallback. */ }
        $result = [];
        foreach ($rows as $row) {
            $result[] = self::describe($row, $row['name'], 'database');
            $names[$row['name']] = true;
        }
        $file = Config::file('PROFILE_JSON', 'profiles.local.json');
        if (is_readable($file)) {
            $data = json_decode(file_get_contents($file), true);
            if (!is_array($data)) throw new \RuntimeException('Invalid profile fallback configuration');
            foreach (['profiles', 'presets'] as $section) {
                if (!isset($data[$section])) continue;
                if (!is_array($data[$section])) throw new \RuntimeException('Invalid profile fallback configuration');
                foreach ($data[$section] as $name => $unused) {
                    if (isset($names[$name])) continue;
                    $result[] = self::describe(['name' => (string)$name], (string)$name, 'json');
                    $names[$name] = true;
                }
            }
        }
        usort($result, function ($a, $b) { return strcmp($a['name'], $b['name']); });
        return $result;
    }

    public static function show($reference)
    {
        $row = false;
        try { $row = (new Registry())->profile($reference); }
        catch (\PDOException $error) { /* Same database-free fallback as Config::profile. */ }
        if ($row) return self::describe($row, $row['name'], 'database');
        if (is_string($reference) && in_array(substr(ltrim($reference), 0, 1), ['{', '[', '"'], true)) throw new \InvalidArgumentException('Profile show requires a saved profile ID or name');
        // Resolve first so numeric strings retain Registry's name-before-ID behavior.
        $config = Config::profile($reference);
        return self::describe($config, trim((string)$reference), 'json', true);
    }

    /** Save global model configuration only for the actual local source owner or root. */
    public static function save($name, $input, $enable = false)
    {
        self::authorizeWrite();
        if (!is_string($name) || trim($name) === '' || strlen(trim($name)) > 160 || preg_match('/[\x00-\x1f\x7f]/', $name) || !preg_match('//u', $name) || substr(trim($name), 0, 1) === '-') throw new \InvalidArgumentException('Profile name must be 1..160 UTF-8 bytes without controls or a leading dash');
        if (!is_string($input)) throw new \InvalidArgumentException('Profile save requires a JSON object or - for stdin');
        if ($input === '-') {
            $input = stream_get_contents(Terminal::stream('stdin'), 1048577);
            if ($input === false) throw new \RuntimeException('Could not read profile JSON from stdin');
        }
        if (strlen($input) > 1048576) throw new \InvalidArgumentException('Profile JSON exceeds 1 MiB');
        if (substr(ltrim($input), 0, 1) !== '{') throw new \InvalidArgumentException('Profile save requires a JSON object or - for stdin');
        try { $config = Config::object($input, 'Profile configuration'); }
        catch (\InvalidArgumentException $error) { throw new \InvalidArgumentException('Profile configuration must be a valid JSON object'); }
        try { $row = Profiles::save(trim($name), $config, Config::boolean($enable, 'Profile enabled')); }
        catch (\InvalidArgumentException $error) { throw new \InvalidArgumentException('Invalid profile configuration; provide request settings or a valid preset'); }
        return self::describe($row, $row['name'], 'database');
    }

    private static function authorizeWrite()
    {
        $unix = Runtime::unixIdentity();
        if (!Runtime::isCli() || ToolContext::get('frontend') || ToolContext::get('run_id') !== null || ToolContext::get('agent_id') !== null) throw new \RuntimeException('Saving profiles requires a local operator');
        $owner = @fileowner(Runtime::root() . '/classes/Agent/Profiles.php');
        if ($owner === false || $unix['uid'] === null || (!$unix['root'] && $unix['uid'] !== $owner)) throw new \RuntimeException('Saving profiles requires root or the profile source owner Unix UID');
    }

    private static function describe(array $reference, $name, $source, $inline = false)
    {
        $config = $inline ? $reference : Config::profile($reference);
        // Empty inspection headers override inherited credentials without loading their secret values.
        unset($config['head'], $config['headers'], $config['credentials_ref']);
        $config['headers'] = [];
        $config = Config::resolve($config);
        $capabilities = Registry::capabilities(array_replace(isset($config['capabilities']) && is_array($config['capabilities']) ? $config['capabilities'] : [], $config, $inline ? [] : $reference));
        foreach ($capabilities as &$value) {
            if ($value !== null) $value = in_array($value, [true, 1, '1', 't', 'true'], true) ? true : (in_array($value, [false, 0, '0', 'f', 'false'], true) ? false : null);
        }
        unset($value);
        $result = ['id' => !$inline && isset($reference['id']) ? (int)$reference['id'] : null, 'name' => $name, 'default' => $name === 'default',
            'enabled' => !$inline && array_key_exists('enabled', $reference) ? in_array($reference['enabled'], [true, 1, '1', 't'], true) : null,
            'source' => $source, 'model' => isset($config['model']) && is_string($config['model']) ? $config['model'] : (isset($config['body']['model']) && is_string($config['body']['model']) ? $config['body']['model'] : null),
            'endpoint' => isset($config['url']) ? self::endpoint($config['url']) : null, 'capabilities' => (object)$capabilities];
        if (isset($config['context_window']) && is_numeric($config['context_window'])) $result['context_window'] = (int)$config['context_window'];
        $parameters = array_intersect_key(array_replace(isset($config['body']) && is_array($config['body']) ? $config['body'] : [], $config), array_flip(['max_rounds', 'max_retries', 'max_contract_corrections', 'max_tokens', 'temperature', 'top_p', 'min_p', 'reasoning_effort', 'stream', 'timeout']));
        foreach ($parameters as $key => $value) if (!is_scalar($value)) unset($parameters[$key]);
        $result['parameters'] = (object)$parameters;
        return $result;
    }

    private static function endpoint($url)
    {
        if (!is_string($url)) return null;
        $parts = parse_url($url);
        if (!$parts || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return null;
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . (isset($parts['path']) ? $parts['path'] : '');
    }

    private static function output($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new \RuntimeException('Could not encode profile result');
        Terminal::write($json . "\n", 'stdout');
    }

    public static function help()
    {
        return "Usage: ok-phill profiles COMMAND\n\nlist                            List database and JSON fallback profiles\nshow ID_OR_NAME                 Inspect one profile without contacting its model\nsave NAME JSON_OR_- [--enable]   Save a JSON object; - reads up to 1 MiB from stdin\ndefaults                        List fixed task/profile assignments\ndefault TASK PROFILE_OR_none    Assign a saved profile or restore current-model fallback\n\nResults are concise JSON on stdout; diagnostics use stderr. Credentials are omitted.\nThe profile named default is the ordinary CLI default. Database rows override JSON names.\nDatabase enabled controls frontend selection; local code may select disabled profiles.\nSaves disable the changed profile unless --enable explicitly requests capability probes.\nSaving global configuration requires root or the profile source owner Unix UID.\nJSON-only profiles have enabled: null. Exit codes: 0 success, 1 runtime, 2 invalid input.\n";
    }
}

<?php
namespace Agent;

class Config
{
    /** Explicit booleans from application code, JSON, or HTML form values. Never use PHP truthiness. */
    public static function boolean($value, $label = 'Boolean setting')
    {
        $value = Utilities::cleanup($value, 'boolean', $issues);
        // Cleanup is best effort; this boundary specifically requires a boolean before saving policy.
        if ($issues['errors'] || !is_bool($value)) throw new \InvalidArgumentException($label . ' must be an explicit boolean (true/false, yes/no, on/off, or 1/0)');
        return $value;
    }

    /** Shared settings boundary: JSON objects, decoded objects, and named PHP arrays. */
    public static function object($input, $label = 'Configuration')
    {
        $input = Utilities::cleanup($input, ['type' => 'object', 'decode_json' => true, 'decode_empty' => true], $issues);
        if ($issues['errors'] || !is_array($input)) {
            $detail = $issues['errors'] ? '; ' . $issues['errors'][0]['message'] : '';
            throw new \InvalidArgumentException($label . ' must be a JSON object or an array with named keys, not a list' . $detail);
        }
        return $input;
    }

    public static function file($environment, $basename)
    {
        $default = dirname(__DIR__, 2) . '/config/' . $basename;
        $path = getenv($environment);
        if (!$path) return $default;
        if ($path[0] !== '/' && !preg_match('/^[a-zA-Z]:[\\\\\/]/', $path)) $path = dirname(__DIR__, 2) . '/' . $path;
        if (!is_readable($path) && is_readable($default)) {
            Log::writeWarning('%s is unavailable in this environment; using the project-local configuration', $environment);
            return $default;
        }
        return $path;
    }
    public static function service($name)
    {
        $config = null;
        try {
            $q = Database::connection()->prepare('SELECT value FROM settings WHERE key = ?');
            $q->execute(['services.' . $name]);
            $value = $q->fetchColumn();
            if ($value !== false) $config = json_decode($value, true);
        } catch (\Exception $e) { }
        if (!is_array($config)) {
            $file = self::file('SERVICE_JSON', 'services.local.json');
            $json = is_readable($file) ? json_decode(file_get_contents($file), true) : [];
            if (!isset($json[$name])) throw new \RuntimeException("Service '$name' is not configured. Run the onboarding script or create the required JSON at $file.");
            $config = $json[$name];
        }
        // Only explicitly transport-aware defaults opt in; custom database settings remain authoritative.
        $environment = isset($config['url_env']) ? $config['url_env'] : null;
        if ($environment !== null && (!is_string($environment) || !preg_match('/^[A-Z_][A-Z0-9_]*$/D', $environment))) throw new \InvalidArgumentException('Service url_env must name an environment variable');
        $url = $environment !== null ? getenv($environment) : false;
        if ($url !== false && $url !== '') {
            if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) throw new \InvalidArgumentException($environment . ' requires an HTTP(S) URL');
            $config['url'] = $url;
        }
        return $config;
    }

    /** Save service settings once normalized, irrespective of caller transport. */
    public static function configureServices($services)
    {
        $services = self::object($services, 'Service configuration');
        foreach ($services as $name => &$value) {
            if (!in_array($name, ['searxng', 'camoufox'], true)) throw new \InvalidArgumentException('Unknown service');
            $value = self::object($value, 'Service ' . $name);
            if (!isset($value['url']) || !is_string($value['url']) || !filter_var($value['url'], FILTER_VALIDATE_URL)
                || !in_array(strtolower(parse_url($value['url'], PHP_URL_SCHEME)), ['http', 'https'], true)) throw new \InvalidArgumentException('Service ' . $name . ' requires an HTTP(S) URL');
            if (isset($value['url_env']) && (!is_string($value['url_env']) || !preg_match('/^[A-Z_][A-Z0-9_]*$/D', $value['url_env']))) throw new \InvalidArgumentException('Service url_env must name an environment variable');
            foreach (['headers', 'head'] as $key) if (isset($value[$key])) {
                if (!is_array($value[$key])) throw new \InvalidArgumentException('Service headers must be a named or list array');
                foreach ($value[$key] as $header => $content) {
                    if (!is_string($content) || preg_match('/[\r\n]/', $content) || (is_string($header) && preg_match('/[\r\n:]/', $header))) throw new \InvalidArgumentException('Service headers must contain valid single-line strings');
                }
            }
        }
        unset($value);
        $db = Database::connection();
        foreach ($services as $name => $value) $db->prepare('INSERT INTO settings (key, value) VALUES (?, ?::jsonb) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value')->execute(['services.' . $name, json_encode($value)]);
        return $services;
    }

    /** Accept a profile reference, registry row, or inline request configuration. */
    public static function profile($name = 'default')
    {
        $reference = null;
        if ($name instanceof \stdClass) $name = self::object($name, 'Profile configuration');
        if (is_string($name) && in_array(substr(ltrim($name), 0, 1), ['{', '"'], true)) {
            $value = json_decode($name, true);
            if (json_last_error() !== JSON_ERROR_NONE || (!is_array($value) && !is_string($value))) throw new \InvalidArgumentException('Profile JSON must be an object or profile name');
            $name = $value;
        }
        if (is_array($name)) {
            if (array_key_exists('config', $name)) {
                foreach (['url', 'model', 'body', 'preset'] as $key) if (array_key_exists($key, $name)) throw new \InvalidArgumentException('Ambiguous profile: use a registry row or inline configuration, not both');
                return self::rowConfig($name);
            }
            // A projection containing just its identity is a reference, not request parameters.
            if (isset($name['id']) && count($name) === 1) {
                if (is_string($name['id'])) $name['id'] = trim($name['id']);
                if (!is_int($name['id']) && !(is_string($name['id']) && ctype_digit($name['id']))) throw new \InvalidArgumentException('Profile ID must be an integer');
                $name = (int)$name['id'];
            }
            elseif (isset($name['name']) && count($name) === 1) { $reference = $name; $name = $name['name']; }
            else return self::object($name, 'Profile configuration');
        }
        if (!is_string($name) && !is_int($name)) throw new \InvalidArgumentException('Use a profile name, ID, configuration array, or JSON object');
        $row = false;
        try { $row = (new Registry())->profile($reference === null ? $name : $reference); }
        catch (\PDOException $e) { /* JSON supports database-free application use. */ }
        if ($row) return self::rowConfig($row);
        $file = self::file('PROFILE_JSON', 'profiles.local.json');
        if (is_readable($file)) {
            $data = json_decode(file_get_contents($file), true);
            if (!is_array($data)) throw new \RuntimeException('Invalid profile JSON: ' . $file);
            if (isset($data['profiles'][$name])) return self::profile(self::object($data['profiles'][$name], 'Profile configuration'));
            if (isset($data['presets'][$name])) return self::profile(self::object($data['presets'][$name], 'Profile preset'));
            $trimmed = is_string($name) ? trim($name) : $name;
            if (isset($data['profiles'][$trimmed])) return self::profile(self::object($data['profiles'][$trimmed], 'Profile configuration'));
            if (isset($data['presets'][$trimmed])) return self::profile(self::object($data['presets'][$trimmed], 'Profile preset'));
        }
        throw new \RuntimeException("Profile '$name' is not configured. Run the onboarding script or create the required profile JSON at $file.");
    }

    private static function rowConfig(array $row)
    {
        $config = self::object($row['config'], 'Profile row config');
        if (!isset($config['context_window']) && !empty($row['model_id'])) {
            try {
                $q = Database::connection()->prepare('SELECT metadata FROM models WHERE id = ?');
                $q->execute([$row['model_id']]);
                $metadata = json_decode($q->fetchColumn(), true);
                if (isset($metadata['max_model_len'])) $config['context_window'] = (int)$metadata['max_model_len'];
                elseif (isset($metadata['running']['context_length'])) $config['context_window'] = (int)$metadata['running']['context_length'];
            } catch (\PDOException $e) { /* A supplied row also works without its original database. */ }
        }
        return $config;
    }

    public static function resolve($profile = 'default')
    {
        $config = self::profile($profile); $visited = [];
        while (isset($config['preset'])) {
            $name = $config['preset'];
            if (!is_string($name) || isset($visited[$name])) throw new \InvalidArgumentException('Circular or invalid profile inheritance');
            $visited[$name] = true; unset($config['preset']); $parent = self::profile($name);
            // An explicit child header set replaces the parent's set, including private references.
            if (isset($config['headers']) || isset($config['head']) || isset($config['credentials_ref'])) unset($parent['headers'], $parent['head'], $parent['credentials_ref']);
            if (isset($parent['body'], $config['body'])) $config['body'] = array_replace($parent['body'], $config['body']);
            $config = array_replace($parent, $config);
        }
        if (isset($config['head'])) { $config['headers'] = $config['head']; unset($config['head']); }
        if (isset($config['headers'])) unset($config['credentials_ref']);
        elseif (isset($config['credentials_ref'])) {
            $q = Database::connection()->prepare('SELECT value FROM settings WHERE key = ?'); $q->execute([$config['credentials_ref']]); $headers = $q->fetchColumn();
            if ($headers === false) throw new \RuntimeException('Profile credentials are no longer configured');
            unset($config['credentials_ref']); $config['headers'] = json_decode($headers, true);
        }
        return $config;
    }

    /** Store an inline configuration without exposing its authentication headers in thread payloads. */
    public static function protect($config)
    {
        $config = self::profile($config);
        $headers = null;
        foreach (['headers', 'head'] as $key) if (isset($config[$key])) { $headers = $config[$key]; unset($config[$key]); }
        if ($headers !== null) {
            $reference = 'credentials.' . hash('sha256', json_encode($headers));
            Database::connection()->prepare('INSERT INTO settings (key, value) VALUES (?, ?::jsonb) ON CONFLICT DO NOTHING')->execute([$reference, json_encode($headers)]);
            $config['credentials_ref'] = $reference;
        }
        return $config;
    }
}

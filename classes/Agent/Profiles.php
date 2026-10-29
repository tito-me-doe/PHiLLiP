<?php
namespace Agent;

class Profiles
{
    const DEFAULT_TASKS = ['embed', 'rerank', 'classify', 'extract', 'generate_titles', 'summarize', 'score'];

    /** Explicit task selection only. Pass the current configuration as fallback; otherwise use 'default'. */
    public static function defaultFor($task, $fallback = 'default')
    {
        $row = self::assignment($task);
        return Config::resolve($row ?: $fallback);
    }

    public static function assignment($task)
    {
        if (!in_array($task, self::DEFAULT_TASKS, true)) throw new \InvalidArgumentException('Unknown profile default concept');
        try {
            $row = Database::row('SELECT p.* FROM profile_defaults d JOIN profiles p ON p.id=d.profileid WHERE d.default_for=?', [$task]);
            if ($row) {
                Log::writeDebug('Using configured task profile', ['default_for' => $task, 'profileid' => $row['id']]);
                return $row;
            }
        } catch (\PDOException $e) {
            // Embedded, database-free requests still summarize using their current configuration.
            Log::writeDebug('Task defaults unavailable; retaining current model', ['default_for' => $task]);
        }
        return null;
    }

    public static function setDefault($task, $profile = null)
    {
        if (!in_array($task, self::DEFAULT_TASKS, true)) throw new \InvalidArgumentException('Unknown profile default concept');
        $row = $profile === null ? null : (new Registry())->profile($profile);
        if ($profile !== null && !$row) throw new \InvalidArgumentException('Select a saved profile');
        Database::execute('UPDATE profile_defaults SET profileid=? WHERE default_for=?', [$row ? $row['id'] : null, $task]);
        Log::writeSuccess('Task profile default saved', ['default_for' => $task, 'profileid' => $row ? $row['id'] : null]);
    }

    /** Save either the traditional arguments or a profile row/form payload. Enabling still probes it. */
    public static function save($name, $config = null, $enable = false, $overrides = [])
    {
        if ($name instanceof \stdClass || is_array($name) || (is_string($name) && substr(ltrim($name), 0, 1) === '{')) {
            if ($config !== null) throw new \InvalidArgumentException('Supply a profile payload or separate name/config arguments, not both');
            $payload = Config::object($name, 'Profile payload');
            if (!isset($payload['name']) && isset($payload['id'])) {
                $row = (new Registry())->profile(['id' => $payload['id']]);
                if (!$row) throw new \InvalidArgumentException('Unknown profile ID');
                $payload['name'] = $row['name'];
            }
            $name = isset($payload['name']) ? $payload['name'] : '';
            $config = isset($payload['config']) ? $payload['config'] : null;
            $enable = array_key_exists('enabled', $payload) ? $payload['enabled'] : false;
            $overrides = isset($payload['overrides']) ? $payload['overrides'] : [];
        }
        if (!is_string($name) || !trim($name)) throw new \InvalidArgumentException('Profile name is required');
        $enable = Config::boolean($enable, 'Profile enabled');
        // Preserve a legacy exact name, but normalize whitespace for new names/references.
        $existing = (new Registry())->profile(['name' => $name]);
        $name = $existing ? $existing['name'] : trim($name);
        $config = Config::profile($config);
        $overrides = Config::object($overrides, 'Capability overrides');
        foreach ($overrides as $column => $value) {
            if (!array_key_exists($column, Registry::CAPABILITIES)) throw new \InvalidArgumentException('Unknown capability override: ' . $column);
            if ($value !== null && !is_bool($value)) throw new \InvalidArgumentException('Capability overrides must be boolean or null');
        }
        new Request($config);
        $db = Database::connection();
        $db->prepare('INSERT INTO profiles (name, config) VALUES (?, ?::jsonb) ON CONFLICT (name) DO UPDATE SET config = EXCLUDED.config, enabled = FALSE, updated_at = NOW()')
            ->execute([$name, json_encode($config)]);
        if (!$enable) return (new Registry())->profile($name);
        $result = self::test($config);
        $sets = [];
        $values = [];
        foreach (Registry::CAPABILITIES as $column => $label) {
            $value = array_key_exists($column, $overrides) ? $overrides[$column] : $result[$column];
            if ($value !== null && !is_bool($value)) throw new \InvalidArgumentException('Capability overrides must be boolean or null');
            $sets[] = $column . ' = ?';
            $values[] = $value === null ? null : ($value ? 'true' : 'false');
        }
        $working = $result['supports_chat'] || $result['supports_embeddings'] || $result['supports_rerank'];
        $values[] = json_encode(['tests' => $result['metadata'], 'overrides' => $overrides]);
        $values[] = $working ? 'true' : 'false';
        $values[] = $name;
        $db->prepare('UPDATE profiles SET ' . implode(', ', $sets) . ', metadata = ?::jsonb, enabled = ?, tested_at = NOW(), updated_at = NOW() WHERE name = ?')->execute($values);
        if (!$working) throw new \RuntimeException('Profile verification failed. Configure a working model endpoint; see the recorded test results.');
        return (new Registry())->profile($name);
    }

    public static function test($config)
    {
        $config = Config::resolve($config);
        $result = array_fill_keys(array_keys(Registry::CAPABILITIES), null);
        $result['metadata'] = [];
        $baseConfig = array_replace($config, ['temperature' => 0, 'max_tokens' => 512, 'stream' => false, 'max_retries' => 0, 'compaction_enabled' => false, 'timeout' => 90]);
        unset($baseConfig['tools'], $baseConfig['contract']);
        $probe = function ($name, $callback) use (&$result) {
            $started = microtime(true);
            try { $ok = (bool)call_user_func($callback); $error = null; }
            catch (\Throwable $e) { $ok = false; $error = mb_substr($e->getMessage(), 0, 2000); }
            $result[$name] = $ok;
            $result['metadata'][$name] = ['ok' => $ok, 'seconds' => round(microtime(true) - $started, 3), 'error' => $error];
        };
        $reasoning = false;
        $probe('supports_chat', function () use ($baseConfig, &$reasoning) {
            $request = new Request($baseConfig); $request->addMessage('Reply exactly PROFILE_READY');
            $answer = $request->send();
            $message = $request->response['parsed']['choices'][0]['message'];
            $reasoning = !empty($message['reasoning']) || !empty($message['reasoning_content']);
            return strpos($answer, 'PROFILE_READY') !== false;
        });
        if ($result['supports_chat']) {
            $result['supports_reasoning'] = $reasoning;
            $probe('supports_streaming', function () use ($baseConfig) {
                $request = new Request(array_replace($baseConfig, ['stream' => true])); $request->addMessage('Reply exactly STREAM_READY');
                $answer = $request->send();
                return strpos($answer, 'STREAM_READY') !== false && count(array_filter($request->response['events'], function ($event) { return $event['type'] === 'text.delta'; })) > 0;
            });
            $probe('supports_tools', function () use ($baseConfig) {
                $token = 'TOKEN-' . bin2hex(random_bytes(5));
                $request = new Request($baseConfig);
                $request->registerTool(
                    /** Return the private verification token. Call this to obtain the token requested by the user. */
                    function () use ($token) { return $token; }
                );
                $request->addMessage('Call the verification tool and return the token it gives you.');
                return strpos($request->send(), $token) !== false && count($request->response['rounds']) >= 2;
            });
            $probe('supports_structured_output', function () use ($baseConfig) {
                $request = new Request($baseConfig);
                $request->registerContract(['ready' => ['type' => 'boolean']]);
                $request->addMessage('Return ready true.'); $request->send();
                return $request->response['validated']['ready'] === true && !array_filter($request->response['events'], function ($event) { return $event['type'] === 'contract.fallback'; });
            });
            $probe('supports_vision', function () use ($baseConfig) {
                $request = new Request($baseConfig);
                $request->addMessage(['role' => 'user', 'content' => [['type' => 'text', 'text' => 'What is the dominant color in this image? Answer with one color name.'], ['type' => 'image_url', 'image_url' => ['url' => self::imageData()]]]]);
                return stripos($request->send(), 'red') !== false;
            });
        }
        $probe('supports_embeddings', function () use ($config) {
            $response = Models::native('embed',['texts'=>['PHiLLiP capability test']],array_replace($config,['timeout'=>60]));
            return !empty($response['data']['vectors'][0]);
        });
        $probe('supports_rerank', function () use ($config) {
            $response = Models::native('rerank',['query'=>'PHP','documents'=>['PHP is a programming language.','The sky is blue.']],array_replace($config,['timeout'=>60]));
            return !empty($response['data']);
        });
        return $result;
    }

    public static function imageData()
    {
        $chunk = function ($type, $data) { return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data)); };
        $rows = str_repeat("\0" . str_repeat("\xff\0\0", 16), 16);
        $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 16, 16, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress($rows)) . $chunk('IEND', '');
        return 'data:image/png;base64,' . base64_encode($png);
    }
}

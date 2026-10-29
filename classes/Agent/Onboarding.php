<?php
namespace Agent;

/** Resumable first-run state. Stored credentials never leave the bootstrap command. */
class Onboarding
{
    public static function pending()
    {
        $config = Installation::get();
        return $config !== null && isset($config['onboarding']) && $config['onboarding']['stage'] !== 'complete';
    }

    public static function bootstrap($username)
    {
        if (!Runtime::isCli() || ToolContext::get('run_id') || ToolContext::get('request') || ToolContext::get('agent_id')) throw new \RuntimeException('Bootstrap requires a local installation operator');
        if (!is_string($username) || !preg_match('/^[a-zA-Z0-9_.@-]{1,80}$/D', $username)) throw new \InvalidArgumentException('Choose a username containing letters, numbers, dots, @, underscores or hyphens');
        return State::transaction(function () use ($username) {
            Database::row('SELECT pg_advisory_xact_lock(74836, 1)');
            if (Installation::get() !== null) return ['created' => false];
            if ((int)Database::row('SELECT COUNT(*) AS count FROM users')['count']) throw new \RuntimeException('Existing users found without installation settings. Resolve this configuration before bootstrapping; no credentials were changed.');
            $password = bin2hex(random_bytes(16));
            // Establish local mappings before creating the identity; never inherit external identity defaults.
            $config = ['auth' => 'standalone', 'mappings' => [], 'onboarding' => ['stage' => 'password', 'auth_epoch' => bin2hex(random_bytes(16))]];
            self::save($config);
            Installation::defaults();
            $user = Identity::createUser($username, $password, $username, true);
            Installation::defaults($user['id']);
            $config['admin_ids'] = [$user['id']];
            $config['onboarding']['owner_id'] = $user['id'];
            self::save($config);
            Log::writeSuccess('Initial administrator created; password change required');
            return ['created' => true, 'username' => $username, 'password' => $password];
        });
    }

    public static function authorize($user, $passwordChange = false)
    {
        if (!$user) throw new \RuntimeException('Sign in with the administrator credentials printed by ./bin/install');
        Access::admin($user['id']);
        $config = Installation::get();
        if (!$config) throw new \RuntimeException('Run ./bin/install to create your initial administrator');
        Pages::authorize($user['id'], 'models');
        if (self::pending() && (string)$config['onboarding']['owner_id'] !== (string)$user['id']) throw new \RuntimeException('Only the initial administrator may finish setup');
        if (self::pending() && $config['onboarding']['stage'] === 'password' && !$passwordChange) throw new \RuntimeException('Change your temporary password before continuing');
    }

    public static function changePassword($user, $password, $confirmation)
    {
        self::authorize($user, true);
        if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72) throw new \InvalidArgumentException('Use a password of 12 to 72 characters');
        if (strpos($password, "\0") !== false) throw new \InvalidArgumentException('Passwords cannot contain NUL characters');
        if (!is_string($confirmation) || !hash_equals($password, $confirmation)) throw new \InvalidArgumentException('The new passwords do not match');
        State::transaction(function () use ($user, $password) {
            Database::row("SELECT key FROM settings WHERE key = 'installation' FOR UPDATE");
            $config = Installation::get();
            if (!self::pending() || $config['onboarding']['stage'] !== 'password') throw new \RuntimeException('The temporary password has already been replaced');
            $old = Database::row('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
            if (password_verify($password, $old['password_hash'])) throw new \InvalidArgumentException('Choose a new password, not the temporary password');
            Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            $config['onboarding']['stage'] = 'configuration';
            $config['onboarding']['auth_epoch'] = bin2hex(random_bytes(16));
            self::save($config);
        });
        // Rotate this session too; every other temporary-password session becomes invalid.
        Auth::login($user['username'], $password);
        Log::writeSuccess('Temporary password replaced');
    }

    public static function configure($user, array $input)
    {
        self::authorize($user);
        $mode = isset($input['auth']) ? $input['auth'] : 'standalone';
        if (!in_array($mode, ['standalone', 'erp'], true)) throw new \InvalidArgumentException('Select a sign-in method');
        State::transaction(function () use ($mode, $input) {
            Database::row("SELECT key FROM settings WHERE key = 'installation' FOR UPDATE");
            $config = Installation::get();
            if (!self::pending()) throw new \RuntimeException('Setup is already complete');
            unset($config['onboarding']['integration']);
            // Preserve the existing integration contract; external mappings are an explicit advanced choice.
            if (!empty($input['connection'])) {
                Mappings::connection($input['connection']);
                $config['onboarding']['integration'] = ['connection' => $input['connection'], 'mappings' => isset($input['mappings']) ? $input['mappings'] : []];
            }
            if ($mode === 'erp') {
                if (empty($input['session']['user_path'])) throw new \InvalidArgumentException('Provide the ERP session user ID path');
                // Apply a changed authentication source only after authenticated setup is complete.
                $config['onboarding']['integration'] = array_replace(isset($config['onboarding']['integration']) ? $config['onboarding']['integration'] : [], ['auth' => $mode, 'session' => $input['session']]);
            } elseif (!empty($input['mappings']['users']['table'])) {
                // Linking an existing administrator requires its credentials, never merely its claimed row/ID.
                $previous = Installation::$override;
                try {
                    Installation::$override = array_replace($config, $config['onboarding']['integration']);
                    $mapped = Identity::authenticate(isset($input['administrator']) ? $input['administrator'] : []);
                    $config['onboarding']['integration']['admin_ids'] = [(string)$mapped['id']];
                } finally { Installation::$override = $previous; }
            }
            $config['onboarding']['stage'] = 'models';
            self::save($config);
            Config::configureServices([
                'searxng' => ['url' => 'http://searxng:8080', 'url_env' => 'SEARXNG_URL'],
                'camoufox' => ['url' => 'http://browser:8080', 'url_env' => 'CAMOUFOX_URL', 'token_env' => 'BROWSER_TOKEN'],
            ]);
        });
        Log::writeSuccess('Installation preferences saved');
    }

    public static function scan($user, $cidr)
    {
        self::authorize($user);
        $payload = Scanner::configuration(['cidr' => $cidr]);
        $hosts = 0;
        foreach (Network::cidrs($payload['cidr']) as $range) { $parts = explode('/', $range); $hosts += pow(2, 32 - (isset($parts[1]) ? (int)$parts[1] : 32)); }
        if ($hosts > 4096) throw new \InvalidArgumentException('Choose a network containing at most 4096 addresses');
        return self::queue('scan', $payload);
    }

    public static function endpoint($user, $url, $apiKey = null)
    {
        self::authorize($user);
        $url = rtrim(trim((string)$url), '/');
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) throw new \InvalidArgumentException('Enter an HTTP(S) endpoint without credentials, query or fragment');
        $url = preg_replace('~/v1(?:/(?:models|chat/completions))?$~', '', $url);
        $headers = [];
        if ($apiKey !== null && $apiKey !== '') {
            if (!is_string($apiKey) || strlen($apiKey) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $apiKey)) throw new \InvalidArgumentException('Enter a valid single-line API key');
            $headers = ['Authorization' => 'Bearer ' . trim($apiKey)];
            $response = Http::request($url . '/v1/models', null, $headers, 5);
            $found = (new Scanner())->inspect($url, ['/v1/models' => $response]);
        } else $found = (new Scanner())->inspect($url);
        if (empty($found['models'])) throw new \RuntimeException('No compatible models were returned. Check the address and ensure the model engine is running.');
        (new Registry())->record($found['endpoint'], $found['models']);
        if ($headers) foreach ($found['models'] as $model) {
            $name = Registry::profileName($url, $model['model']);
            $config = Config::resolve((new Registry())->profile(['name' => $name]));
            $config['headers'] = $headers;
            Profiles::save($name, $config);
        }
        return ['count' => count($found['models'])];
    }

    public static function selectModel($user, $id)
    {
        self::authorize($user);
        if (!is_scalar($id) || !ctype_digit((string)$id)) throw new \InvalidArgumentException('Choose a discovered model');
        $profile = (new Registry())->profile(['id' => $id]);
        if (!$profile) throw new \InvalidArgumentException('Choose a discovered model');
        return self::queue('profile_verify', ['profile_id' => (int)$id]);
    }

    private static function queue($type, array $payload)
    {
        return State::transaction(function () use ($type, $payload) {
            Database::row('SELECT pg_advisory_xact_lock(74836, 2)');
            if ($type === 'profile_verify') {
                $active = Database::row("SELECT id, payload FROM operations WHERE type='profile_verify' AND status IN ('queued','running') ORDER BY id LIMIT 1");
                if ($active) {
                    if (json_decode($active['payload'], true) === $payload) return ['id' => $active['id']];
                    throw new \RuntimeException('Another model is being verified. Wait for that check to finish before choosing a different model.');
                }
            }
            $old = Database::row("SELECT id FROM operations WHERE type = ? AND status IN ('queued','running') AND payload = ?::jsonb ORDER BY id DESC LIMIT 1", [$type, State::encode($payload)]);
            return $old ?: Database::row('INSERT INTO operations(type, payload) VALUES (?, ?::jsonb) RETURNING id', [$type, State::encode($payload)]);
        });
    }

    public static function status($user)
    {
        self::authorize($user);
        $profiles = [];
        foreach ((new Registry())->profiles() as $row) {
            $config = Config::resolve($row);
            $profiles[] = ['id' => $row['id'], 'name' => $row['name'], 'model' => isset($config['model']) ? $config['model'] : $config['body']['model'], 'url' => $config['url'], 'enabled' => $row['enabled'], 'chat' => $row['supports_chat']];
        }
        return ['profiles' => $profiles, 'operations' => Database::rows("SELECT id, type, status, updated_at FROM operations WHERE type IN ('scan','profile_verify') ORDER BY id DESC LIMIT 8"), 'ready' => self::ready()];
    }

    public static function ready()
    {
        $row = (new Registry())->profile(['name' => 'default']);
        return $row && $row['enabled'] && $row['supports_chat'] && $row['tested_at'] !== null
            && !Database::row("SELECT id FROM operations WHERE type='profile_verify' AND status IN ('queued','running') LIMIT 1");
    }

    public static function finish($user)
    {
        self::authorize($user);
        State::transaction(function () {
            Database::row("SELECT key FROM settings WHERE key = 'installation' FOR UPDATE");
            if (!self::ready()) throw new \RuntimeException('Verify a working default chat model before finishing setup');
            $config = Installation::get();
            if (self::pending() && $config['onboarding']['stage'] !== 'models') throw new \RuntimeException('Save your installation preferences before finishing');
            if (isset($config['onboarding']['integration'])) {
                $future = array_replace($config, $config['onboarding']['integration']);
                if ($future['auth'] === 'erp') {
                    $previous = Installation::$override;
                    try {
                        Installation::$override = $future;
                        $session = Auth::current();
                        if (!$session['user']) throw new \RuntimeException('Sign in through the configured ERP session before finishing');
                        $future['admin_ids'] = [(string)$session['user']['id']];
                    } finally { Installation::$override = $previous; }
                }
                $config = $future;
            }
            unset($config['onboarding']['integration']);
            $config['onboarding']['stage'] = 'complete';
            self::save($config);
        });
        Log::writeSuccess('PHiLLiP is ready to chat');
    }

    private static function save(array $config)
    {
        Database::execute("INSERT INTO settings(key,value) VALUES ('installation',?::jsonb) ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value", [State::encode($config)]);
    }
}

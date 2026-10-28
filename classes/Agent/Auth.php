<?php
namespace Agent;

class Auth
{
    private static function open()
    {
        $config = Installation::get() ?: ['auth' => 'standalone'];
        if ($config['auth'] === 'erp' && !empty($config['session']['bootstrap'])) {
            if (!is_readable($config['session']['bootstrap'])) throw new \RuntimeException('Configured ERP session bootstrap is not readable');
            require_once $config['session']['bootstrap'];
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (!empty($config['session']['name'])) session_name($config['session']['name']);
            ini_set('session.use_strict_mode', '1');
            ini_set('session.cookie_samesite', 'Lax');
            session_set_cookie_params(0, (getenv('APP_BASE_PATH') ?: '') . '/', '', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', true);
            session_start();
        }
        if (empty($_SESSION['phillip_csrf'])) $_SESSION['phillip_csrf'] = bin2hex(random_bytes(24));
        return $config;
    }

    public static function current()
    {
        $config = self::open();
        if ($config['auth'] === 'erp') {
            $id = $_SESSION;
            foreach (explode('.', isset($config['session']['user_path']) ? $config['session']['user_path'] : 'user_id') as $key) $id = is_array($id) && isset($id[$key]) ? $id[$key] : null;
        } else $id = isset($_SESSION['phillip_user_id']) ? $_SESSION['phillip_user_id'] : null;
        if ($config['auth'] === 'standalone' && isset($_SESSION['phillip_last_seen']) && (int)ini_get('session.gc_maxlifetime') > 0
            && time() - (int)$_SESSION['phillip_last_seen'] > (int)ini_get('session.gc_maxlifetime')) {
            $id = null; unset($_SESSION['phillip_user_id'], $_SESSION['phillip_last_seen']);
        }
        $user = $id !== null ? Identity::user(['id' => $id]) : null;
        if ($user && isset($config['onboarding']['owner_id'], $config['onboarding']['auth_epoch']) && (string)$user['id'] === (string)$config['onboarding']['owner_id']
            && (!isset($_SESSION['phillip_auth_epoch']) || !hash_equals($config['onboarding']['auth_epoch'], $_SESSION['phillip_auth_epoch']))) $user = null;
        // A trusted ERP session can use local identity tables without a mapped users table.
        if (!$user && $config['auth'] === 'erp' && is_scalar($id) && (string)$id !== '' && empty($config['mappings']['users']['table'])) {
            Mappings::insert('users', ['id' => (string)$id, 'username' => 'erp:' . (string)$id, 'display_name' => 'ERP user ' . (string)$id,
                'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), 'is_admin' => false, 'active' => true]);
            $user = Identity::user(['id' => $id]);
        }
        if ($user && !$user['active']) $user = null;
        if ($user && $config['auth'] === 'standalone') $_SESSION['phillip_last_seen'] = time();
        Runtime::bindIdentity($user);
        $csrf = $_SESSION['phillip_csrf'];
        session_write_close();
        return ['user' => $user, 'csrf' => $csrf, 'auth_mode' => $config['auth']];
    }

    /** Resume an existing authenticated session; an invalid bearer never falls back to Unix identity. */
    public static function fromSession($id)
    {
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9,-]{16,256}$/D', $id)) throw new \InvalidArgumentException('Invalid session identifier');
        if (session_status() === PHP_SESSION_ACTIVE) throw new \RuntimeException('Close the active session before resuming another');
        $savedId = session_id(); $cookies = ini_get('session.use_cookies'); $onlyCookies = ini_get('session.use_only_cookies');
        try {
            ini_set('session.use_cookies', '0'); ini_set('session.use_only_cookies', '0'); session_id($id);
            $state = self::current();
            if (!hash_equals($id, session_id()) || !$state['user']) throw new \RuntimeException('Session is invalid, expired, or unauthenticated');
            if (Onboarding::pending()) throw new \RuntimeException('Finish web setup before using a frontend session');
            return $state['user'];
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            session_id($savedId); ini_set('session.use_cookies', $cookies); ini_set('session.use_only_cookies', $onlyCookies);
        }
    }

    public static function login($username, $password = null)
    {
        $config = self::open();
        if ($config['auth'] !== 'standalone') { session_write_close(); throw new \RuntimeException('Sign in through the configured ERP application'); }
        try {
            $user = Identity::authenticate($username, $password);
            session_regenerate_id(true);
            $_SESSION['phillip_user_id'] = $user['id'];
            $_SESSION['phillip_last_seen'] = time();
            $_SESSION['phillip_csrf'] = bin2hex(random_bytes(24));
            if (isset($config['onboarding']['auth_epoch'])) $_SESSION['phillip_auth_epoch'] = $config['onboarding']['auth_epoch'];
        } finally { session_write_close(); }
        return self::current();
    }

    public static function logout()
    {
        self::open();
        unset($_SESSION['phillip_user_id']);
        $_SESSION['phillip_csrf'] = bin2hex(random_bytes(24));
        session_regenerate_id(true);
        session_write_close();
    }
}

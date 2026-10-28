<?php
namespace Agent;

class Installation
{
    public static $override;

    public static function get()
    {
        if (self::$override !== null) return self::$override;
        $value = Database::connection()->query("SELECT value FROM settings WHERE key = 'installation'")->fetchColumn();
        if ($value !== false) return json_decode($value, true);
        $file = dirname(__DIR__, 2) . '/config/installation.local.json';
        return is_readable($file) ? json_decode(file_get_contents($file), true) : null;
    }

    public static function install(array $configuration, $username, $password, $displayName)
    {
        if (self::get() !== null) throw new \RuntimeException('Onboarding has already been completed');
        if (!in_array($configuration['auth'], ['standalone', 'erp'], true)) throw new \InvalidArgumentException('Select one authentication mode');
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO settings (key, value) VALUES ('installation', ?::jsonb)")->execute([json_encode($configuration)]);
            if ($configuration['auth'] === 'erp') {
                $state = Auth::current();
                if (!$state['user']) throw new \RuntimeException('The configured ERP session must identify a user');
                $user = $state['user'];
            } elseif (empty($configuration['mappings']['users']['table'])) {
                if (strlen($password) < 10) throw new \InvalidArgumentException('Use an administrator password of at least 10 characters');
                $user = Identity::createUser($username, $password, $displayName, true);
            } else $user = Identity::authenticate($username, $password);
            $configuration['admin_ids'] = [(string)$user['id']];
            $db->prepare("UPDATE settings SET value = ?::jsonb WHERE key = 'installation'")->execute([json_encode($configuration)]);
            $db->commit();
            return $user;
        } catch (\Throwable $e) { $db->rollBack(); throw $e; }
    }
}

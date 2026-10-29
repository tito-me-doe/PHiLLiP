<?php
namespace Agent;

class Installation
{
    public static $override;

    /** Idempotent first-run defaults; Everyone's membership is implicit, not a join row. */
    public static function defaults($firstUserId = null)
    {
        Database::execute("INSERT INTO teams(id,name) VALUES ('0','Everyone'),('1','Administrators'),('2','Developers') ON CONFLICT DO NOTHING");
        foreach (['page.conversations','Agent\\Tools\\SearchWeb','Agent\\Tools\\Fetch','Agent\\Tools\\BrowseWeb','Agent\\Tools\\Memory'] as $resource) {
            Identity::grant('group','0',$resource);
        }
        if ($firstUserId !== null) foreach (['1','2'] as $group) {
            if (!Mappings::rows('memberships',['user_id'=>(string)$firstUserId,'team_id'=>$group])) {
                Mappings::insert('memberships',['user_id'=>(string)$firstUserId,'team_id'=>$group]);
            }
        }
    }

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
            self::defaults();
            if ($configuration['auth'] === 'erp') {
                $state = Auth::current();
                if (!$state['user']) throw new \RuntimeException('The configured ERP session must identify a user');
                $user = $state['user'];
            } elseif (empty($configuration['mappings']['users']['table'])) {
                if (strlen($password) < 10) throw new \InvalidArgumentException('Use an administrator password of at least 10 characters');
                $user = Identity::createUser($username, $password, $displayName, true);
            } else $user = Identity::authenticate($username, $password);
            $configuration['admin_ids'] = [(string)$user['id']];
            self::defaults($user['id']);
            $db->prepare("UPDATE settings SET value = ?::jsonb WHERE key = 'installation'")->execute([json_encode($configuration)]);
            $db->commit();
            return Identity::user(['id'=>$user['id']]);
        } catch (\Throwable $e) { $db->rollBack(); throw $e; }
    }
}

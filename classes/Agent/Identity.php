<?php
namespace Agent;

class Identity
{
    /** Resolve an ID, unique username, or row-shaped reference against the authoritative mapping. */
    public static function user($input)
    {
        if ($input === null || $input === '') return null;
        if ($input instanceof \stdClass) $input = (array)$input;
        if (is_string($input) && substr(ltrim($input), 0, 1) === '{') {
            $decoded = json_decode($input, true);
            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) throw new \InvalidArgumentException('Invalid user reference JSON');
            $input = $decoded;
        }
        if (is_array($input)) {
            // Row fields, including privilege flags, are references, never authentication or authority.
            if (isset($input['id'])) {
                if (!is_string($input['id']) && !is_int($input['id'])) throw new \InvalidArgumentException('User reference id must be text or an integer');
                $criteria = ['id' => (string)$input['id']];
            }
            elseif (isset($input['username']) && is_string($input['username'])) $criteria = ['username' => $input['username']];
            else throw new \InvalidArgumentException('User reference requires an id or username');
            $rows = isset($criteria['username']) ? self::usernameRows($criteria['username']) : Mappings::rows('users', $criteria);
        } elseif (is_string($input) || is_int($input)) {
            $ids = Mappings::rows('users', ['id' => (string)$input]);
            $names = Mappings::rows('users', ['username' => (string)$input]);
            if ($ids && $names && $ids[0]['id'] !== $names[0]['id']) throw new \InvalidArgumentException('Ambiguous user reference; specify id or username explicitly');
            if (!$ids && !$names) $names = self::usernameRows((string)$input);
            $rows = $ids ?: $names;
        } else throw new \InvalidArgumentException('User reference requires an id, username, or row');
        return $rows ? self::publicUser($rows[0]) : null;
    }

    /** Canonical public rows without per-row lookups or exposing password hashes. */
    public static function users(array $criteria = [])
    {
        $installation = Installation::get() ?: [];
        return array_map(function ($row) use ($installation) { return self::publicUser($row, $installation); }, Mappings::rows('users', $criteria));
    }

    private static function publicUser(array $user, $installation = null)
    {
        unset($user['password_hash']);
        if ($installation === null) $installation = Installation::get() ?: [];
        if (in_array((string)$user['id'], isset($installation['admin_ids']) ? $installation['admin_ids'] : [], true)) $user['is_admin'] = true;
        return $user;
    }

    /** Prefer exact legacy usernames; tolerate boundary whitespace only when no exact row exists. */
    private static function usernameRows($username)
    {
        $rows = Mappings::rows('users', ['username' => $username]);
        $trimmed = trim($username);
        return !$rows && $trimmed !== '' && $trimmed !== $username ? Mappings::rows('users', ['username' => $trimmed]) : $rows;
    }

    /** Accept positional credentials or a username/password payload; never accept a password hash as proof. */
    public static function authenticate($username, $password = null)
    {
        if ($username instanceof \stdClass) $username = (array)$username;
        if (is_array($username)) {
            if ($password !== null) throw new \InvalidArgumentException('Supply credentials as either a payload or separate arguments');
            $password = isset($username['password']) ? $username['password'] : null;
            $username = isset($username['username']) ? $username['username'] : null;
        }
        if (!is_string($username) || $username === '' || !is_string($password)) throw new \InvalidArgumentException('Credentials require a username and password');
        $rows = self::usernameRows($username);
        $user = $rows ? $rows[0] : null;
        if (!$user || !$user['active'] || !password_verify($password, (string)$user['password_hash'])) throw new \RuntimeException('Invalid username or password');
        return self::publicUser($user);
    }

    public static function createUser($username, $password = null, $displayName = null, $isAdmin = false)
    {
        if ($username instanceof \stdClass) $username = (array)$username;
        if (is_array($username)) {
            if ($password !== null || $displayName !== null || $isAdmin !== false) throw new \InvalidArgumentException('Supply user fields as either a payload or separate arguments');
            $data = $username;
            $username = isset($data['username']) ? $data['username'] : null;
            $password = isset($data['password']) ? $data['password'] : null;
            $displayName = isset($data['display_name']) ? $data['display_name'] : null;
            $isAdmin = isset($data['is_admin']) ? Config::boolean($data['is_admin'], 'is_admin') : false;
        }
        if (!is_string($username) || $username === '' || !is_string($password)) throw new \InvalidArgumentException('User creation requires a username and password');
        $username = trim($username);
        if ($username === '') throw new \InvalidArgumentException('User creation requires a username and password');
        if ($displayName === null || $displayName === '') $displayName = $username;
        if (!is_string($displayName)) throw new \InvalidArgumentException('Display name must be text');
        $isAdmin = Config::boolean($isAdmin, 'is_admin');
        $id = bin2hex(random_bytes(16));
        $id = Mappings::insert('users', ['id' => $id, 'username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'display_name' => $displayName, 'is_admin' => $isAdmin ? 'true' : 'false', 'active' => 'true']);
        return self::user(['id' => $id]);
    }

    public static function teams($userId)
    {
        $ids = array_map('strval', array_column(Mappings::rows('memberships', ['user_id' => (string)$userId]), 'team_id'));
        return array_values(array_filter(Mappings::rows('teams'), function ($team) use ($ids) { return in_array((string)$team['id'], $ids, true); }));
    }

    /** UI choices only. Authorization continues to use every real membership, including singleton teams. */
    public static function sharedTeams($userId)
    {
        $teams = self::teams($userId);
        if (!$teams) return [];
        $config = Installation::get() ?: [];
        if (empty($config['mappings']['users']['table']) && empty($config['mappings']['memberships']['table'])) {
            $ids = array_column($teams, 'id');
            $rows = Database::rows('SELECT DISTINCT m.team_id FROM memberships m JOIN users u ON u.id=m.user_id WHERE u.active=TRUE AND m.user_id<>? AND m.team_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_merge([(string)$userId], $ids));
            $shared = array_column($rows, 'team_id');
            return array_values(array_filter($teams, function ($team) use ($shared) { return in_array((string)$team['id'], $shared, true); }));
        }
        $active = array_column(self::users(['active' => true]), 'id');
        $members = [];
        foreach (Mappings::rows('memberships') as $row) if ((string)$row['user_id'] !== (string)$userId && in_array((string)$row['user_id'], $active, true)) $members[(string)$row['team_id']] = true;
        return array_values(array_filter($teams, function ($team) use ($members) { return isset($members[(string)$team['id']]); }));
    }

    public static function grant($scopeType, $scopeId, $tool, $allowed)
    {
        Mappings::upsert('permissions', ['scope_type' => $scopeType, 'scope_id' => (string)$scopeId, 'tool' => ltrim($tool, '\\')], ['allowed' => Config::boolean($allowed, 'Permission allowed')]);
    }
}

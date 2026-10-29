<?php
namespace Agent;

class Mappings
{
    const TABLES = ['users' => 'users', 'teams' => 'teams', 'memberships' => 'memberships', 'permissions' => 'permissions'];
    const COLUMNS = ['users' => ['id', 'username', 'password_hash', 'display_name', 'is_admin', 'active'], 'teams' => ['id', 'name'],
        'memberships' => ['user_id', 'team_id'], 'permissions' => ['scope_type', 'scope_id', 'tool', 'allowed']];

    public static function connection(array $config)
    {
        return new \PDO($config['dsn'], isset($config['username']) ? $config['username'] : null, isset($config['password']) ? $config['password'] : null,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    }

    public static function inspect(array $config, $table = null)
    {
        $db = self::connection($config);
        $driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($table !== null) {
            $q = $db->query('SELECT * FROM ' . self::identifier($db, $table) . ' WHERE 1=0');
            $columns = [];
            for ($i = 0; $i < $q->columnCount(); $i++) $columns[] = $q->getColumnMeta($i)['name'];
            return $columns;
        }
        if ($driver === 'sqlite') return $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        if ($driver === 'mysql') return $db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        return $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name")->fetchAll(\PDO::FETCH_COLUMN);
    }

    private static function source($entity)
    {
        if (!isset(self::TABLES[$entity])) throw new \InvalidArgumentException('Unknown identity mapping');
        $installation = Installation::get() ?: [];
        $spec = isset($installation['mappings'][$entity]) ? $installation['mappings'][$entity] : [];
        if (empty($spec['table'])) {
            $columns = array_combine(self::COLUMNS[$entity], self::COLUMNS[$entity]);
            if ($entity === 'permissions') $columns['tool'] = 'resource'; // Legacy ERP mapping key, not a tool-only policy.
            return [Database::connection(), ['table' => self::TABLES[$entity], 'columns' => $columns, 'writable' => true]];
        }
        return [self::connection(isset($spec['connection']) ? $spec['connection'] : $installation['connection']), $spec];
    }

    private static function identifier($db, $name)
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $name)) throw new \InvalidArgumentException('Invalid database identifier');
        $quote = $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? '`' : '"';
        return $quote . str_replace('.', $quote . '.' . $quote, $name) . $quote;
    }

    public static function rows($entity, array $criteria = [])
    {
        list($db, $spec) = self::source($entity);
        $where = [];
        $params = [];
        foreach ($criteria as $key => $value) if (isset($spec['columns'][$key]) && is_string($spec['columns'][$key]) && strpos($spec['columns'][$key], 'literal:') !== 0) {
            $where[] = self::identifier($db, $spec['columns'][$key]) . ' = ?'; $params[] = self::writeValue($db, $spec, $key, $value);
        }
        $q = $db->prepare('SELECT * FROM ' . self::identifier($db, $spec['table']) . ($where ? ' WHERE ' . implode(' AND ', $where) : ''));
        $q->execute($params);
        $result = [];
        foreach ($q->fetchAll() as $row) {
            $normalized = [];
            foreach (self::COLUMNS[$entity] as $key) {
                $column = isset($spec['columns'][$key]) ? $spec['columns'][$key] : null;
                $normalized[$key] = is_string($column) && strpos($column, 'literal:') === 0 ? substr($column, 8) : ($column !== null && isset($row[$column]) ? $row[$column] : null);
            }
            if ($entity === 'users') {
                $normalized['id'] = (string)$normalized['id'];
                $normalized['display_name'] = $normalized['display_name'] ?: $normalized['username'];
                $normalized['is_admin'] = in_array($normalized['is_admin'], [true, 1, '1', 'true', 't'], true);
                $normalized['active'] = $normalized['active'] === null || in_array($normalized['active'], [true, 1, '1', 'true', 't'], true);
            }
            if ($entity === 'permissions') {
                if (isset($spec['tool_values'][$normalized['tool']])) $normalized['tool'] = $spec['tool_values'][$normalized['tool']];
                $normalized['allowed'] = in_array($normalized['allowed'], [true, 1, '1', 'true', 't'], true);
            }
            $matches = true;
            foreach ($criteria as $key => $value) if ((string)$normalized[$key] !== (string)$value) $matches = false;
            if ($matches) $result[] = $normalized;
        }
        return $result;
    }

    public static function insert($entity, array $values)
    {
        list($db, $spec) = self::source($entity);
        if (empty($spec['writable'])) throw new \RuntimeException('This identity mapping is read-only');
        if (!empty($spec['auto_id'])) unset($values['id']);
        $columns = [];
        $params = [];
        foreach ($values as $key => $value) if (isset($spec['columns'][$key]) && strpos($spec['columns'][$key], 'literal:') !== 0) {
            $columns[] = self::identifier($db, $spec['columns'][$key]); $params[] = self::writeValue($db, $spec, $key, $value);
        }
        $sql = 'INSERT INTO ' . self::identifier($db, $spec['table']) . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $returning = !empty($spec['auto_id']) && $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql';
        if ($returning) $sql .= ' RETURNING ' . self::identifier($db, $spec['columns']['id']);
        $q = $db->prepare($sql); $q->execute($params);
        if (isset($values['id'])) return $values['id'];
        // Join/policy tables have no generated identifier; PostgreSQL lastval() would throw.
        if (empty($spec['auto_id'])) return null;
        return $returning ? $q->fetchColumn() : $db->lastInsertId();
    }

    public static function upsert($entity, array $criteria, array $values)
    {
        list($db, $spec) = self::source($entity);
        if (empty($spec['writable'])) throw new \RuntimeException('This identity mapping is read-only');
        if (!self::rows($entity, $criteria)) return self::insert($entity, array_merge($criteria, $values));
        $sets = []; $where = []; $params = [];
        foreach ($values as $key => $value) {
            if (empty($spec['columns'][$key]) || strpos($spec['columns'][$key], 'literal:') === 0) throw new \RuntimeException('Mapped column is not writable: ' . $key);
            $sets[] = self::identifier($db, $spec['columns'][$key]) . ' = ?'; $params[] = self::writeValue($db, $spec, $key, $value);
        }
        foreach ($criteria as $key => $value) if (!empty($spec['columns'][$key]) && strpos($spec['columns'][$key], 'literal:') !== 0) {
            $where[] = self::identifier($db, $spec['columns'][$key]) . ' = ?'; $params[] = self::writeValue($db, $spec, $key, $value);
        }
        if (!$where) throw new \RuntimeException('Mapped update requires identifying columns');
        $db->prepare('UPDATE ' . self::identifier($db, $spec['table']) . ' SET ' . implode(', ', $sets) . ' WHERE ' . implode(' AND ', $where))->execute($params);
    }

    public static function delete($entity, array $criteria)
    {
        list($db, $spec) = self::source($entity);
        if (empty($spec['writable'])) throw new \RuntimeException('This identity mapping is read-only');
        $where = []; $params = [];
        foreach ($criteria as $key => $value) {
            if (empty($spec['columns'][$key]) || strpos($spec['columns'][$key], 'literal:') === 0) throw new \RuntimeException('Deletion requires mapped identifying columns');
            $where[] = self::identifier($db, $spec['columns'][$key]) . ' = ?'; $params[] = self::writeValue($db, $spec, $key, $value);
        }
        if (!$where) throw new \RuntimeException('Deletion requires identifying columns');
        $db->prepare('DELETE FROM ' . self::identifier($db, $spec['table']) . ' WHERE ' . implode(' AND ', $where))->execute($params);
    }

    private static function writeValue($db, array $spec, $key, $value)
    {
        if ($key === 'tool' && !empty($spec['tool_values'])) {
            $external = array_search($value, $spec['tool_values'], true);
            if ($external !== false) return $external;
        }
        if (in_array($key, ['is_admin', 'active', 'allowed'])) {
            $boolean = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            return $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql' ? ($boolean ? 'true' : 'false') : (int)$boolean;
        }
        return $value;
    }
}

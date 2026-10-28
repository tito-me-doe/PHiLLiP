<?php
namespace Agent;

/** Durable, resource-scoped state. Application callers are trusted; frontend adapters enforce ACLs. */
class State
{
    public $id;
    private function __construct($id) { $this->id = (int)$id; }

    public static function open($scope, $resourceId, array $initial = [], $parent = null, array $links = [])
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $scope) || (string)$resourceId === '' || strlen((string)$resourceId) > 255) throw new \InvalidArgumentException('State requires a scope and resource identifier');
        if ($parent !== null && !$parent instanceof self) throw new \InvalidArgumentException('Parent must be a state handle');
        $values = [$scope, (string)$resourceId, $parent ? $parent->id : null];
        foreach (['thread_id', 'run_id', 'user_id'] as $key) $values[] = isset($links[$key]) ? $links[$key] : null;
        $values[] = self::encode((object)$initial);
        $row = Database::row('INSERT INTO states (scope, resource_id, parent_id, thread_id, run_id, user_id, value) VALUES (?, ?, ?, ?, ?, ?, ?::jsonb)
            ON CONFLICT (scope, resource_id) DO NOTHING RETURNING id, parent_id, thread_id, run_id, user_id', $values);
        if (!$row) $row = Database::row('SELECT id, parent_id, thread_id, run_id, user_id FROM states WHERE scope = ? AND resource_id = ?', [$scope, (string)$resourceId]);
        if ($parent && (int)$row['parent_id'] !== $parent->id) throw new \RuntimeException('State already belongs to a different parent');
        foreach ($links as $key => $value) if (!array_key_exists($key, $row) || (string)$row[$key] !== (string)$value) throw new \RuntimeException('State resource association does not match');
        return new self($row['id']);
    }

    public static function thread($id) { return self::open('thread', (string)$id, [], null, ['thread_id' => (int)$id]); }
    public static function run($id)
    {
        $run = Run::get($id);
        return self::open('run', (string)$id, ['phase' => $run['status'], 'content' => '', 'reasoning' => '', 'progress' => '', 'last_event_id' => 0], self::thread($run['thread_id']), ['thread_id' => (int)$run['thread_id'], 'run_id' => (int)$id]);
    }

    public function child($scope, $resourceId, array $initial = [])
    {
        $row = $this->read(); $links = [];
        foreach (['thread_id', 'run_id', 'user_id'] as $key) if ($row[$key] !== null) $links[$key] = $row[$key];
        return self::open($scope, $resourceId, $initial, $this, $links);
    }

    public function read()
    {
        $row = Database::row('SELECT * FROM states WHERE id = ?', [$this->id]);
        if (!$row) throw new \RuntimeException('State no longer exists'); return self::decode($row);
    }
    public function children()
    {
        return array_map([self::class, 'decode'], Database::rows('SELECT * FROM states WHERE parent_id = ? ORDER BY id', [$this->id]));
    }
    public function patch(array $values, $expectedRevision = null) { return $this->write('value || ?::jsonb', [self::encode((object)$values)], $expectedRevision); }
    public function replace(array $values, $expectedRevision = null) { return $this->write('?::jsonb', [self::encode((object)$values)], $expectedRevision); }
    public function mergeMap($key, array $values, $expectedRevision = null)
    {
        return $this->write("jsonb_set(value, ARRAY[?::text], COALESCE(value->?::text, '{}'::jsonb) || ?::jsonb)", [$key, $key, self::encode((object)$values)], $expectedRevision);
    }
    private function write($expression, array $params, $expectedRevision)
    {
        $sql = 'UPDATE states SET value = ' . $expression . ', revision = revision + 1, updated_at = NOW() WHERE id = ?'; $params[] = $this->id;
        if ($expectedRevision !== null) { $sql .= ' AND revision = ?'; $params[] = (int)$expectedRevision; }
        $row = Database::row($sql . ' RETURNING *', $params);
        if (!$row) {
            $this->read();
            throw new StateConflict('State changed since it was read; reload and retry the update');
        }
        return self::decode($row);
    }
    public static function decode(array $row) { $row['value'] = json_decode($row['value'], true); $row['revision'] = (int)$row['revision']; return $row; }
    public static function encode($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) throw new \InvalidArgumentException('State must be valid JSON: ' . json_last_error_msg()); return $json;
    }
    public static function transaction($callback, $snapshot = false)
    {
        $db = Database::connection(); $own = !$db->inTransaction();
        if ($own) { $db->beginTransaction(); if ($snapshot) $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); }
        try { $result = call_user_func($callback); if ($own) $db->commit(); return $result; }
        catch (\Throwable $e) { if ($own) $db->rollBack(); throw $e; }
    }
}

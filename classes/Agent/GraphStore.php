<?php
namespace Agent;

/** Graph namespace/entity/alias/edge contracts with bounded read-only traversal. */
class GraphStore
{
    const MAX_NEIGHBORS = 100;
    const MAX_TRAVERSAL_DEPTH = 5;
    const MAX_TRAVERSAL_LIMIT = 250;

    public static function createEntity($namespace, $name, $properties = [])
    {
        if (!is_string($namespace) || !trim($namespace)) throw new \InvalidArgumentException('Entity needs a namespace');
        if (!is_string($name) || !trim($name)) throw new \InvalidArgumentException('Entity needs a name');
        if (!is_array($properties)) $properties = [];
        return Database::row(
            'INSERT INTO knowledge_entities (namespace, name, properties) VALUES (?, ?, ?) RETURNING *',
            [$namespace, $name, json_encode($properties)]
        );
    }

    /** Resolve an entity by namespace and name; throws on ambiguity. */
    public static function resolve($namespace, $name)
    {
        $rows = Database::rows(
            'SELECT * FROM knowledge_entities WHERE namespace = ? AND name = ? ORDER BY id',
            [$namespace, $name]
        );
        if (!$rows) throw new \RuntimeException('Entity not found');
        if (count($rows) > 1) throw new \RuntimeException('Ambiguous entity: multiple entities match namespace and name');
        return $rows[0];
    }

    public static function resolveByAlias($alias)
    {
        if (!is_string($alias) || !trim($alias)) throw new \InvalidArgumentException('Alias cannot be empty');
        $row = Database::row(
            'SELECT e.* FROM knowledge_entities e JOIN knowledge_entity_aliases a ON a.entity_id = e.id WHERE a.alias = ?',
            [$alias]
        );
        if (!$row) throw new \RuntimeException('Alias not found');
        return $row;
    }

    public static function createAlias($entityId, $alias)
    {
        self::getEntity($entityId);
        if (!is_string($alias) || !trim($alias)) throw new \InvalidArgumentException('Alias cannot be empty');
        return Database::row(
            'INSERT INTO knowledge_entity_aliases (entity_id, alias) VALUES (?, ?) RETURNING *',
            [$entityId, $alias]
        );
    }

    public static function createEdge($sourceId, $targetId, $relation, $properties = [])
    {
        self::getEntity($sourceId);
        self::getEntity($targetId);
        if (!is_string($relation) || !trim($relation)) throw new \InvalidArgumentException('Edge needs a relation');
        if (!is_array($properties)) $properties = [];
        return Database::row(
            'INSERT INTO knowledge_edges (source_id, target_id, relation, properties)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (source_id, target_id, relation) DO UPDATE
             SET properties = EXCLUDED.properties, created_at = NOW()
             RETURNING *',
            [$sourceId, $targetId, $relation, json_encode($properties)]
        );
    }

    /** Bounded read-only neighbors. Direction: out (default), in, or both. */
    public static function neighbors($entityId, $direction = 'out', $limit = 10)
    {
        self::getEntity($entityId);
        $limit = max(1, min(self::MAX_NEIGHBORS, (int)$limit));
        if ($direction === 'in') {
            $sql = 'SELECT e.target_id AS id, t.namespace, t.name, e.relation, e.properties
                    FROM knowledge_edges e JOIN knowledge_entities t ON t.id = e.target_id
                    WHERE e.source_id = ? ORDER BY e.id DESC LIMIT ?';
            return Database::rows($sql, [$entityId, $limit]);
        }
        if ($direction === 'both') {
            $sql = 'SELECT e.target_id AS id, t.namespace, t.name, e.relation, e.properties
                    FROM knowledge_edges e JOIN knowledge_entities t ON t.id = e.target_id WHERE e.source_id = ?
                    UNION ALL
                    SELECT e.source_id AS id, t.namespace, t.name, e.relation, e.properties
                    FROM knowledge_edges e JOIN knowledge_entities t ON t.id = e.source_id WHERE e.target_id = ?
                    ORDER BY id DESC LIMIT ?';
            return Database::rows($sql, [$entityId, $entityId, $limit]);
        }
        $sql = 'SELECT e.target_id AS id, t.namespace, t.name, e.relation, e.properties
                FROM knowledge_edges e JOIN knowledge_entities t ON t.id = e.target_id
                WHERE e.source_id = ? ORDER BY e.id DESC LIMIT ?';
        return Database::rows($sql, [$entityId, $limit]);
    }

    /** Bounded read-only traversal of outgoing edges up to depth, cycle-safe. */
    public static function traversal($entityId, $depth = 2, $limit = 50)
    {
        self::getEntity($entityId);
        $depth = max(1, min(self::MAX_TRAVERSAL_DEPTH, (int)$depth));
        $limit = max(1, min(self::MAX_TRAVERSAL_LIMIT, (int)$limit));
        $sql = "WITH RECURSIVE walk AS (
            SELECT e.target_id AS id, 1 AS d, ARRAY[e.source_id, e.target_id] AS path
            FROM knowledge_edges e WHERE e.source_id = ?
            UNION ALL
            SELECT e.target_id, w.d + 1, w.path || e.target_id
            FROM walk w JOIN knowledge_edges e ON e.source_id = w.id
            WHERE w.d < $depth AND NOT e.target_id = ANY(w.path)
        ) SELECT DISTINCT id FROM walk ORDER BY id LIMIT $limit";
        $rows = Database::rows($sql, [$entityId]);
        $entities = [];
        foreach ($rows as $row) {
            $entities[] = self::getEntity((int)$row['id']);
        }
        return $entities;
    }

    private static function getEntity($id)
    {
        $row = Database::row('SELECT * FROM knowledge_entities WHERE id = ?', [$id]);
        if (!$row) throw new \RuntimeException('Entity not found');
        return $row;
    }
}

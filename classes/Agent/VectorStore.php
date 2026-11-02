<?php
namespace Agent;

/** Bounded vector collection lifecycle and cosine-similarity search over Postgres pg_vector. */
class VectorStore
{
    const MAX_DIMENSION = 4096;
    const MAX_TOPK = 100;

    public static function createCollection($name, $dimension, $model, $ownerId)
    {
        if (!is_string($name) || !trim($name)) throw new \InvalidArgumentException('Collection needs a name');
        if (!is_int($dimension) || $dimension <= 0 || $dimension > self::MAX_DIMENSION) {
            throw new \InvalidArgumentException('Dimension must be a positive integer within the allowed bound');
        }
        if (!is_string($model)) $model = (string)$model;
        if (!is_string($ownerId) || !trim($ownerId)) throw new \InvalidArgumentException('Collection needs an owner');
        return Database::row(
            'INSERT INTO vector_collections (name, owner_id, dimension, model) VALUES (?, ?, ?, ?) RETURNING *',
            [$name, $ownerId, $dimension, $model]
        );
    }

    public static function listCollections($ownerId)
    {
        if (!is_string($ownerId) || !trim($ownerId)) throw new \InvalidArgumentException('Listing needs an owner');
        return Database::rows(
            'SELECT id, name, dimension, model, created_at FROM vector_collections WHERE owner_id = ? ORDER BY id',
            [$ownerId]
        );
    }

    public static function getCollection($id)
    {
        $row = Database::row('SELECT * FROM vector_collections WHERE id = ?', [$id]);
        if (!$row) throw new \RuntimeException('Collection not found');
        return $row;
    }

    /** Validate a numeric vector is finite and matches the expected dimension. */
    public static function validateVector($vector, $dimension)
    {
        if (!is_array($vector)) throw new \InvalidArgumentException('Vector must be an array of finite numbers');
        if (count($vector) !== (int)$dimension) {
            throw new \InvalidArgumentException('Vector dimension does not match collection dimension');
        }
        foreach ($vector as $value) {
            if (!is_float($value) && !is_int($value)) {
                throw new \InvalidArgumentException('Vector elements must be numbers');
            }
            if (!is_finite((float)$value)) {
                throw new \InvalidArgumentException('Vector elements must be finite');
            }
        }
        return array_map('floatval', $vector);
    }

    public static function upsert($collectionId, $itemId, $vector, $content = '', $metadata = [])
    {
        $collection = self::getCollection($collectionId);
        $vector = self::validateVector($vector, (int)$collection['dimension']);
        $json = json_encode($vector);
        if ($json === false) throw new \InvalidArgumentException('Vector must be encodable as JSON');
        if (!is_array($metadata)) $metadata = [];
        return Database::row(
            'INSERT INTO vector_items (collection_id, item_id, vector, content, metadata)
             VALUES (?, ?, ?::vector, ?, ?)
             ON CONFLICT (collection_id, item_id) DO UPDATE
             SET vector = EXCLUDED.vector, content = EXCLUDED.content, metadata = EXCLUDED.metadata, updated_at = NOW()
             RETURNING *',
            [$collection['id'], $itemId, $json, $content, json_encode($metadata)]
        );
    }

    public static function delete($collectionId, $itemId)
    {
        self::getCollection($collectionId);
        $row = Database::row(
            'DELETE FROM vector_items WHERE collection_id = ? AND item_id = ? RETURNING *',
            [$collectionId, $itemId]
        );
        if (!$row) throw new \RuntimeException('Vector item not found');
        return $row;
    }

    public static function get($collectionId, $itemId)
    {
        self::getCollection($collectionId);
        $row = Database::row(
            'SELECT id, collection_id, item_id, content, metadata, created_at, updated_at FROM vector_items
             WHERE collection_id = ? AND item_id = ?',
            [$collectionId, $itemId]
        );
        if (!$row) throw new \RuntimeException('Vector item not found');
        $row['metadata'] = json_decode($row['metadata'], true) ?? [];
        return $row;
    }

    /** Bounded cosine-similarity top-k over a collection. Returns items ranked by similarity (highest first). */
    public static function topK($collectionId, $queryVector, $limit = 10)
    {
        $collection = self::getCollection($collectionId);
        $queryVector = self::validateVector($queryVector, (int)$collection['dimension']);
        $limit = max(1, min(self::MAX_TOPK, (int)$limit));
        $json = json_encode($queryVector);
        if ($json === false) throw new \InvalidArgumentException('Query vector must be encodable as JSON');
        $rows = Database::rows(
            'SELECT item_id, content, metadata, 1 - (vector <=> ?::vector) AS similarity
             FROM vector_items WHERE collection_id = ?
             ORDER BY vector <=> ?::vector
             LIMIT ?',
            [$json, $collection['id'], $json, $limit]
        );
        foreach ($rows as &$row) $row['metadata'] = json_decode($row['metadata'], true) ?? [];
        unset($row);
        return $rows;
    }

    /** Embed texts via the existing Models::embed transport and upsert the first vector. */
    public static function embedAndUpsert($collectionId, $itemId, $texts, $content = '', $metadata = [], $fallback = null)
    {
        if (!is_array($texts) || !$texts) throw new \InvalidArgumentException('Embedding needs at least one text');
        $result = Models::embed($texts, $fallback);
        if (empty($result['vectors'][0])) throw new \RuntimeException('Embedding returned no vector');
        return self::upsert($collectionId, $itemId, $result['vectors'][0], $content, $metadata);
    }
}

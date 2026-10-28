<?php
namespace Agent;

class Vectors
{
    public static function profile()
    {
        $q = Database::connection()->query("SELECT value FROM settings WHERE key = 'tasks.embedding'");
        $name = $q->fetchColumn();
        if ($name !== false) return Config::resolve($name);
        $row = Database::connection()->query("SELECT p.config FROM profiles p JOIN models m ON m.id = p.model_id JOIN endpoints e ON e.id = m.endpoint_id
            WHERE m.supports_embeddings = TRUE AND e.status = 'online' ORDER BY p.id LIMIT 1")->fetch();
        if (!$row) throw new \RuntimeException('Configure an embedding profile to enable vector retrieval; SQL search remains available');
        return Config::resolve($row);
    }

    public static function embed(array $texts)
    {
        $config = self::profile();
        $url = preg_replace('~/v1/(chat/completions|responses)$~', '/v1/embeddings', $config['url']);
        $response = Http::request($url, ['model' => $config['model'], 'input' => $texts], isset($config['headers']) ? $config['headers'] : [], 90);
        if ($response['status'] !== 200 || !isset($response['json']['data']) || count($response['json']['data']) !== count($texts)) throw new \RuntimeException('Embedding service unavailable or returned an invalid batch. Configure a working embedding profile.');
        $vectors = [];
        foreach ($response['json']['data'] as $index => $row) {
            if (empty($row['embedding']) || !is_array($row['embedding'])) throw new \RuntimeException('Invalid embedding vector');
            foreach ($row['embedding'] as $value) if (!is_numeric($value) || !is_finite((float)$value)) throw new \RuntimeException('Invalid embedding coordinate');
            $vectors[isset($row['index']) ? $row['index'] : $index] = $row['embedding'];
        }
        ksort($vectors);
        return ['model' => sha1($url . '/' . $config['model']), 'vectors' => array_values($vectors)];
    }

    public static function search($query, $threadId = null, $userId = null, $limit = 5)
    {
        $result = self::embed([$query]);
        $vector = $result['vectors'][0];
        list($visible, $params) = Access::predicate($userId);
        if ($threadId !== null) { $visible .= ' AND m.thread_id = ?'; $params[] = $threadId; }
        $limit = max(1, min(25, (int)$limit));
        $sql = "SELECT m.id, m.thread_id, m.role, m.search_text AS text, t.title, MIN(v.embedding <=> ?::vector) AS distance
            FROM message_vectors v JOIN messages m ON m.id = v.message_id JOIN threads t ON t.id = m.thread_id
            WHERE v.embedding_model = ? AND vector_dims(v.embedding) = ? AND $visible
            GROUP BY m.id, t.title ORDER BY distance LIMIT $limit";
        $q = Database::connection()->prepare($sql);
        $q->execute(array_merge([json_encode($vector), $result['model'], count($vector)], $params));
        return $q->fetchAll();
    }
}

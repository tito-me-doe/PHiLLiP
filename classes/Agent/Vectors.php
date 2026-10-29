<?php
namespace Agent;

class Vectors
{
    public static function profile()
    {
        return Profiles::defaultFor('embed');
    }

    public static function embed(array $texts)
    {
        return Models::embed($texts);
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

<?php
namespace Agent;

class History
{
    public static function search($query, $threadId = null, $userId = null, $limit = 5)
    {
        list($visible, $params) = Access::predicate($userId);
        $where = $visible;
        if ($threadId !== null) { $where .= ' AND m.thread_id = ?'; $params[] = $threadId; }
        $limit = max(1, min(25, (int)$limit));
        $query = mb_substr(trim($query), 0, 2000);
        $sql = "SELECT m.id, m.thread_id, m.role, m.search_text AS text, t.title,
            ts_rank(to_tsvector('simple', m.search_text), plainto_tsquery('simple', ?)) AS rank
            FROM messages m JOIN threads t ON t.id = m.thread_id WHERE $where
            AND (to_tsvector('simple', m.search_text) @@ plainto_tsquery('simple', ?) OR m.search_text ILIKE ?)
            ORDER BY rank DESC, m.id DESC LIMIT $limit";
        $q = Database::connection()->prepare($sql);
        $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
        $q->execute(array_merge([$query], $params, [$query, $pattern]));
        $rows = $q->fetchAll();
        $scores = [];
        $merged = [];
        foreach ($rows as $index => $row) { $merged[$row['id']] = $row; $scores[$row['id']] = 1 / (60 + $index + 1); }
        try {
            foreach (Vectors::search($query, $threadId, $userId, $limit) as $index => $row) {
                if (!isset($merged[$row['id']])) { $merged[$row['id']] = $row; $scores[$row['id']] = 0; }
                $scores[$row['id']] += 1 / (60 + $index + 1);
            }
        } catch (\Exception $e) { Events::emit('retrieval.fallback', ['message' => $e->getMessage(), 'mode' => 'SQL']); }
        uasort($merged, function ($a, $b) use ($scores) { return $scores[$b['id']] <=> $scores[$a['id']]; });
        $rows = array_slice(array_values($merged), 0, $limit);
        foreach ($rows as &$row) $row['text'] = mb_substr($row['text'], 0, 4000);
        return $rows;
    }

    public static function read($messageId, $threadId = null, $userId = null)
    {
        $q = Database::connection()->prepare('SELECT * FROM messages WHERE id = ?');
        $q->execute([$messageId]);
        $row = $q->fetch();
        if (!$row || ($threadId !== null && $row['thread_id'] != $threadId)) throw new \RuntimeException('Message is not in the current thread');
        if ($userId !== null) Access::read($userId, new Thread($row['thread_id']));
        $row['raw'] = json_decode($row['raw'], true);
        return $row;
    }
}

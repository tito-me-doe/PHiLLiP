<?php
namespace Agent;

class Knowledge
{
    public static function remember($threadId, $ownerId, $content, $subject, array $sourceIds, $relation = '', $object = '', $kind = 'fact')
    {
        if (!trim($content) || !$sourceIds) throw new \InvalidArgumentException('Memory requires content and at least one source message');
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $sourceIds = array_unique($sourceIds);
            $q = $db->prepare('SELECT 1 FROM messages WHERE id = ? AND thread_id = ?');
            foreach ($sourceIds as $id) {
                $q->execute([$id, $threadId]);
                $found = $q->fetchColumn(); $q->closeCursor();
                if (!$found) throw new \RuntimeException('Memory source is not in its source thread');
            }
            $q = $db->prepare('SELECT id FROM claims WHERE thread_id = ? AND owner_id = ? AND content = ? AND subject = ?');
            $q->execute([$threadId, $ownerId, $content, $subject]);
            $existing = $q->fetchColumn();
            if ($existing) {
                $source = $db->prepare('INSERT INTO claim_sources VALUES (?, ?) ON CONFLICT DO NOTHING');
                foreach ($sourceIds as $id) { $source->execute([$existing, $id]); $source->closeCursor(); }
                $db->commit();
                return $existing;
            }
            $q = $db->prepare('INSERT INTO claims (thread_id, owner_id, subject, content, kind) VALUES (?, ?, ?, ?, ?) RETURNING id');
            $q->execute([$threadId, $ownerId, $subject, $content, $kind]);
            $claimId = $q->fetchColumn();
            $source = $db->prepare('INSERT INTO claim_sources VALUES (?, ?)');
            foreach ($sourceIds as $id) { $source->execute([$claimId, $id]); $source->closeCursor(); }
            if ($subject !== '' && $object !== '' && $relation !== '') {
                $nodes = [];
                $q = $db->prepare('INSERT INTO graph_nodes (label, key) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET label = graph_nodes.label RETURNING id');
                foreach ([$subject, $object] as $label) {
                    $q->execute([$label, mb_strtolower(trim($label))]); $nodes[] = $q->fetchColumn(); $q->closeCursor();
                }
                $db->prepare('INSERT INTO graph_edges (claim_id, source_id, target_id, relation) VALUES (?, ?, ?, ?)')->execute([$claimId, $nodes[0], $nodes[1], $relation]);
            }
            $db->commit();
            return $claimId;
        } catch (\Throwable $e) { $db->rollBack(); throw $e; }
    }

    public static function visibility($userId = null, $threadId = null)
    {
        list($threadVisible, $params) = Access::predicate($userId);
        if ($userId instanceof MemoryAudience) {
            $visible = "(c.visibility = 'organization' OR (c.visibility = 'thread' AND $threadVisible)";
            if ($userId->threadId !== null) { $visible .= " OR (c.thread_id = ? AND c.visibility IN ('thread', 'private'))"; $params[] = $userId->threadId; }
            $visible .= ')';
        } elseif ($userId === null) $visible = 'TRUE';
        else {
            $teams = array_column(Identity::teams($userId), 'id');
            $visible = "((c.visibility = 'thread' AND $threadVisible) OR c.visibility = 'organization' OR (c.visibility = 'private' AND c.owner_id = ?)";
            $params[] = (string)$userId;
            if ($teams) { $visible .= " OR (c.visibility = 'team' AND c.team_id IN (" . implode(',', array_fill(0, count($teams), '?')) . '))'; $params = array_merge($params, $teams); }
            $visible .= ')';
        }
        if ($threadId !== null) { $visible .= ' AND c.thread_id = ?'; $params[] = $threadId; }
        return [$visible, $params];
    }

    public static function recall($query, $userId = null, $threadId = null, $limit = 5)
    {
        list($visible, $params) = self::visibility($userId, $threadId);
        $sql = "SELECT c.*, ARRAY(SELECT message_id FROM claim_sources WHERE claim_id = c.id) AS sources FROM claims c JOIN threads t ON t.id = c.thread_id
            WHERE $visible AND (c.content ILIKE ? OR c.subject ILIKE ?) ORDER BY c.id DESC LIMIT " . max(1, min(50, (int)$limit));
        $q = Database::connection()->prepare($sql);
        $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($query)) . '%';
        $q->execute(array_merge($params, [$pattern, $pattern]));
        $rows = $q->fetchAll();
        foreach ($rows as &$row) $row['sources'] = trim($row['sources'], '{}') === '' ? [] : array_map('intval', explode(',', trim($row['sources'], '{}')));
        return $rows;
    }

    public static function get($id, $userId = null)
    {
        list($visible, $params) = self::visibility($userId);
        $q = Database::connection()->prepare("SELECT c.* FROM claims c JOIN threads t ON t.id = c.thread_id WHERE c.id = ? AND $visible");
        $q->execute(array_merge([$id], $params));
        $row = $q->fetch();
        if (!$row) throw new \RuntimeException('Memory not found or access denied');
        return $row;
    }

    public static function graph($query, $userId = null, $threadId = null, $depth = 2)
    {
        list($visible, $params) = self::visibility($userId, $threadId);
        $depth = max(1, min(4, (int)$depth));
        $sql = "WITH RECURSIVE eligible AS MATERIALIZED (
            SELECT e.*, a.label AS subject, b.label AS object FROM graph_edges e JOIN claims c ON c.id = e.claim_id
            JOIN threads t ON t.id = c.thread_id JOIN graph_nodes a ON a.id = e.source_id JOIN graph_nodes b ON b.id = e.target_id WHERE $visible
        ), walk AS (
            SELECT e.*, 1 AS depth, ARRAY[e.source_id, e.target_id] AS path FROM eligible e WHERE e.subject ILIKE ? OR e.object ILIKE ?
            UNION ALL SELECT e.*, w.depth + 1, w.path || e.target_id FROM walk w JOIN eligible e ON e.source_id = w.target_id
            WHERE w.depth < $depth AND NOT e.target_id = ANY(w.path)
        ) SELECT DISTINCT claim_id, subject, relation, object FROM walk LIMIT 50";
        $q = Database::connection()->prepare($sql);
        $q->execute(array_merge($params, ['%' . $query . '%', '%' . $query . '%']));
        return $q->fetchAll();
    }
}

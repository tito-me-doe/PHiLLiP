<?php
namespace Agent;

class Registry
{
    const CAPABILITIES = ['supports_chat' => 'Chat', 'supports_tools' => 'Tools', 'supports_vision' => 'Vision',
        'supports_structured_output' => 'Structured output', 'supports_streaming' => 'Streaming',
        'supports_embeddings' => 'Embeddings', 'supports_rerank' => 'Reranking', 'supports_reasoning' => 'Reasoning', 'supports_pdf' => 'Native PDF'];
    private $db;

    public function __construct($db = null) { $this->db = $db ?: Database::connection(); }

    public function record(array $endpoint, array $models)
    {
        $sql = "INSERT INTO endpoints (url, engine, status, metadata, last_seen_at) VALUES (?, ?, ?, ?::jsonb, NOW())
                ON CONFLICT (url) DO UPDATE SET engine = EXCLUDED.engine, status = EXCLUDED.status,
                metadata = EXCLUDED.metadata, last_checked_at = NOW(), last_seen_at = NOW() RETURNING id";
        $q = $this->db->prepare($sql);
        $q->execute([$endpoint['url'], $endpoint['engine'], $endpoint['status'], json_encode($endpoint['metadata'])]);
        $id = $q->fetchColumn();
        $this->db->prepare("UPDATE models SET status = 'unavailable' WHERE endpoint_id = ?")->execute([$id]);
        if (!$models) return $id;
        $columns = array_keys(self::CAPABILITIES);
        $updates = [];
        foreach ($columns as $column) $updates[] = $column . ' = EXCLUDED.' . $column;
        $modelQuery = $this->db->prepare('INSERT INTO models (endpoint_id, model, status, metadata, ' . implode(', ', $columns) . ') VALUES (?, ?, ?, ?::jsonb, ' . implode(', ', array_fill(0, count($columns), '?')) . ')
            ON CONFLICT (endpoint_id, model) DO UPDATE SET status = EXCLUDED.status, metadata = EXCLUDED.metadata, ' . implode(', ', $updates) . ' RETURNING id');
        $profileQuery = $this->db->prepare('INSERT INTO profiles (name, model_id, config, ' . implode(', ', $columns) . ') VALUES (?, ?, ?::jsonb, ' . implode(', ', array_fill(0, count($columns), '?')) . ') ON CONFLICT (name) DO NOTHING');
        foreach ($models as $model) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = !isset($model[$column]) ? null : ($model[$column] ? 'true' : 'false');
            }
            $modelQuery->execute(array_merge([$id, $model['model'], $model['status'], json_encode($model['metadata'])], $values));
            $modelId = $modelQuery->fetchColumn(); $modelQuery->closeCursor();
            $name = self::profileName($endpoint['url'], $model['model']);
            $config = ['url' => rtrim($endpoint['url'], '/') . '/v1/chat/completions', 'model' => $model['model'], 'stream' => false];
            $profileQuery->execute(array_merge([$name, $modelId, json_encode($config)], $values)); $profileQuery->closeCursor();
        }
        return $id;
    }

    public static function capabilities(array $row) { return array_intersect_key($row, self::CAPABILITIES); }

    public static function profileName($url, $model)
    {
        return substr(trim(preg_replace('/[^a-zA-Z0-9_-]+/', '-', parse_url($url, PHP_URL_HOST) . '-' . $model), '-'), 0, 120)
            . '-' . substr(sha1($url . '/' . $model), 0, 8);
    }

    public function unavailable($url, $status = 'offline')
    {
        $this->db->prepare('UPDATE endpoints SET status = ?, last_checked_at = NOW() WHERE url = ?')->execute([$status, $url]);
        $this->db->prepare("UPDATE models SET status = ? WHERE endpoint_id IN (SELECT id FROM endpoints WHERE url = ?)")->execute([$status, $url]);
    }

    public function endpoints() { return $this->db->query('SELECT * FROM endpoints ORDER BY url')->fetchAll(); }

    public function models()
    {
        return $this->db->query('SELECT m.*, e.url, e.engine, e.status AS endpoint_status FROM models m JOIN endpoints e ON e.id = m.endpoint_id ORDER BY e.url, m.model')->fetchAll();
    }

    public function profiles($enabledOnly = false)
    {
        return $this->db->query('SELECT * FROM profiles' . ($enabledOnly ? ' WHERE enabled = TRUE' : '') . ' ORDER BY name')->fetchAll();
    }

    /** Look up a registry row by primary key, unique name, or an existing row/reference. */
    public function profile($profile)
    {
        $byId = is_int($profile);
        $byName = false;
        if ($profile instanceof \stdClass) $profile = get_object_vars($profile);
        if (is_array($profile)) {
            if (isset($profile['id'])) { $profile = $profile['id']; $byId = true; }
            elseif (isset($profile['name'])) { $profile = $profile['name']; $byName = true; }
            else throw new \InvalidArgumentException('Profile reference requires an ID or name');
        }
        if (!is_int($profile) && !is_string($profile)) throw new \InvalidArgumentException('Use a profile ID, name, or row');
        if ($byId && is_string($profile)) $profile = trim($profile);
        if ($byId && !ctype_digit((string)$profile)) throw new \InvalidArgumentException('Profile ID must be a nonnegative integer');
        // Integer IDs are explicit. Numeric strings still prefer an existing unique name.
        if (!$byId && is_string($profile)) {
            $q = $this->db->prepare('SELECT * FROM profiles WHERE name = ?');
            $q->execute([$profile]);
            $row = $q->fetch();
            if (!$row && trim($profile) !== $profile) {
                $profile = trim($profile);
                $q->execute([$profile]);
                $row = $q->fetch();
            }
            if ($row || $byName || !ctype_digit($profile)) return $row;
        }
        $q = $this->db->prepare('SELECT * FROM profiles WHERE id = ?');
        $q->execute([$profile]);
        return $q->fetch();
    }
}

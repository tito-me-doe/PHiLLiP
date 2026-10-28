<?php
namespace Agent;

/** Rebuildable durable projection of event activity; run lifecycle stays in runs. */
class WorkflowState
{
    public static function apply($runId, array $event, $eventId)
    {
        $type = $event['type']; $data = $event['data']; $patch = ['last_event_id' => (int)$eventId];
        if ($type === 'run.started') $patch += ['phase' => 'thinking', 'progress' => 'Thinking…'];
        elseif ($type === 'tool.start') $patch += ['phase' => 'tool', 'progress' => 'Using ' . $data['name'], 'tool' => $data];
        elseif ($type === 'tool.progress') $patch += ['progress' => isset($data['message']) ? $data['message'] : 'Working…'];
        elseif ($type === 'attachment.progress' || $type === 'attachment.fallback') $patch += ['phase' => 'preparing', 'progress' => $data['message']];
        elseif ($type === 'tool.result') $patch += ['phase' => 'thinking', 'progress' => 'Tool finished; thinking…', 'tool' => null];
        elseif ($type === 'message') $patch += ['content' => '', 'reasoning' => '', 'phase' => 'thinking', 'progress' => 'Thinking…'];
        elseif ($type === 'run.stop_requested') $patch += ['progress' => 'Stop requested'];
        elseif (in_array($type, ['run.completed', 'run.stopped', 'run.failed'], true)) $patch += ['phase' => Run::get($runId)['status'], 'content' => '', 'reasoning' => '', 'progress' => '', 'tool' => null];
        if ($type === 'text.delta' || $type === 'reasoning.delta') {
            $key = $type === 'text.delta' ? 'content' : 'reasoning';
            $patch += ['phase' => $key === 'content' ? 'writing' : 'thinking', 'progress' => $key === 'content' ? 'Writing…' : 'Thinking…'];
            $changed = Database::execute("UPDATE states SET value = (value || ?::jsonb) || jsonb_build_object(?::text, COALESCE(value->>?::text, '') || ?::text), revision = revision + 1, updated_at = NOW() WHERE scope = 'run' AND run_id = ? AND COALESCE((value->>'last_event_id')::bigint, 0) < ?",
                [State::encode($patch), $key, $key, $data['content'], $runId, $eventId]);
        } else {
            $changed = Database::execute("UPDATE states SET value = value || ?::jsonb, revision = revision + 1, updated_at = NOW() WHERE scope = 'run' AND run_id = ? AND COALESCE((value->>'last_event_id')::bigint, 0) < ?",
                [State::encode($patch), $runId, $eventId]);
        }
        if (!$changed) {
            $q = Database::connection()->prepare("SELECT 1 FROM states WHERE scope = 'run' AND run_id = ?"); $q->execute([$runId]);
            // Missing projections are restored from durable events, without rerunning tools.
            if (!$q->fetchColumn()) self::rebuild($runId);
        }
    }

    public static function snapshot($threadId)
    {
        return State::transaction(function () use ($threadId) {
            $q = Database::connection()->prepare("SELECT r.id, r.status, r.stop_level, r.error, r.settings->'config'->>'assistant_name' AS assistant_name, s.revision, s.value AS state FROM runs r LEFT JOIN states s ON s.scope = 'run' AND s.run_id = r.id WHERE r.thread_id = ? ORDER BY r.id");
            $q->execute([$threadId]); $runs = $q->fetchAll(); $active = null; $queued = [];
            foreach ($runs as &$run) {
                $run['state'] = json_decode($run['state'] ?: '{}', true);
                if ($run['status'] === 'running') $active = $run['id'];
                elseif ($run['status'] === 'queued') $queued[] = $run['id'];
            }
            unset($run);
            $q = Database::connection()->prepare('SELECT COALESCE(MAX(e.id), 0) FROM events e JOIN runs r ON r.id = e.run_id WHERE r.thread_id = ?'); $q->execute([$threadId]);
            return ['runs' => $runs, 'active_run_id' => $active, 'queued_run_ids' => $queued, 'event_cursor' => (int)$q->fetchColumn()];
        }, true);
    }

    public static function feed($threadId, $after, $viewerId = null)
    {
        return State::transaction(function () use ($threadId, $after, $viewerId) {
            $q = Database::connection()->prepare('SELECT e.* FROM events e JOIN runs r ON r.id = e.run_id WHERE r.thread_id = ? AND e.id > ? ORDER BY e.id LIMIT 300');
            $q->execute([$threadId, max(0, (int)$after)]); $events = $q->fetchAll();
            foreach ($events as &$event) {
                $event['data'] = json_decode($event['data'], true);
                if ($viewerId !== null && $event['type'] === 'log.message') {
                    $record = Log::delivery($event['data'], $viewerId);
                    // Preserve cursor progression without transmitting hidden diagnostic contents.
                    $event['data'] = $record ?: [];
                    if (!$record) $event['type'] = 'log.suppressed';
                }
            }
            unset($event);
            return ['events' => $events, 'state' => self::snapshot($threadId)];
        }, true);
    }

    public static function rebuild($runId)
    {
        return State::transaction(function () use ($runId) {
            $run = Run::get($runId);
            Database::connection()->prepare('SELECT id FROM threads WHERE id = ? FOR UPDATE')->execute([$run['thread_id']]);
            $state = State::run($runId);
            $value = $state->read()['value'];
            $state->replace(['phase' => $run['status'], 'content' => '', 'reasoning' => '', 'progress' => '', 'last_event_id' => 0]
                + array_intersect_key($value, ['pending_notes' => true]));
            $q = Database::connection()->prepare('SELECT * FROM events WHERE run_id = ? ORDER BY id'); $q->execute([$runId]);
            foreach ($q->fetchAll() as $event) self::apply($runId, ['type' => $event['type'], 'data' => json_decode($event['data'], true)], $event['id']);
            return $state->read();
        });
    }
}

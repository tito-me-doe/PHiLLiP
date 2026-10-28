CREATE TABLE states (
    id BIGSERIAL PRIMARY KEY,
    scope TEXT NOT NULL,
    resource_id TEXT NOT NULL,
    parent_id BIGINT REFERENCES states(id) ON DELETE CASCADE,
    thread_id BIGINT REFERENCES threads(id) ON DELETE CASCADE,
    run_id BIGINT REFERENCES runs(id) ON DELETE CASCADE,
    user_id TEXT,
    value JSONB NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(value) = 'object'),
    revision BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (scope, resource_id)
);
CREATE INDEX states_parent ON states(parent_id);
CREATE INDEX states_thread ON states(thread_id);
CREATE INDEX states_run ON states(run_id);
CREATE INDEX states_user ON states(user_id);

INSERT INTO states (scope, resource_id, thread_id) SELECT 'thread', id::text, id FROM threads;
INSERT INTO states (scope, resource_id, parent_id, thread_id, run_id, value)
    SELECT 'run', r.id::text, s.id, r.thread_id, r.id,
        jsonb_build_object('phase', r.status, 'content', '', 'reasoning', '', 'progress', '', 'last_event_id', 0)
    FROM runs r JOIN states s ON s.scope = 'thread' AND s.thread_id = r.thread_id;

-- Preserve existing avatar preferences; identity IDs may come from an external ERP.
INSERT INTO states (scope, resource_id, user_id, value)
    SELECT 'ui.user', substr(key, 13), substr(key, 13),
        jsonb_build_object('view', 'chat', 'thread_id', NULL, 'chat_tab', 'private', 'settings_tab', 'appearance', 'avatar', COALESCE(value->>'avatar', 'terminal'))
    FROM settings WHERE key LIKE 'preferences.%';

CREATE FUNCTION phillip_prune_panel_state() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    UPDATE states SET value = jsonb_set(value, '{panels}', (value->'panels') - ('reason-' || OLD.id) - ('tool-' || OLD.id)),
        revision = revision + 1, updated_at = NOW()
    WHERE scope = 'ui.thread' AND thread_id = OLD.thread_id AND jsonb_typeof(value->'panels') = 'object'
        AND ((value->'panels') ? ('reason-' || OLD.id) OR (value->'panels') ? ('tool-' || OLD.id));
    RETURN OLD;
END $$;
CREATE TRIGGER prune_panel_state AFTER DELETE ON messages FOR EACH ROW EXECUTE FUNCTION phillip_prune_panel_state();

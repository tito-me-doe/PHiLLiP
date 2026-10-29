ALTER TABLE threads ADD COLUMN updated_at TIMESTAMPTZ;
UPDATE threads SET updated_at=created_at;
ALTER TABLE threads ALTER COLUMN updated_at SET DEFAULT NOW();
ALTER TABLE threads ALTER COLUMN updated_at SET NOT NULL;
CREATE FUNCTION touch_thread_activity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN NEW.updated_at=NOW(); RETURN NEW; END;
$$;
CREATE TRIGGER threads_activity BEFORE UPDATE ON threads FOR EACH ROW EXECUTE FUNCTION touch_thread_activity();
CREATE INDEX messages_activity ON messages(thread_id,created_at DESC);

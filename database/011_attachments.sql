CREATE TABLE attachments (
    id TEXT PRIMARY KEY,
    owner_id TEXT NOT NULL,
    name TEXT NOT NULL,
    mime TEXT NOT NULL,
    size BIGINT NOT NULL CHECK (size >= 0),
    sha256 TEXT NOT NULL,
    data BYTEA NOT NULL,
    text_content TEXT,
    upload_key TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX one_attachment_per_upload ON attachments(owner_id, upload_key) WHERE upload_key IS NOT NULL;
CREATE TABLE attachment_links (
    attachment_id TEXT NOT NULL REFERENCES attachments(id) ON DELETE CASCADE,
    message_id BIGINT NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
    PRIMARY KEY (attachment_id, message_id)
);
CREATE INDEX attachment_links_message ON attachment_links(message_id);

-- Deferred cleanup lets an edited message retain its files while its old run is replaced.
CREATE FUNCTION phillip_collect_attachment() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    DELETE FROM attachments a WHERE a.id = OLD.attachment_id
        AND NOT EXISTS (SELECT 1 FROM attachment_links l WHERE l.attachment_id = a.id);
    RETURN OLD;
END $$;
CREATE CONSTRAINT TRIGGER collect_attachment AFTER DELETE ON attachment_links
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION phillip_collect_attachment();

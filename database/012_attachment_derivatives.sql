CREATE TABLE attachment_derivatives (
    attachment_id TEXT NOT NULL REFERENCES attachments(id) ON DELETE CASCADE,
    parser_key TEXT NOT NULL,
    value JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (attachment_id, parser_key)
);
CREATE OR REPLACE FUNCTION phillip_collect_attachment() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM 1 FROM attachments WHERE id = OLD.attachment_id FOR UPDATE;
    DELETE FROM attachments a WHERE a.id = OLD.attachment_id
        AND NOT EXISTS (SELECT 1 FROM attachment_links l WHERE l.attachment_id = a.id);
    RETURN OLD;
END $$;

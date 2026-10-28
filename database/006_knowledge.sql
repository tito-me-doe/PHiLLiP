ALTER TABLE messages ADD COLUMN IF NOT EXISTS search_text TEXT NOT NULL DEFAULT '';
UPDATE messages SET search_text = COALESCE(raw->>'content', '') WHERE search_text = '';
CREATE INDEX IF NOT EXISTS messages_search ON messages USING GIN (to_tsvector('simple', search_text));
CREATE TABLE IF NOT EXISTS message_vectors (
    id BIGSERIAL PRIMARY KEY,
    message_id BIGINT NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
    chunk INTEGER NOT NULL,
    embedding_model TEXT NOT NULL,
    embedding vector NOT NULL,
    UNIQUE(message_id, chunk, embedding_model)
);
CREATE TABLE IF NOT EXISTS claims (
    id BIGSERIAL PRIMARY KEY,
    thread_id BIGINT NOT NULL REFERENCES threads(id) ON DELETE CASCADE,
    owner_id TEXT NOT NULL,
    subject TEXT NOT NULL DEFAULT '',
    content TEXT NOT NULL,
    kind TEXT NOT NULL DEFAULT 'fact',
    visibility TEXT NOT NULL DEFAULT 'thread' CHECK (visibility IN ('thread', 'private', 'team', 'organization')),
    team_id TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS claim_sources (
    claim_id BIGINT NOT NULL REFERENCES claims(id) ON DELETE CASCADE,
    message_id BIGINT NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
    PRIMARY KEY(claim_id, message_id)
);
CREATE TABLE IF NOT EXISTS graph_nodes (
    id BIGSERIAL PRIMARY KEY,
    label TEXT NOT NULL,
    key TEXT NOT NULL UNIQUE
);
CREATE TABLE IF NOT EXISTS graph_edges (
    id BIGSERIAL PRIMARY KEY,
    claim_id BIGINT NOT NULL REFERENCES claims(id) ON DELETE CASCADE,
    source_id BIGINT NOT NULL REFERENCES graph_nodes(id),
    target_id BIGINT NOT NULL REFERENCES graph_nodes(id),
    relation TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS index_jobs (
    run_id BIGINT PRIMARY KEY REFERENCES runs(id) ON DELETE CASCADE,
    status TEXT NOT NULL DEFAULT 'queued',
    error TEXT,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE OR REPLACE FUNCTION remove_unsupported_claim() RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    DELETE FROM claims WHERE id = OLD.claim_id AND NOT EXISTS (SELECT 1 FROM claim_sources WHERE claim_id = OLD.claim_id);
    DELETE FROM graph_nodes n WHERE NOT EXISTS (SELECT 1 FROM graph_edges e WHERE e.source_id = n.id OR e.target_id = n.id);
    RETURN OLD;
END;
$$;
CREATE TRIGGER claim_source_removed AFTER DELETE ON claim_sources FOR EACH ROW EXECUTE FUNCTION remove_unsupported_claim();

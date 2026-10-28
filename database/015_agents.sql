CREATE TABLE IF NOT EXISTS agents (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    team TEXT NOT NULL DEFAULT 'default',
    owner_id TEXT NOT NULL,
    unix_uid BIGINT NOT NULL,
    hostname TEXT NOT NULL,
    thread_id BIGINT NOT NULL UNIQUE REFERENCES threads(id) ON DELETE CASCADE,
    workspace TEXT NOT NULL,
    parent_id BIGINT REFERENCES agents(id) ON DELETE CASCADE,
    parent_thread_id BIGINT REFERENCES threads(id) ON DELETE CASCADE,
    depth SMALLINT NOT NULL DEFAULT 0 CHECK (depth BETWEEN 0 AND 2),
    paused BOOLEAN NOT NULL DEFAULT FALSE,
    worker_pid INTEGER,
    worker_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (hostname, unix_uid, owner_id, team, name)
);
CREATE INDEX IF NOT EXISTS agents_owner ON agents(hostname, unix_uid, owner_id, team);

CREATE EXTENSION IF NOT EXISTS vector;
CREATE TABLE IF NOT EXISTS endpoints (
    id BIGSERIAL PRIMARY KEY,
    url TEXT NOT NULL UNIQUE,
    engine TEXT NOT NULL DEFAULT 'openai-compatible',
    status TEXT NOT NULL DEFAULT 'unknown',
    metadata JSONB NOT NULL DEFAULT '{}',
    last_checked_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_seen_at TIMESTAMPTZ
);
CREATE TABLE IF NOT EXISTS models (
    id BIGSERIAL PRIMARY KEY,
    endpoint_id BIGINT NOT NULL REFERENCES endpoints(id) ON DELETE CASCADE,
    model TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'available',
    capabilities JSONB NOT NULL DEFAULT '{}',
    metadata JSONB NOT NULL DEFAULT '{}',
    UNIQUE(endpoint_id, model)
);
CREATE TABLE IF NOT EXISTS profiles (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    model_id BIGINT REFERENCES models(id) ON DELETE SET NULL,
    config JSONB NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    capabilities JSONB NOT NULL DEFAULT '{}',
    overrides JSONB NOT NULL DEFAULT '{}',
    test_results JSONB NOT NULL DEFAULT '{}',
    tested_at TIMESTAMPTZ,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS vector_collections (
    id BIGSERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    owner_id TEXT NOT NULL,
    dimension INTEGER NOT NULL CHECK (dimension > 0),
    model TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (name, owner_id)
);

CREATE TABLE IF NOT EXISTS vector_items (
    id BIGSERIAL PRIMARY KEY,
    collection_id BIGINT NOT NULL REFERENCES vector_collections(id) ON DELETE CASCADE,
    item_id TEXT NOT NULL,
    vector vector NOT NULL,
    content TEXT NOT NULL DEFAULT '',
    metadata JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (collection_id, item_id)
);

CREATE INDEX vector_items_collection ON vector_items(collection_id, id DESC);

CREATE TABLE IF NOT EXISTS knowledge_entities (
    id BIGSERIAL PRIMARY KEY,
    namespace TEXT NOT NULL,
    name TEXT NOT NULL,
    properties JSONB NOT NULL DEFAULT '{}',
    vector vector,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (namespace, name)
);

CREATE TABLE IF NOT EXISTS knowledge_entity_aliases (
    id BIGSERIAL PRIMARY KEY,
    entity_id BIGINT NOT NULL REFERENCES knowledge_entities(id) ON DELETE CASCADE,
    alias TEXT NOT NULL,
    UNIQUE (alias)
);

CREATE TABLE IF NOT EXISTS knowledge_edges (
    id BIGSERIAL PRIMARY KEY,
    source_id BIGINT NOT NULL REFERENCES knowledge_entities(id) ON DELETE CASCADE,
    target_id BIGINT NOT NULL REFERENCES knowledge_entities(id) ON DELETE CASCADE,
    relation TEXT NOT NULL,
    properties JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (source_id, target_id, relation)
);

CREATE INDEX knowledge_edges_source ON knowledge_edges(source_id, id DESC);
CREATE INDEX knowledge_edges_target ON knowledge_edges(target_id, id DESC);

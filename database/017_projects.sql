CREATE TABLE IF NOT EXISTS projects (
    id BIGSERIAL PRIMARY KEY,
    thread_id BIGINT NOT NULL UNIQUE REFERENCES threads(id) ON DELETE CASCADE,
    status TEXT NOT NULL DEFAULT 'planning' CHECK (status IN ('planning', 'running', 'paused', 'blocked', 'completed')),
    revision BIGINT NOT NULL DEFAULT 1,
    preflight_status TEXT NOT NULL DEFAULT 'unchecked' CHECK (preflight_status IN ('unchecked', 'queued', 'checking', 'ready', 'gaps', 'error')),
    preflight_revision BIGINT,
    preflight_result JSONB NOT NULL DEFAULT '{}'::jsonb,
    preflight_started_at TIMESTAMPTZ,
    started_by TEXT,
    started_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS project_todos (
    id BIGSERIAL PRIMARY KEY,
    project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    parent_id BIGINT,
    title TEXT NOT NULL DEFAULT '',
    goal TEXT NOT NULL DEFAULT '',
    requirements TEXT NOT NULL DEFAULT '',
    context TEXT NOT NULL DEFAULT '',
    gates JSONB NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(gates) = 'array'),
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'working', 'review', 'done', 'blocked')),
    result TEXT NOT NULL DEFAULT '',
    evidence JSONB NOT NULL DEFAULT '{}'::jsonb,
    review JSONB NOT NULL DEFAULT '{}'::jsonb,
    persona_id BIGINT REFERENCES personas(id) ON DELETE SET NULL,
    thread_id BIGINT UNIQUE REFERENCES threads(id) ON DELETE SET NULL,
    run_id BIGINT REFERENCES runs(id) ON DELETE SET NULL,
    attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    max_attempts INTEGER NOT NULL DEFAULT 3 CHECK (max_attempts BETWEEN 1 AND 10),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (project_id, id),
    FOREIGN KEY (project_id, parent_id) REFERENCES project_todos(project_id, id) ON DELETE CASCADE,
    CHECK (parent_id IS NULL OR parent_id <> id)
);
CREATE INDEX IF NOT EXISTS project_todos_project ON project_todos(project_id, parent_id, id);
CREATE INDEX IF NOT EXISTS projects_work ON projects(status, preflight_status);
CREATE TABLE IF NOT EXISTS project_events (
    id BIGSERIAL PRIMARY KEY,
    project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    todo_id BIGINT REFERENCES project_todos(id) ON DELETE SET NULL,
    type TEXT NOT NULL,
    data JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS project_events_project ON project_events(project_id, id);

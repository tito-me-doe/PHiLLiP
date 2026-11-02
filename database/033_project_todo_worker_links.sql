CREATE TABLE project_todo_worker_links (
    id BIGSERIAL PRIMARY KEY,
    project_id BIGINT NOT NULL,
    todo_id BIGINT NOT NULL,
    todo_revision BIGINT NOT NULL,
    agent_id BIGINT NOT NULL,
    agent_name TEXT NOT NULL,
    run_id BIGINT NOT NULL,
    actor TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (todo_id, todo_revision, run_id),
    FOREIGN KEY (project_id, todo_id) REFERENCES project_todos(project_id, id) ON DELETE CASCADE
);
CREATE INDEX project_todo_worker_links_todo ON project_todo_worker_links(project_id, todo_id, id DESC);
CREATE TABLE project_todo_worker_verdicts (
    id BIGSERIAL PRIMARY KEY,
    link_id BIGINT NOT NULL REFERENCES project_todo_worker_links(id) ON DELETE CASCADE,
    verdict TEXT NOT NULL CHECK (verdict IN ('accept', 'reject')),
    evidence JSONB NOT NULL,
    actor TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX project_todo_worker_verdicts_link ON project_todo_worker_verdicts(link_id, id DESC);

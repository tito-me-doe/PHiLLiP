ALTER TABLE project_todos
  ADD COLUMN IF NOT EXISTS creator_todo_id BIGINT REFERENCES project_todos(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS revision BIGINT NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS run_revision BIGINT,
  ADD COLUMN IF NOT EXISTS ready_revision BIGINT,
  ADD COLUMN IF NOT EXISTS readiness JSONB NOT NULL DEFAULT '{}'::jsonb,
  ADD COLUMN IF NOT EXISTS wait_reason TEXT NULL CHECK (wait_reason IN ('children','decision')),
  ADD COLUMN IF NOT EXISTS continuations INTEGER NOT NULL DEFAULT 0;

ALTER TABLE project_todos DROP CONSTRAINT IF EXISTS project_todos_status_check;
ALTER TABLE project_todos ADD CONSTRAINT project_todos_status_check CHECK (status IN ('pending', 'working', 'review', 'done', 'blocked', 'waiting'));

ALTER TABLE project_todos DROP CONSTRAINT IF EXISTS project_todos_creator_todo_id_fkey;
ALTER TABLE project_todos ADD CONSTRAINT project_todos_creator_fkey FOREIGN KEY (project_id, creator_todo_id) REFERENCES project_todos(project_id, id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS project_decisions (
    id BIGSERIAL PRIMARY KEY,
    project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    todo_id BIGINT NOT NULL REFERENCES project_todos(id) ON DELETE CASCADE,
    target_todo_id BIGINT REFERENCES project_todos(id) ON DELETE SET NULL,
    todo_revision BIGINT NOT NULL,
    question TEXT NOT NULL,
    evidence JSONB NOT NULL DEFAULT '{}'::jsonb,
    answer TEXT,
    answered_by TEXT,
    answered_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS project_decisions_project ON project_decisions(project_id, id);
CREATE INDEX IF NOT EXISTS project_decisions_todo ON project_decisions(todo_id);
CREATE INDEX IF NOT EXISTS project_decisions_target ON project_decisions(target_todo_id);

ALTER TABLE project_decisions DROP CONSTRAINT IF EXISTS project_decisions_todo_fkey;
ALTER TABLE project_decisions ADD CONSTRAINT project_decisions_todo_fkey FOREIGN KEY (project_id, todo_id) REFERENCES project_todos(project_id, id);
ALTER TABLE project_decisions DROP CONSTRAINT IF EXISTS project_decisions_target_fkey;
ALTER TABLE project_decisions ADD CONSTRAINT project_decisions_target_fkey FOREIGN KEY (project_id, target_todo_id) REFERENCES project_todos(project_id, id);

-- Correct the first recursive draft and bind every relationship to its project.
ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_status_check;
ALTER TABLE projects ADD CONSTRAINT projects_status_check
    CHECK (status IN ('planning', 'running', 'paused', 'blocked', 'completed'));
ALTER TABLE project_todos DROP CONSTRAINT IF EXISTS project_todos_status_check;
ALTER TABLE project_todos ADD CONSTRAINT project_todos_status_check
    CHECK (status IN ('pending', 'working', 'waiting', 'review', 'done', 'blocked'));

ALTER TABLE project_todos DROP CONSTRAINT IF EXISTS project_todos_creator_todo_id_fkey;
ALTER TABLE project_todos DROP CONSTRAINT IF EXISTS project_todos_creator_fkey;
ALTER TABLE project_todos DROP CONSTRAINT IF EXISTS project_todos_creator_same_project;
ALTER TABLE project_todos ADD CONSTRAINT project_todos_creator_same_project
    FOREIGN KEY (project_id, creator_todo_id) REFERENCES project_todos(project_id, id) ON DELETE CASCADE;
ALTER TABLE project_todos DROP CONSTRAINT IF EXISTS project_todos_creator_is_parent;
ALTER TABLE project_todos ADD CONSTRAINT project_todos_creator_is_parent
    CHECK (creator_todo_id IS NULL OR creator_todo_id = parent_id);

ALTER TABLE project_decisions DROP CONSTRAINT IF EXISTS project_decisions_todo_id_fkey;
ALTER TABLE project_decisions DROP CONSTRAINT IF EXISTS project_decisions_target_todo_id_fkey;
ALTER TABLE project_decisions DROP CONSTRAINT IF EXISTS project_decisions_todo_fkey;
ALTER TABLE project_decisions DROP CONSTRAINT IF EXISTS project_decisions_target_fkey;
ALTER TABLE project_decisions DROP CONSTRAINT IF EXISTS project_decisions_requester_project;
ALTER TABLE project_decisions DROP CONSTRAINT IF EXISTS project_decisions_target_project;
ALTER TABLE project_decisions ADD CONSTRAINT project_decisions_requester_project
    FOREIGN KEY (project_id, todo_id) REFERENCES project_todos(project_id, id) ON DELETE CASCADE;
ALTER TABLE project_decisions ADD CONSTRAINT project_decisions_target_project
    FOREIGN KEY (project_id, target_todo_id) REFERENCES project_todos(project_id, id) ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS project_decisions_unanswered ON project_decisions(project_id, target_todo_id)
    WHERE answer IS NULL;

-- UI state is browser-local. Remove only the prototype UI scopes and their trigger.
DROP TRIGGER prune_panel_state ON messages;
DROP FUNCTION phillip_prune_panel_state();
DELETE FROM states WHERE scope IN ('ui.user', 'ui.thread');

ALTER TABLE threads ADD COLUMN creation_key TEXT;
ALTER TABLE threads ADD COLUMN creation_hash TEXT;
CREATE UNIQUE INDEX one_thread_per_submission ON threads(owner_id, creation_key) WHERE creation_key IS NOT NULL;
ALTER TABLE runs ADD COLUMN submission_key TEXT;
ALTER TABLE runs ADD COLUMN submission_hash TEXT;
CREATE UNIQUE INDEX one_run_per_submission ON runs(thread_id, author_id, submission_key) WHERE submission_key IS NOT NULL;

-- Fewer, clearer pages: Access (users, groups, pages and tools), System Settings (models, profiles, parsing, services)
-- and Personas & Experts. Personas can be switched off without deleting them.
ALTER TABLE personas ADD COLUMN IF NOT EXISTS enabled BOOLEAN NOT NULL DEFAULT TRUE;

ALTER TABLE pages DROP CONSTRAINT IF EXISTS pages_known_handler;
UPDATE pages SET title='Access' WHERE id='access';
UPDATE pages SET title='System Settings' WHERE id='models';
UPDATE pages SET title='Personas & Experts' WHERE id='personas';
INSERT INTO permissions(scope_type,scope_id,resource,allowed)
SELECT scope_type,scope_id,'page.models',TRUE FROM permissions WHERE resource='page.tools-services' ON CONFLICT DO NOTHING;
INSERT INTO permissions(scope_type,scope_id,resource,allowed)
SELECT scope_type,scope_id,'page.access',TRUE FROM permissions WHERE resource='page.navigation' ON CONFLICT DO NOTHING;
DELETE FROM permissions WHERE resource IN ('page.tools-services','page.navigation');
DELETE FROM pages WHERE id IN ('tools-services','navigation');
ALTER TABLE pages ADD CONSTRAINT pages_known_handler CHECK(handler IN
('conversations','projects','personas','knowledge','benchmarks','appearance','models','access'));

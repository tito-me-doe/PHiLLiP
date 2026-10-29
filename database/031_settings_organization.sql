-- Consolidate tool configuration without duplicating menu or permission concepts.
ALTER TABLE pages DROP CONSTRAINT pages_known_handler;
UPDATE pages SET title='Users & Groups' WHERE id='access';
UPDATE pages SET title='Models & Profiles' WHERE id='models';
INSERT INTO pages(id,title,path,handler) VALUES
('tools-services','Tools & Services','/tools-services','tools-services');
UPDATE pages SET handler='tools-services' WHERE handler IN ('services','parsers');
DELETE FROM pages WHERE id IN ('services','parsers');
ALTER TABLE pages ADD CONSTRAINT pages_known_handler CHECK(handler IN
('conversations','projects','personas','knowledge','benchmarks','test-suites','appearance','models','tools-services','access','navigation'));
INSERT INTO permissions(scope_type,scope_id,resource,allowed)
SELECT scope_type,scope_id,'page.tools-services',TRUE FROM permissions
WHERE resource IN ('page.services','page.parsers') ON CONFLICT DO NOTHING;
DELETE FROM permissions WHERE resource IN ('page.services','page.parsers');

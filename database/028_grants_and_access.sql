-- Everyone is implicit; the two operator groups are ordinary, additive memberships.
INSERT INTO teams(id,name) VALUES ('0','Everyone'),('1','Administrators'),('2','Developers')
ON CONFLICT(id) DO UPDATE SET name=EXCLUDED.name;
INSERT INTO memberships(user_id,team_id)
SELECT u.id,g.id FROM users u CROSS JOIN (VALUES ('1'),('2')) AS g(id)
WHERE u.is_admin OR u.id IN (SELECT jsonb_array_elements_text(value->'admin_ids') FROM settings WHERE key='installation')
ON CONFLICT DO NOTHING;
DELETE FROM memberships WHERE team_id='0';

ALTER TABLE pages DROP CONSTRAINT pages_known_handler;
UPDATE pages SET handler='access' WHERE handler IN ('users','groups','memberships','permissions');
DELETE FROM pages WHERE id IN ('users','groups','memberships','permissions');
INSERT INTO pages(id,title,path,handler,section) VALUES ('access','Access control','/access','access','Administration');
ALTER TABLE pages ADD CONSTRAINT pages_known_handler CHECK(handler IN
('conversations','projects','personas','knowledge','benchmarks','test-suites','appearance','models','services','parsers','access','navigation'));
UPDATE pages SET path='/chat',title='Chat' WHERE id='conversations';
ALTER TABLE pages DROP COLUMN default_access, DROP COLUMN section, DROP COLUMN position;

-- Permission rows are grants, never blockers. The boolean remains for mapped-table compatibility.
DELETE FROM permissions WHERE NOT allowed OR scope_type='organization' OR (scope_type='team' AND scope_id='0');
ALTER TABLE permissions ADD CONSTRAINT permissions_grants_only CHECK(allowed);
INSERT INTO permissions(scope_type,scope_id,resource,allowed) VALUES
('team','0','page.conversations',TRUE),
('team','0','Agent\Tools\SearchWeb',TRUE),
('team','0','Agent\Tools\Fetch',TRUE),
('team','0','Agent\Tools\BrowseWeb',TRUE),
('team','0','Agent\Tools\Memory',TRUE)
ON CONFLICT DO NOTHING;
INSERT INTO permissions(scope_type,scope_id,resource,allowed)
SELECT 'team','1','page.'||id,TRUE FROM pages
ON CONFLICT DO NOTHING;
INSERT INTO permissions(scope_type,scope_id,resource,allowed) VALUES ('team','1','Agent\Tools\Tasks',TRUE)
ON CONFLICT DO NOTHING;

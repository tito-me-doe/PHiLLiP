-- Test definitions are edited in Benchmarks, not a separate navigation concept.
ALTER TABLE pages DROP CONSTRAINT pages_known_handler;
UPDATE pages SET handler='benchmarks' WHERE handler='test-suites';
DELETE FROM pages WHERE id='test-suites';
ALTER TABLE pages ADD CONSTRAINT pages_known_handler CHECK(handler IN
('conversations','projects','personas','knowledge','benchmarks','appearance','models','tools-services','access','navigation'));
INSERT INTO permissions(scope_type,scope_id,resource,allowed)
SELECT scope_type,scope_id,'page.benchmarks',TRUE FROM permissions WHERE resource='page.test-suites' ON CONFLICT DO NOTHING;
DELETE FROM permissions WHERE resource='page.test-suites';

-- The most recent matching control wins, so resuming a tab cannot resume other tabs.
CREATE SEQUENCE benchmark_control_revision;
ALTER TABLE benchmark_controls ADD COLUMN version BIGINT NOT NULL DEFAULT 0;
UPDATE benchmark_controls SET version=nextval('benchmark_control_revision') WHERE paused;

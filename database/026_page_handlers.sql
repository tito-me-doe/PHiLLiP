-- Additional routes may point only to a registered concept, never to an arbitrary PHP file.
ALTER TABLE pages ADD COLUMN handler TEXT;
UPDATE pages SET handler=id;
ALTER TABLE pages ALTER COLUMN handler SET NOT NULL;
ALTER TABLE pages ADD CONSTRAINT pages_known_handler CHECK(handler IN ('conversations','projects','personas','knowledge','benchmarks','test-suites','appearance','models','services','parsers','users','groups','memberships','permissions','navigation'));

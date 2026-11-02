-- "Access" becomes "Permissions & Users". The Appearance page is dropped: the theme toggle lives in every header
-- and Sign out in the pages menu.
ALTER TABLE pages DROP CONSTRAINT IF EXISTS pages_known_handler;
UPDATE pages SET title='Permissions & Users' WHERE id='access';
DELETE FROM permissions WHERE resource='page.appearance';
DELETE FROM pages WHERE id='appearance';
ALTER TABLE pages ADD CONSTRAINT pages_known_handler CHECK(handler IN
('conversations','projects','personas','knowledge','benchmarks','models','access'));

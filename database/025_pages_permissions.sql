-- Preserve existing grants and ERP column mappings while making policy resources general.
ALTER TABLE tool_permissions RENAME TO permissions;
ALTER TABLE permissions RENAME COLUMN tool TO resource;
CREATE VIEW tool_permissions AS SELECT scope_type,scope_id,resource AS tool,allowed FROM permissions;

CREATE TABLE pages (
    id TEXT PRIMARY KEY,
    title TEXT NOT NULL CHECK(length(title) BETWEEN 1 AND 80),
    path TEXT NOT NULL UNIQUE CHECK(path ~ '^/[a-z][a-z0-9-]*(/[a-z][a-z0-9-]*)?$'),
    section TEXT NOT NULL CHECK(section IN ('Workspace','Personal','Administration')),
    position INTEGER NOT NULL DEFAULT 0,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    in_navigation BOOLEAN NOT NULL DEFAULT TRUE,
    default_access BOOLEAN NOT NULL DEFAULT FALSE
);
INSERT INTO pages(id,title,path,section,position,default_access,in_navigation) VALUES
('conversations','Conversations','/conversations','Workspace',10,TRUE,FALSE),
('projects','New project','/projects','Workspace',20,TRUE,FALSE),
('personas','Personas','/personas','Workspace',30,TRUE,TRUE),
('knowledge','Memory','/memory','Workspace',40,TRUE,TRUE),
('benchmarks','Benchmarks','/benchmarks','Workspace',50,FALSE,TRUE),
('test-suites','Test suites','/test-suites','Workspace',60,FALSE,TRUE),
('appearance','Appearance','/appearance','Personal',10,TRUE,TRUE),
('models','Models','/models','Administration',10,FALSE,TRUE),
('services','Services','/services','Administration',20,FALSE,TRUE),
('parsers','Attachment parsing','/attachment-parsers','Administration',30,FALSE,TRUE),
('users','Users','/users','Administration',40,FALSE,TRUE),
('groups','Groups','/groups','Administration',50,FALSE,TRUE),
('memberships','Memberships','/memberships','Administration',60,FALSE,TRUE),
('permissions','Permissions','/permissions','Administration',70,FALSE,TRUE),
('navigation','Navigation & routes','/navigation','Administration',80,FALSE,TRUE);

-- Translate disabled pages into absent grants, then remove the parallel access controls.
DELETE FROM permissions p USING pages r WHERE NOT r.enabled AND p.resource='page.'||r.id;
ALTER TABLE pages DROP COLUMN enabled, DROP COLUMN in_navigation;

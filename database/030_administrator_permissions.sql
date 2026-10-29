-- Group 1 has all permissions implicitly, including resources added in the future.
-- Ownership and content visibility are enforced separately and remain unchanged.
DELETE FROM permissions WHERE scope_type IN ('team', 'group') AND scope_id = '1';

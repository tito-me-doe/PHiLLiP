ALTER TABLE threads ADD COLUMN IF NOT EXISTS deletedon TIMESTAMPTZ;
ALTER TABLE operations DROP CONSTRAINT IF EXISTS operations_type_check;
ALTER TABLE operations ADD CONSTRAINT operations_type_check CHECK(type IN ('scan', 'profile_verify', 'thread_title'));

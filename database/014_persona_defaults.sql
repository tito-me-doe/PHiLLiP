ALTER TABLE personas ADD COLUMN IF NOT EXISTS tools JSONB NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(tools) = 'array');
ALTER TABLE personas ADD COLUMN IF NOT EXISTS contract JSONB CHECK (contract IS NULL OR jsonb_typeof(contract) = 'object');

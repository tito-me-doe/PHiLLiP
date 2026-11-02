-- Personas are characters people talk to: identity, personality sheet and avatar live beside the composed prompt.
-- All columns are nullable or defaulted, so older code that only knows name/prompt/tools/contract keeps working.
ALTER TABLE personas ADD COLUMN IF NOT EXISTS slug TEXT;
ALTER TABLE personas ADD COLUMN IF NOT EXISTS pronouns TEXT;
ALTER TABLE personas ADD COLUMN IF NOT EXISTS tagline TEXT;
ALTER TABLE personas ADD COLUMN IF NOT EXISTS archetype TEXT;
ALTER TABLE personas ADD COLUMN IF NOT EXISTS maturity TEXT NOT NULL DEFAULT 'everyone';
ALTER TABLE personas ADD COLUMN IF NOT EXISTS avatar TEXT;
ALTER TABLE personas ADD COLUMN IF NOT EXISTS sheet JSONB;
DO $$ BEGIN
    ALTER TABLE personas ADD CONSTRAINT personas_maturity_known CHECK (maturity IN ('everyone', 'adult'));
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN
    ALTER TABLE personas ADD CONSTRAINT personas_sheet_object CHECK (sheet IS NULL OR jsonb_typeof(sheet) = 'object');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
-- A shipped character is identified by its slug; the existing default_persona_names index keeps names unique among defaults.
CREATE UNIQUE INDEX IF NOT EXISTS default_persona_slugs ON personas(slug) WHERE owner_id IS NULL AND slug IS NOT NULL;
-- Anyone may create their own persona (they can edit or delete only their own; shared ones stay administrator-managed).
INSERT INTO permissions (scope_type, scope_id, resource, allowed) VALUES ('team', '0', 'page.personas', TRUE) ON CONFLICT DO NOTHING;

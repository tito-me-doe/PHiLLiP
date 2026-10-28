ALTER TABLE models
    ADD COLUMN IF NOT EXISTS supports_chat BOOLEAN,
    ADD COLUMN IF NOT EXISTS supports_tools BOOLEAN,
    ADD COLUMN IF NOT EXISTS supports_vision BOOLEAN,
    ADD COLUMN IF NOT EXISTS supports_structured_output BOOLEAN,
    ADD COLUMN IF NOT EXISTS supports_streaming BOOLEAN,
    ADD COLUMN IF NOT EXISTS supports_embeddings BOOLEAN,
    ADD COLUMN IF NOT EXISTS supports_rerank BOOLEAN,
    ADD COLUMN IF NOT EXISTS supports_reasoning BOOLEAN,
    ADD COLUMN IF NOT EXISTS supports_pdf BOOLEAN;
UPDATE models SET supports_chat = (capabilities->>'chat')::boolean, supports_tools = (capabilities->>'tools')::boolean, supports_vision = (capabilities->>'vision')::boolean, supports_structured_output = (capabilities->>'structured_output')::boolean, supports_streaming = (capabilities->>'streaming')::boolean, supports_embeddings = (capabilities->>'embeddings')::boolean, supports_rerank = (capabilities->>'rerank')::boolean, supports_reasoning = (capabilities->>'reasoning')::boolean, supports_pdf = (capabilities->>'pdf')::boolean;
ALTER TABLE profiles
    ADD COLUMN IF NOT EXISTS supports_chat BOOLEAN,
    ADD COLUMN IF NOT EXISTS supports_tools BOOLEAN,
    ADD COLUMN IF NOT EXISTS supports_vision BOOLEAN,
    ADD COLUMN IF NOT EXISTS supports_structured_output BOOLEAN,
    ADD COLUMN IF NOT EXISTS supports_streaming BOOLEAN,
    ADD COLUMN IF NOT EXISTS supports_embeddings BOOLEAN,
    ADD COLUMN IF NOT EXISTS supports_rerank BOOLEAN,
    ADD COLUMN IF NOT EXISTS supports_reasoning BOOLEAN,
    ADD COLUMN IF NOT EXISTS supports_pdf BOOLEAN;
UPDATE profiles SET supports_chat = (capabilities->>'chat')::boolean, supports_tools = (capabilities->>'tools')::boolean, supports_vision = (capabilities->>'vision')::boolean, supports_structured_output = (capabilities->>'structured_output')::boolean, supports_streaming = (capabilities->>'streaming')::boolean, supports_embeddings = (capabilities->>'embeddings')::boolean, supports_rerank = (capabilities->>'rerank')::boolean, supports_reasoning = (capabilities->>'reasoning')::boolean, supports_pdf = (capabilities->>'pdf')::boolean;
ALTER TABLE profiles ADD COLUMN IF NOT EXISTS metadata JSONB NOT NULL DEFAULT '{}';
UPDATE profiles SET metadata = jsonb_build_object('tests', test_results, 'overrides', overrides);
ALTER TABLE models DROP COLUMN capabilities;
ALTER TABLE profiles DROP COLUMN capabilities, DROP COLUMN overrides, DROP COLUMN test_results;

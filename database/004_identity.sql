CREATE TABLE IF NOT EXISTS users (
    id TEXT PRIMARY KEY,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT,
    display_name TEXT NOT NULL,
    is_admin BOOLEAN NOT NULL DEFAULT FALSE,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    preferences JSONB NOT NULL DEFAULT '{}'
);
CREATE TABLE IF NOT EXISTS teams (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS memberships (
    user_id TEXT NOT NULL,
    team_id TEXT NOT NULL,
    PRIMARY KEY(user_id, team_id)
);
CREATE TABLE IF NOT EXISTS tool_permissions (
    scope_type TEXT NOT NULL CHECK (scope_type IN ('user', 'team', 'organization')),
    scope_id TEXT NOT NULL,
    tool TEXT NOT NULL,
    allowed BOOLEAN NOT NULL,
    PRIMARY KEY(scope_type, scope_id, tool)
);
CREATE TABLE IF NOT EXISTS personas (
    id BIGSERIAL PRIMARY KEY,
    owner_id TEXT,
    name TEXT NOT NULL,
    prompt TEXT NOT NULL,
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    visibility TEXT NOT NULL CHECK (visibility IN ('private', 'team', 'organization')),
    team_id TEXT,
    CHECK ((visibility = 'team' AND team_id IS NOT NULL) OR (visibility <> 'team' AND team_id IS NULL))
);

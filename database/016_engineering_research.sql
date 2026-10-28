-- Curated engineering telemetry. No chat content, identities, endpoints, settings,
-- profile JSON, errors, SQL defaults or arbitrary model metadata cross this boundary.
CREATE SCHEMA IF NOT EXISTS engineering_research;
REVOKE ALL ON SCHEMA engineering_research FROM PUBLIC;

CREATE OR REPLACE VIEW engineering_research.run_daily AS
SELECT date_trunc('day', created_at) AS day, status, count(*) AS run_count,
       count(started_at) AS started_count, count(finished_at) AS finished_count,
       avg(extract(epoch FROM (started_at - created_at))) AS mean_queue_seconds,
       avg(extract(epoch FROM (finished_at - started_at))) AS mean_execution_seconds,
       max(extract(epoch FROM (finished_at - started_at))) AS max_execution_seconds
FROM public.runs GROUP BY date_trunc('day', created_at), status;

CREATE OR REPLACE VIEW engineering_research.agent_summary AS
SELECT depth, paused, count(*) AS agent_count,
       count(*) FILTER (WHERE worker_pid IS NOT NULL) AS assigned_worker_count
FROM public.agents GROUP BY depth, paused;

CREATE OR REPLACE VIEW engineering_research.model_summary AS
SELECT CASE WHEN status IN ('available', 'unavailable', 'unknown') THEN status ELSE 'other' END AS status,
       supports_chat, supports_tools, supports_vision, supports_structured_output,
       supports_streaming, supports_embeddings, supports_rerank, supports_reasoning,
       supports_pdf, count(*) AS model_count
FROM public.models
GROUP BY CASE WHEN status IN ('available', 'unavailable', 'unknown') THEN status ELSE 'other' END,
       supports_chat, supports_tools, supports_vision, supports_structured_output,
       supports_streaming, supports_embeddings, supports_rerank, supports_reasoning, supports_pdf;

-- information_schema is filtered by the caller's privileges. Materialize only
-- these safe definition fields under operator credentials, never grant base access.
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
               WHERE n.nspname = 'engineering_research' AND c.relname = 'schema_catalog' AND c.relkind = 'v') THEN
        DROP VIEW engineering_research.schema_catalog;
    END IF;
END $$;
CREATE TABLE IF NOT EXISTS engineering_research.schema_catalog (
    table_schema TEXT NOT NULL,
    table_name TEXT NOT NULL,
    column_name TEXT NOT NULL,
    ordinal_position INTEGER NOT NULL,
    data_type TEXT NOT NULL,
    is_nullable TEXT NOT NULL,
    PRIMARY KEY (table_schema, table_name, column_name)
);
TRUNCATE engineering_research.schema_catalog;
INSERT INTO engineering_research.schema_catalog
SELECT table_schema, table_name, column_name, ordinal_position, data_type, is_nullable
FROM information_schema.columns WHERE table_schema IN ('public', 'engineering_research');

REVOKE ALL ON ALL TABLES IN SCHEMA engineering_research FROM PUBLIC;

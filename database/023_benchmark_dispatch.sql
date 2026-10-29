ALTER TABLE benchmark_runs ADD COLUMN IF NOT EXISTS dispatch_mode TEXT NOT NULL DEFAULT 'queue' CHECK(dispatch_mode IN ('manual','queue'));

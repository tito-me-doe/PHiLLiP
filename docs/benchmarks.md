# Benchmarks — operator guide

Run saved ten-question suites against saved model profiles. Results are durable, per-question, and inspectable through the Benchmarks UI. Native schemas (openai/ollama/tei) and model types (chat/embed/rerank/classify/extract/vision) are distinct, explicitly configured concepts.

## Commands

`bin/phill-bench -p PROFILE -b TEST [--json]`

Runs one saved test against one saved profile. Names or IDs are accepted. `--list` prints saved tests without inference. `--json` emits the summary machine-readably. `--debug`/`--verbose` are consumed by bootstrap before argument parsing and do work. Exit codes: 0 completed, 1 failed, 2 invalid usage, 130 interrupted.

`bin/phill-bench-run [-p PROFILES_CSV] [-b TESTS_CSV] [--json]`

Default: enqueue every saved profile × every runnable saved test (max 1000 pairs), then drain runnable work. Implicit all-test runs skip empty/invalid drafts with a warning; explicitly selected invalid tests fail. Pairs whose profile is known not to support the test's model type are skipped. `--drain` runs existing queued jobs without adding any. `--worker` supervises the queue continuously. `--worker`/`--drain` cannot be combined with `-p`/`-b`. Exit 3 means queued/running/paused work remains. If a persistent worker already owns the queue, the CLI observes its submitted jobs and returns their final results rather than starting a duplicate worker. Interrupting that observation does not cancel the independent worker; use Stop for that.

Quick reference:

- List tests: `bin/phill-bench --list`
- Run one suite: `bin/phill-bench -p PROFILE -b TEST`
- JSON summary: `bin/phill-bench -p PROFILE -b TEST --json`
- Queue everything: `bin/phill-bench-run`
- Drain only: `bin/phill-bench-run --drain`
- Continuous worker: `bin/phill-bench-run --worker`
- Specific profiles/tests: `bin/phill-bench-run -p p1,p2 -b t1,t2`
- Verbose/debug logging: `--verbose` / `--debug` (consumed by bootstrap)

## Shipped suites (ten questions each)

Seeded in `database/022_benchmarks.sql`, all `chat` type with the `matches` scorer:

- `php-correctness` — PHP 8 semantics; deterministic, no generated code executed.
- `grounded-reasoning` — business arithmetic, dates, evidence tracking, resistance to instructions embedded in source data.
- `structured-extraction` — typed business data into strict JSON contracts without fabricating missing values.

Example question inputs:

- Chat: `{"prompt":"PHP 8: echo count(['a'=>1,'b'=>2]); Return only the output."}`
- Extract with contract: `{"prompt":"Extract from: Invoice INV-204 for customer Acme.","contract":{"invoice_id":{"type":"string"},"customer":{"type":"string"}}}`
- Classify: `{"prompt":"Please refund this invoice","labels":["billing","technical"]}`
- Embed: `{"texts":["PHP is a programming language."]}`
- Rerank: `{"query":"PHP","documents":["PHP is a programming language.","The sky is blue."]}`
- Vision: `{"prompt":"What is the dominant color?","image":"data:image/png;base64,..."}`

## Native schemas and model types

Model types enforced by `Benchmarks::MODEL_TYPES`: `chat`, `embed`, `rerank`, `classify`, `extract`, `vision`.

Native request schema set per profile via `request_schema ∈ {openai, ollama, tei}` (`Models::native()`):

| Schema | Embed | Rerank |
|---|---|---|
| openai | `/v1/embeddings`, body `{model, input}` | `/v1/rerank`, body `{model, query, documents}` |
| ollama | `/api/embed`, body `{model, input}` | not supported |
| tei | `/embed`, body `{inputs}` | `/rerank`, body `{query, texts}` |

The profile URL path is rewritten by `Models::endpoint()`; embedding responses are validated for vector count, index, dimensions, and finite coordinates. Classification and extraction run through the chat model with an output contract; vision combines text and `image_url` content.

## Scoring

Scorers (`Benchmarks::SCORERS`): `matches`, `contains`, `starts_with`, `ends_with`, `pcre`, `llm_score`.

- `matches`: exact string equality, or deep JSON equality when criteria is JSON.
- `contains`/`starts_with`/`ends_with`: substring checks → 1.0 / 0.0.
- `pcre`: `preg_match` with bounded backtracking/recursion budgets, restored afterward.
- `llm_score`: an LLM judge scores 0–1 with a reason, using `Profiles::defaultFor('score', ...)`; the judge is a measurement input, not ground truth.

Execution hardens config: `tools_enabled=false`, `max_rounds=1`, `max_retries=0`, `max_contract_corrections=0`, `compaction_enabled=false`, `timeout=120`, `max_tokens=2048` when unset. `first_token_seconds` comes from the first `text.delta` event; `tokens_per_second = completion_tokens / elapsed_seconds` only when usage is present — unknown usage is omitted, never estimated.

The frontend plots actual elapsed wall time in seconds, not tokens/second or requests/second, for every model type. Lower time is better. Token metrics remain diagnostic data in saved results. Failed/stopped jobs retain their status and recorded counts; only completed runs enter the score/chart. Transport/authentication/service-unavailable errors end the job early instead of repeating every remaining question against a broken profile.

## Pause / resume / stop / recovery

Controls live in `benchmark_controls(scope, paused, version)` with global, `test:<id>`, `profile:<id>`, and `profile:<id>:test:<id>` scopes. The latest matching control wins. Every control update gets a sequence revision, serialized under the queue's global row lock.

- Pause finishes the active question, then marks the run `paused` at the next question boundary.
- Resume returns paused runs in its scope to queued. Resuming a specific tab/profile overrides an earlier All pause only for that scope, leaving unrelated work paused. A later All resume applies to everything. Standalone single-run commands do not wait on queue pauses.
- Stop cancels queued/paused/running runs and requests stop on each still-running result.
- Recovery (`BenchmarkRunner::recover()`) marks abandoned runs as `failed` after 90 seconds; incomplete results are stopped, not replayed; interrupted work is never silently replayed. The queue worker owns an advisory lock and recovers stale runs every 10 seconds.

## Editor and revisions

Tests/questions are edited within **Benchmarks**: the final **New Test** tab opens a creation dialog, and a selected test exposes **Edit Test** and **Edit Questions** below the graph. The question dialog uses read-only rows with modal editors and ordinary PHP form fallbacks. Optimistic concurrency remains: `lockTest()` throws `StateConflict` ("Test changed; reload before editing") when the supplied revision mismatches. Edits bump `revision`; tests are capped at 200 questions. Queued/completed runs retain their original questions and configuration — nothing is evaluated as PHP or shell code.

## Fixed task defaults

`Profiles::DEFAULT_TASKS = ['embed','rerank','classify','extract','generate_titles','summarize','score']`. These are the only valid `profile_defaults.default_for` values (enforced by table CHECK constraint). `Profiles::defaultFor($task, $fallback)` returns the assigned profile or resolves `$fallback` (default `'default'`). The `llm_score` scorer uses the `score` task default as its judge model.

In **System Settings**, the **Default for** column edits all tasks assigned to a profile in one dialog. `Profiles::setDefaults($profile, $tasks)` atomically replaces that profile's assignments without clearing other profiles' unrelated defaults. Missing chat/embedding dependencies are listed below the Profiles table; other unassigned tasks use their documented fallback.

## Report and controls

`BenchmarkFrontend::report()` serves one profile-centric leaderboard. Only saved profiles appear, never unprofiled registry models. The default **All** tab combines each profile's latest completed run per test as total earned question scores / total questions seen (including fractional scores), and sums their wall times. A test tab shows that test only. New tests and revisions do not invalidate previous scores; a new completed run replaces that profile/test's previous result. Sorting is score descending, then wall time ascending, with unscored profiles last. The Pareto frontier favors higher scores and shorter times; coverage counts are displayed because All scores may reflect different tests/questions.

Process controls and scoped status sit between tabs and graph. Controls are icon-only and follow the work: idle offers Play; running or queued work offers Pause; paused work offers Play (resume) and Stop. On All, global controls target every test; on a test tab, they target only that test. Row controls additionally limit the action to that profile. Repeated Play reuses unfinished profile/test pairs rather than duplicating them. The page has no separate queue section, recent-runs list, or Test Suites navigation entry. Report, process controls, and definition editing share `page.benchmarks` permission.

Metadata, combined profile results, and individual run details open in PHP-rendered dialogs without leaving the report. The run details show each question's input, rubric, answer, thread, run, elapsed time, and raw saved conversation/response. Regular links/forms remain available without JavaScript. Credentials and private profile configuration are stripped from report payloads.

## Snapshots and durability

Each run snapshots the test questions and the resolved profile config at enqueue time into `benchmark_runs.snapshot`. Per-question results store `threadid`, `runid`, question JSON, score, answer, and `raw` JSONB. Run-level `score = AVG(result scores)` and `elapsed_seconds = SUM(...)`. The public `summary()` omits private configuration and raw provider bodies.

## Caveats

- Timing includes request and persistence overhead; engine token counts may include reasoning tokens. These are observed results, not a guarantee.
- Benchmark definitions and results may contain business data; do not treat them as public.
- `llm_score` judges are models, not ground truth.
- Native schema failures surface as "Configure a working task profile/schema."
- Capability probing records per-capability ok/seconds/error into profile metadata; profiles enable only when chat, embeddings, or rerank works.

## Operation and verification

Compose runs `benchmarks` as the persistent queue worker; restart it after editing worker PHP. Manual `phill-bench` jobs cannot be claimed by that worker. The queue worker is serialized per database schema; deliberately concurrent standalone runs can still contend for the same engine.

Use `ok-phill profiles defaults` to inspect assignments and `ok-phill profiles default embed PROFILE` to set one (`none` clears it). Models provides the same task-default controls through modal editors. `Tasks::classify`, `extract`, `rank`, and `similarity` are optional, permission-controlled tools. They select only the task's configured profile, falling back to the current model when unassigned; a chat-only fallback cannot fabricate embeddings.

Regression suites: `php tests/run.php benchmarks models benchmark_frontend`. Optional isolated browser checks: `php tests/benchmark_frontend.php --browser` (requires Playwright/Chromium). Live engine/schema coverage is narrower than fixture coverage; see [requirements](../REQUIREMENTS.md).

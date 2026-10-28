<?php
require __DIR__ . '/helpers.php';
use Agent\DatabaseResearch;
use Agent\Tools\SQL;

$registry = new Agent\ToolRegistry(); $registry->register(SQL::class);
check(count($registry->definitions()) === 4, 'SQL exposes only four documented research tools');
Agent\ToolContext::withValues(['frontend' => true], function () {
    expectException(function () { SQL::tables(); }, 'local CLI');
    expectException(function () { SQL::query('SELECT * FROM engineering_research.run_daily'); }, 'local CLI');
});

// Parser tests run without relying on database availability or provisioning.
$validate = new ReflectionMethod(DatabaseResearch::class, 'validate');
if (PHP_VERSION_ID < 80100) $validate->setAccessible(true);
$safe = [
    'SELECT status, sum(run_count) AS result_total FROM engineering_research.run_daily GROUP BY status ORDER BY result_total DESC',
    'SELECT status, count(*) AS n FROM engineering_research.run_daily GROUP BY status ORDER BY n DESC',
    "SELECT count(*) FROM schema_catalog WHERE table_schema = :schema AND ordinal_position >= :position",
    "SELECT 'it''s; -- just a literal /* */' AS result_text FROM schema_catalog LIMIT 1",
    "/* outer /* nested */ block */ SELECT/*separator*/count(*) FROM schema_catalog -- SELECT pg_read_file('/etc/passwd')\n",
    "SELECT extract(epoch FROM day) AS result_epoch FROM run_daily",
    'SELECT round(avg(mean_execution_seconds), 2) AS result_seconds FROM run_daily WHERE status IN (?, ?)',
    'SELECT count(*) FROM engineering_research.schema_catalog WHERE (ordinal_position != 1 AND ordinal_position <= 3)'
];
foreach ($safe as $sql) check(is_string($validate->invoke(null, $sql)), 'Positive research dialect accepts ordinary literals, comments, binds and safe aggregates');
$unsafe = [
    'SELECT * FROM public.settings', 'SELECT * FROM settings', 'SELECT * FROM pg_catalog.pg_authid',
    'SELECT * FROM information_schema.columns', 'SELECT * FROM engineering_research.settings',
    'SELECT * FROM run_daily; SELECT * FROM settings', 'SELECT * FROM run_daily;',
    'DELETE FROM run_daily', 'UPDATE run_daily SET status = NULL',
    'SELECT * INTO TEMP result_copy FROM run_daily',
    'WITH result_q AS (DELETE FROM settings RETURNING *) SELECT * FROM result_q',
    'WITH RECURSIVE result_q AS (SELECT 1) SELECT * FROM result_q',
    'SELECT (SELECT password FROM settings) FROM run_daily',
    'SELECT * FROM run_daily UNION SELECT * FROM settings',
    'SELECT * FROM run_daily JOIN schema_catalog ON true', 'SELECT * FROM run_daily, run_daily',
    'SELECT * FROM run_daily AS result_a, run_daily AS result_b',
    'SELECT pg_read_file(?) FROM run_daily', 'SELECT pg_read_binary_file(?) FROM run_daily',
    'SELECT pg_ls_dir(?) FROM run_daily', 'SELECT pg_sleep(2) FROM run_daily',
    'SELECT pg_advisory_lock(1) FROM run_daily', 'SELECT pg_notify(?, ?) FROM run_daily',
    'SELECT set_config(?, ?, false) FROM run_daily', 'SELECT dblink(?, ?) FROM run_daily',
    'SELECT lo_export(1, ?) FROM run_daily', 'SELECT query_to_xml(?) FROM run_daily',
    'SELECT pg_catalog.count(*) FROM run_daily', 'SELECT "count"(*) FROM run_daily',
    "SELECT E'escaped' FROM run_daily", 'SELECT $$literal$$ FROM run_daily',
    'SELECT status::regclass FROM run_daily', 'SELECT CAST(status AS text) FROM run_daily',
    'SELECT count(*) AS into FROM run_daily', 'SELECT count(*) AS pg_sleep, pg_sleep(2) FROM run_daily',
    'SELECT count(*) FROM run_daily FOR UPDATE', 'EXPLAIN ANALYZE SELECT * FROM run_daily',
    "SELECT 'unclosed FROM run_daily", 'SELECT count(*) FROM run_daily /* unclosed',
    "SELECT count(*) FROM run_daily\0", 'SELECT count(*) FROM run_daily WHERE status = :a::text'
];
foreach ($unsafe as $sql) {
    $rejected = false;
    try { $validate->invoke(null, $sql); } catch (InvalidArgumentException $e) { $rejected = true; }
    check($rejected, 'Unsafe SQL is rejected before opening the research connection');
}
expectException(function () { SQL::describe('public.settings'); }, 'returned by SQL::tables');

// Real privilege tests are mandatory when configured; a clean checkout may explicitly skip.
try { $config = Agent\Config::service('database_research'); }
catch (RuntimeException $e) { echo "SKIP: Run php bin/phill-research-provision for real PostgreSQL permission tests.\n"; exit(0); }
if (isset($config['dsn']) && strpos($config['dsn'], 'pgsql:') !== 0) {
    expectException(function () { SQL::tables(); }, 'PostgreSQL'); exit(0);
}
$tables = SQL::tables();
check($tables['tables'] === array_map(function ($table) { return 'engineering_research.' . $table; }, array_keys(DatabaseResearch::RELATIONS)), 'Dedicated connection lists exactly the fully qualified curated relation allowlist');
foreach ($tables['tables'] as $table) {
    $description = SQL::describe($table);
    check(array_column($description['rows'], 'column_name') === $tables['columns'][$table], 'Live curated relation columns match the strict query vocabulary');
}
check((int)SQL::query("SELECT count(*) AS n FROM schema_catalog WHERE table_schema = 'public' AND table_name = 'settings'")['rows'][0]['n'] > 0, 'Operator snapshot exposes production schema definitions without production records or grants');
$result = SQL::query('SELECT * FROM schema_catalog ORDER BY table_name, ordinal_position', [], 2);
check(count($result['rows']) === 2 && $result['truncated'], 'Actual database queries enforce the caller row bound');
check(count(SQL::query('SELECT * FROM schema_catalog', [], 0)['rows']) === 1, 'Nonpositive limits clamp to one');
$result = SQL::query('SELECT * FROM schema_catalog', [], 10000);
check(count($result['rows']) <= 100 && strlen(json_encode($result)) < 32768, 'Actual output remains below 100 rows and 32 KiB');
$literal = "it'; -- SQL /* comment */ is data";
$result = SQL::query('SELECT :label AS result_label FROM schema_catalog LIMIT 1', ['label' => $literal]);
check($result['rows'][0]['result_label'] === $literal, 'Named bound values cannot become SQL tokens');
$result = SQL::query('SELECT ? AS result_label FROM schema_catalog LIMIT 1', [$literal]);
check($result['rows'][0]['result_label'] === $literal, 'Positional bound values preserve quote and comment characters');
$result = SQL::query("SELECT 'it''s; -- literal /* */' AS result_label FROM schema_catalog LIMIT 1");
check($result['rows'][0]['result_label'] === "it's; -- literal /* */", 'Literal quote/comment lexing agrees with PostgreSQL');
$result = SQL::query("SELECT/*boundary*/count(*) AS result_total FROM schema_catalog -- semicolon ; ignored\n");
check((int)$result['rows'][0]['result_total'] > 0, 'Comment removal preserves token boundaries against the actual backend');
$result = SQL::query('SELECT table_name FROM schema_catalog WHERE ordinal_position >= :position ORDER BY table_name LIMIT 1', ['position' => 1]);
check(count($result['rows']) === 1, 'Comparison operators and typed bound parameters execute against PostgreSQL');
$result = SQL::explain('SELECT count(*) FROM run_daily');
check($result['explain'] && count($result['rows']) > 0, 'EXPLAIN returns a plan without ANALYZE');
expectException(function () { SQL::query('SELECT count(*) FROM schema_catalog WHERE ordinal_position = ?', [['nested']]); }, 'scalar');
expectException(function () { SQL::query('SELECT count(*) FROM schema_catalog WHERE ordinal_position = :position', ['position' => 'not an integer']); }, 'SQLSTATE');
check(SQL::query('SELECT count(*) FROM schema_catalog')['row_count'] === 1, 'Failed statements roll back without poisoning the next read-only transaction');

// Use the actual research credentials outside the SQL grammar to prove database ACLs.
$dsn = isset($config['dsn']) ? $config['dsn'] : getenv('DB_DSN');
$restricted = new PDO($dsn, $config['user'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
check($restricted->query('SHOW default_transaction_read_only')->fetchColumn() === 'on', 'Dedicated PostgreSQL role defaults to read-only transactions');
$restricted->exec('SET default_transaction_read_only = off');
foreach (['settings', 'users', 'threads', 'messages', 'events', 'runs', 'agents', 'profiles', 'endpoints', 'models', 'logs'] as $table) {
    $denied = false;
    try { $restricted->query('SELECT * FROM public.' . $table . ' LIMIT 1'); }
    catch (PDOException $e) { $denied = $e->getCode() === '42501'; }
    check($denied, 'Real research role cannot SELECT private production table ' . $table . ' even after disabling its read-only default');
}
foreach ([
    "DELETE FROM public.settings WHERE false",
    "INSERT INTO public.settings (key, value) SELECT 'research-test', '{}'::jsonb WHERE false",
    'DELETE FROM engineering_research.run_daily WHERE false',
    'CREATE TABLE engineering_research.research_forbidden (id int)',
    'CREATE TABLE public.research_forbidden (id int)',
    'SET ROLE "' . str_replace('"', '""', getenv('DB_USER')) . '"'
] as $sql) {
    $denied = false;
    $restricted->beginTransaction();
    try { $restricted->exec($sql); } catch (PDOException $e) { $denied = in_array($e->getCode(), ['42501', '55000'], true); }
    finally { $restricted->rollBack(); }
    check($denied, 'Database privileges independently reject mutations and role escalation');
}
foreach (array_keys(DatabaseResearch::RELATIONS) as $table) {
    $q = $restricted->prepare("SELECT has_table_privilege(current_user, ?, 'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')");
    $q->execute(['engineering_research.' . $table]);
    check(!$q->fetchColumn(), 'Research role has no mutation grants on a curated relation');
}
$start = microtime(true); $timedOut = false;
try { $restricted->query('SELECT pg_catalog.pg_sleep(2)'); } catch (PDOException $e) { $timedOut = $e->getCode() === '57014'; }
check($timedOut && microtime(true) - $start < 1.8, 'Server-side role statement timeout cancels an otherwise valid long read');
check(strlen(json_encode(SQL::tables())) < 4096, 'Research discovery never returns credentials or connection URLs');

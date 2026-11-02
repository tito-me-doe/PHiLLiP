<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';

/**
 * Regression test for task1093: private WebFixture shells.
 *
 * Scope (intentional, not a broad bootstrap redesign):
 *  - Stop running the shared engineering_research migration (migration016, database/016_engineering_research.sql)
 *    inside every PRIVATE WebFixture schema. Production migration016 is unchanged; it is provisioned globally by
 *    bin/phill-research-provision.
 *  - Do not replace global views or bootstrap the shared catalog from a private search_path.
 *  - Serialize the remaining shared, database-global fixture DDL (extension setup) with a DB advisory lock
 *    released in finally.
 *
 * Parallel workers (separate processes, separate schemas) construct and close a WebFixture. The parent asserts
 * separate schemas, correct private object lifecycle and cleanup, and that the shared engineering_research
 * definitions/catalog are unchanged by the fixture lifecycle.
 */

use Agent\Database;

if (($argv[1] ?? null) === 'worker') {
    $index = (int)($argv[2] ?? 0);
    $result = ['worker' => $index, 'ok' => false, 'schema' => null, 'error' => null];
    $web = null;
    try {
        $web = new WebFixture();
        $schemaRow = Database::row('SELECT current_schema() AS s');
        $schema = $schemaRow['s'];
        $result['schema'] = $schema;
        if ($schema === '' || strpos($schema, 'web_fixture_') !== 0) {
            throw new RuntimeException('Unexpected private schema: ' . var_export($schema, true));
        }
        // Private fixture objects must be constructible and readable within the worker's own schema.
        Database::execute("INSERT INTO endpoints (url, engine, status) VALUES (?, ?, 'online')", [
            'http://concurrency-worker-' . $index . '.invalid',
            'fixture',
        ]);
        $countRow = Database::row('SELECT count(*) AS n FROM endpoints');
        $count = (int)$countRow['n'];
        if ($count !== 1) {
            throw new RuntimeException('Private fixture object not readable: count=' . $count);
        }
        $result['ok'] = true;
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
    } finally {
        if ($web !== null) {
            $web->close();
        }
    }
    echo json_encode($result) . "\n";
    exit($result['ok'] ? 0 : 1);
}

// Parent: orchestrate parallel workers.
$workerCount = (int)(getenv('WEB_FIXTURE_CONCURRENCY_WORKERS') ?: 4);

// Baseline: shared engineering_research definitions/catalog before any fixture lifecycle.
$engineeringResearchRow = Database::row("SELECT count(*) AS n FROM information_schema.schemata WHERE schema_name = 'engineering_research'");
$engineeringResearch = (int)$engineeringResearchRow['n'] > 0;
function researchSnapshot($exists)
{
    if (!$exists) return null;
    $views = Database::rows("SELECT viewname, definition FROM pg_views WHERE schemaname = 'engineering_research' ORDER BY viewname");
    $catalog = Database::rows('SELECT * FROM engineering_research.schema_catalog ORDER BY table_schema, table_name, ordinal_position');
    return ['views' => $views, 'catalog' => $catalog];
}
$researchBefore = researchSnapshot($engineeringResearch);
$leftoverBeforeRow = Database::row("SELECT count(*) AS n FROM information_schema.schemata WHERE schema_name LIKE 'web_fixture_%'");
$leftoverBefore = (int)$leftoverBeforeRow['n'];

$workers = [];
for ($i = 0; $i < $workerCount; $i++) {
    $cmd = 'exec ' . implode(' ', array_map('escapeshellarg', [PHP_BINARY, __FILE__, 'worker', (string)$i]));
    $pipes = [];
    $process = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start concurrency worker ' . $i);
    }
    $workers[] = ['process' => $process, 'pipes' => $pipes];
}

$results = [];
foreach ($workers as $index => $spec) {
    $stdout = stream_get_contents($spec['pipes'][1]);
    $stderr = stream_get_contents($spec['pipes'][2]);
    fclose($spec['pipes'][1]);
    fclose($spec['pipes'][2]);
    $exit = proc_close($spec['process']);
    $decoded = json_decode($stdout, true);
    $results[] = ['index' => $index, 'exit' => $exit, 'result' => is_array($decoded) ? $decoded : null, 'stderr' => $stderr];
}

$schemas = [];
foreach ($results as $result) {
    $message = 'worker ' . $result['index'] . ' exit=' . $result['exit'] . ' stderr=' . $result['stderr'] . ' result=' . json_encode($result['result']);
    check($result['exit'] === 0, 'worker constructs and closes successfully: ' . $message);
    check(isset($result['result']['schema']) && $result['result']['ok'] === true, 'worker reports a private schema: ' . $message);
    $schemas[] = $result['result']['schema'];
}
check(count(array_unique($schemas)) === count($schemas), 'parallel fixtures construct with separate schemas');

// Private fixture cleanup: no net leak of web_fixture_% schemas after every worker closes.
$leftoverAfterRow = Database::row("SELECT count(*) AS n FROM information_schema.schemata WHERE schema_name LIKE 'web_fixture_%'");
$leftoverAfter = (int)$leftoverAfterRow['n'];
check($leftoverAfter === $leftoverBefore, 'private fixture schemas are dropped on close (before=' . $leftoverBefore . ', after=' . $leftoverAfter . ')');

// Shared engineering_research definitions/catalog must be unchanged by the fixture lifecycle.
$engineeringResearchAfterRow = Database::row("SELECT count(*) AS n FROM information_schema.schemata WHERE schema_name = 'engineering_research'");
$engineeringResearchAfter = (int)$engineeringResearchAfterRow['n'] > 0;
check($engineeringResearchAfter === $engineeringResearch, 'engineering_research schema presence unchanged by fixture lifecycle');
if ($engineeringResearch) {
    check(researchSnapshot(true) === $researchBefore, 'engineering_research view definitions and catalog contents are unchanged');
}
foreach ($schemas as $schema) {
    $remaining = Database::row('SELECT count(*) AS n FROM information_schema.schemata WHERE schema_name = ?', [$schema]);
    check((int)$remaining['n'] === 0, 'fixture schema ' . $schema . ' was removed');
}

<?php
require __DIR__ . '/helpers.php';

$testDb = getenv('PHILLIP_TEST_DATABASE');
check($testDb !== false && $testDb !== '', 'PHILLIP_TEST_DATABASE is set');
check(strpos($testDb, 'phillip_test_') === 0, 'PHILLIP_TEST_DATABASE starts with phillip_test_');

$prodDb = getenv('PHILLIP_PRODUCTION_DATABASE');
check($prodDb !== false && $prodDb !== '', 'PHILLIP_PRODUCTION_DATABASE is set');

// current_database() via Agent\Database::row
$row = Agent\Database::row("SELECT current_database() AS db");
check($row['db'] === $testDb, 'Connected to test database, current_database() matches');
check($row['db'] !== $prodDb, 'Test database differs from production database');

// migrations table populated
$count = Agent\Database::row("SELECT count(*) AS c FROM migrations");
check((int)$count['c'] > 0, 'Migrations table is populated');

// Write then read-back
Agent\Database::execute("CREATE TABLE IF NOT EXISTS isolation_check (id SERIAL PRIMARY KEY, value TEXT)");
Agent\Database::execute("INSERT INTO isolation_check (value) VALUES ('isolation-test-data')");
$row = Agent\Database::row("SELECT value FROM isolation_check WHERE value = 'isolation-test-data'");
check($row['value'] === 'isolation-test-data', 'Write then read-back works');

// Guard: refuse production DSN (reflection to reset PDO cache, save/restore DB_DSN in finally)
$reflection = new ReflectionClass(Agent\Database::class);
$property = $reflection->getProperty('connection');
$property->setAccessible(true);
$property->setValue(null, null);

$previousDsn = getenv('DB_DSN');
putenv('DB_DSN=pgsql:host=127.0.0.1;port=15432;dbname=' . $prodDb);
$_ENV['DB_DSN'] = 'pgsql:host=127.0.0.1;port=15432;dbname=' . $prodDb;
$_SERVER['DB_DSN'] = 'pgsql:host=127.0.0.1;port=15432;dbname=' . $prodDb;

try {
    Agent\Database::connection();
    check(false, 'Guard should throw for production DSN');
} catch (RuntimeException $e) {
    check(strpos($e->getMessage(), 'Refusing to connect to the production database') !== false, 'Guard message is correct');
} finally {
    putenv('DB_DSN=' . $previousDsn);
    $_ENV['DB_DSN'] = $previousDsn;
    $_SERVER['DB_DSN'] = $previousDsn;
$property->setValue(null, null);
}

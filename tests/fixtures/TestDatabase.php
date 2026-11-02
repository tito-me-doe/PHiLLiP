<?php
final class TestDatabase
{
    private static bool $prepared = false;
    private static string $testDbName = '';
    private const LOCK_KEY = 5787026487507950676;

    public static function prepare(): void
    {
        if (self::$prepared) {
            return;
        }

        // A child process (a test re-running itself, a worker, a CLI) inherits its parent's isolated database:
        // join it. Never create a second database or drop the parent's.
        $inherited = getenv('PHILLIP_TEST_DATABASE');
        if (getenv('PHILLIP_TESTING') === '1' && is_string($inherited) && preg_match('/^phillip_test_[0-9]+_[0-9a-f]{8}$/D', $inherited)
            && preg_match('/dbname=' . preg_quote($inherited, '/') . '(;|$)/', (string)getenv('DB_DSN'))) {
            self::$prepared = true;
            return;
        }

        // Resolve production connection settings from getenv() or root .env
        $dsn = getenv('DB_DSN');
        $user = getenv('DB_USER');
        $pass = getenv('DB_PASSWORD');

        if ($dsn === false || $dsn === '' || $user === false || $user === '') {
            $envPath = dirname(__DIR__, 2) . '/.env';
            if (is_file($envPath)) {
                $env = [];
                foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    if ($line[0] === '#' || strpos($line, '=') === false) continue;
                    list($key, $value) = explode('=', $line, 2);
                    $env[trim($key)] = trim($value, " \t\r\n\"'");
                }
                if ($dsn === false || $dsn === '') {
                    $dsn = $env['DB_DSN'] ?? '';
                }
                if ($user === false || $user === '') {
                    $user = $env['DB_USER'] ?? '';
                }
                if ($pass === false || $pass === '') {
                    $pass = $env['DB_PASSWORD'] ?? '';
                }
            }
        }

        if ($dsn === false || $dsn === '' || $user === false || $user === '') {
            throw new RuntimeException('Cannot determine production database connection settings');
        }

        $productionDbName = self::extractDbname($dsn);
        if ($productionDbName === '') {
            throw new RuntimeException('Cannot determine production database name from DSN');
        }

        $maintDsn = self::replaceDbname($dsn, 'postgres');

        $maint = null;
        try {
            $maint = new PDO($maintDsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('Maintenance database unreachable: ' . $e->getMessage(), 0, $e);
        }

        try {
            $maint->exec('SELECT pg_advisory_lock(' . self::LOCK_KEY . ')');

            self::ensureTemplate($maint, $dsn, $user, $pass);

            $testDbName = 'phillip_test_' . getmypid() . '_' . bin2hex(random_bytes(4));

            if (!str_starts_with($testDbName, 'phillip_test_')) {
                throw new RuntimeException('Test database name does not start with phillip_test_');
            }
            if ($testDbName === $productionDbName) {
                throw new RuntimeException('Test database name equals production database name');
            }

            $attempts = 0;
            while (true) {
                try {
                    $maint->exec('CREATE DATABASE "' . $testDbName . '" TEMPLATE phillip_test_template');
                    break;
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'being accessed by other users') !== false && $attempts++ < 10) {
                        usleep(100000);
                        continue;
                    }
                    throw $e;
                }
            }

            self::removeStaleDatabases($maint, getmypid());

        } finally {
            $maint = null;
        }

        $newDsn = self::replaceDbname($dsn, $testDbName);

        putenv('DB_DSN=' . $newDsn);
        putenv('DB_NAME=' . $testDbName);
        putenv('PHILLIP_TESTING=1');
        putenv('PHILLIP_TEST_DATABASE=' . $testDbName);
        putenv('PHILLIP_PRODUCTION_DATABASE=' . $productionDbName);

        $_ENV['DB_DSN'] = $newDsn;
        $_ENV['DB_NAME'] = $testDbName;
        $_ENV['PHILLIP_TESTING'] = '1';
        $_ENV['PHILLIP_TEST_DATABASE'] = $testDbName;
        $_ENV['PHILLIP_PRODUCTION_DATABASE'] = $productionDbName;

        $_SERVER['DB_DSN'] = $newDsn;
        $_SERVER['DB_NAME'] = $testDbName;
        $_SERVER['PHILLIP_TESTING'] = '1';
        $_SERVER['PHILLIP_TEST_DATABASE'] = $testDbName;
        $_SERVER['PHILLIP_PRODUCTION_DATABASE'] = $productionDbName;

        // Diagnostics from tests must not land in the application's own daily log under storage/.
        $logFile = sys_get_temp_dir() . '/phillip-test-' . $testDbName . '.log';
        putenv('LOG_FILE=' . $logFile);
        $_ENV['LOG_FILE'] = $_SERVER['LOG_FILE'] = $logFile;
        register_shutdown_function(function () use ($logFile) {
            if (getenv('PHILLIP_TEST_KEEP') !== '1' && is_file($logFile)) @unlink($logFile);
        });

        self::$testDbName = $testDbName;
        self::$prepared = true;

        // The database must outlive every other shutdown function (a test's own cleanup still needs it), so defer
        // the drop behind a chain of re-registrations: functions registered during shutdown run after the existing ones.
        $drop = function () use ($testDbName, $maintDsn, $user, $pass) {
            if (getenv('PHILLIP_TEST_KEEP') === '1') {
                fwrite(STDERR, $testDbName . "\n");
                return;
            }
            try {
                $pdo = new PDO($maintDsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $pdo->exec('DROP DATABASE IF EXISTS "' . $testDbName . '" WITH (FORCE)');
                $pdo = null;
            } catch (Throwable $e) {
                fwrite(STDERR, 'Failed to drop test database: ' . $e->getMessage() . "\n");
            }
        };
        register_shutdown_function(function () use ($drop) {
            register_shutdown_function(function () use ($drop) {
                register_shutdown_function($drop);
            });
        });
    }

    private static function extractDbname(string $dsn): string
    {
        if (preg_match('/dbname=([^;]+)/', $dsn, $m)) {
            return $m[1];
        }
        return '';
    }

    private static function replaceDbname(string $dsn, string $newDbname): string
    {
        return preg_replace('/dbname=[^;]+/', 'dbname=' . $newDbname, $dsn);
    }

    private static function computeFingerprint(): string
    {
        $parts = [];
        $dbDir = dirname(__DIR__, 2) . '/database';
        $files = scandir($dbDir);
        $sqlFiles = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            if (substr($file, -4) === '.sql') {
                $sqlFiles[] = $file;
            }
        }
        sort($sqlFiles);
        foreach ($sqlFiles as $file) {
            $parts[] = file_get_contents($dbDir . '/' . $file);
        }
        $seedFile = dirname(__DIR__, 2) . '/tests/fixtures/test_seed.sql';
        if (is_file($seedFile)) {
            $parts[] = file_get_contents($seedFile);
        }
        foreach ([dirname(__DIR__, 2) . '/bin/phill-demo', __DIR__ . '/seed_template.php'] as $seeder) {
            if (is_file($seeder)) {
                $parts[] = file_get_contents($seeder);
            }
        }
        // Shipped sheets feed the seeded personas, so editing one (or its avatar list) must rebuild the template.
        foreach (array_merge(glob(dirname(__DIR__, 2) . '/resources/personas/*.json') ?: [], glob(dirname(__DIR__, 2) . '/resources/engineering/*.json') ?: []) as $resource) {
            $parts[] = file_get_contents($resource);
        }
        return sha1(implode('', $parts));
    }

    private static function ensureTemplate(PDO $maint, string $dsn, string $user, string $pass): void
    {
        $fingerprint = self::computeFingerprint();

        $exists = $maint->query("SELECT 1 FROM pg_database WHERE datname = 'phillip_test_template'")->fetchColumn();

        $needsRebuild = true;
        if ($exists) {
            try {
                $templateDsn = self::replaceDbname($dsn, 'phillip_test_template');
                $check = new PDO($templateDsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $meta = $check->query("SELECT value FROM template_meta WHERE key = 'fingerprint'")->fetchColumn();
                $check = null;
                if ($meta !== false && $meta === $fingerprint) {
                    $needsRebuild = false;
                }
            } catch (Throwable $e) {
                // template_meta missing or unreadable -> rebuild
            }
        }

        if (!$needsRebuild) {
            return;
        }

        $maint->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'phillip_test_template' AND pid <> pg_backend_pid()");
        $maint->exec('DROP DATABASE IF EXISTS "phillip_test_template"');
        $maint->exec('CREATE DATABASE "phillip_test_template"');

        $templateDsn = self::replaceDbname($dsn, 'phillip_test_template');
        $tpl = new PDO($templateDsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        try {
            try {
                $tpl->exec('CREATE EXTENSION IF NOT EXISTS vector');
            } catch (Throwable $e) {
                fwrite(STDERR, 'Failed to create vector extension: ' . $e->getMessage());
            }
            $tpl->exec("CREATE TABLE IF NOT EXISTS migrations (name TEXT PRIMARY KEY, applied_at TIMESTAMPTZ DEFAULT NOW())");

            $dbDir = dirname(__DIR__, 2) . '/database';
            $files = scandir($dbDir);
            $sqlFiles = [];
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                if (substr($file, -4) === '.sql') {
                    $sqlFiles[] = $file;
                }
            }
            sort($sqlFiles);

            foreach ($sqlFiles as $file) {
                $name = $file;
                $applied = $tpl->prepare('SELECT 1 FROM migrations WHERE name = ?');
                $applied->execute([$name]);
                if ($applied->fetchColumn()) continue;

                $tpl->beginTransaction();
                try {
                    $sql = file_get_contents($dbDir . '/' . $file);
                    $tpl->exec($sql);
                    $tpl->prepare('INSERT INTO migrations (name) VALUES (?)')->execute([$name]);
                    $tpl->commit();
                } catch (Throwable $e) {
                    $tpl->rollBack();
                    throw $e;
                }
            }

            $seedFile = dirname(__DIR__, 2) . '/tests/fixtures/test_seed.sql';
            if (is_file($seedFile)) {
                $sql = file_get_contents($seedFile);
                if ($sql !== false && trim($sql) !== '') {
                    // Skip exec if the seed file contains only comments (no executable SQL)
                    $activeSql = preg_replace('/--.*$/m', '', $sql);
                    $activeSql = preg_replace('/\s+/', ' ', $activeSql);
                    if (trim($activeSql) !== '') {
                        $tpl->beginTransaction();
                        try {
                            $tpl->exec($sql);
                            $tpl->commit();
                        } catch (Throwable $e) {
                            $tpl->rollBack();
                            throw $e;
                        }
                    }
                }
            }

            // Demo accounts (admin/alice/bob/carol/visitor, password phillip-demo), teams and grants come from the
            // application's own seeder, so there is a single source of truth. It runs against the TEMPLATE only.
            foreach ([dirname(__DIR__, 2) . '/bin/phill-demo', __DIR__ . '/seed_template.php'] as $seeder) {
                if (!is_file($seeder)) continue;
                $child = array_merge(getenv(), ['DB_DSN' => $templateDsn, 'DB_NAME' => 'phillip_test_template', 'DB_USER' => $user, 'DB_PASSWORD' => $pass, 'LOG_MIN_FILE' => '3', 'NO_COLOR' => '1']);
                unset($child['PHILLIP_TESTING'], $child['PHILLIP_PRODUCTION_DATABASE'], $child['PHILLIP_TEST_DATABASE']);
                $process = proc_open([PHP_BINARY, $seeder], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $child);
                if (!is_resource($process)) throw new RuntimeException('Cannot start ' . basename($seeder) . ' for the test template');
                $seedOutput = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                if (proc_close($process) !== 0) throw new RuntimeException('Seeding the test template with ' . basename($seeder) . ' failed: ' . substr($seedOutput, -400));
            }

            try {
                $tpl->exec("CREATE TABLE IF NOT EXISTS template_meta (key TEXT PRIMARY KEY, value TEXT)");
                $tpl->exec("INSERT INTO template_meta (key, value) VALUES ('fingerprint', '" . $fingerprint . "') ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value");
            } catch (Throwable $e) {
                fwrite(STDERR, 'Failed to create template_meta: ' . $e->getMessage() . "\n");
            }

        } finally {
            $tpl = null;
        }
    }

    private static function removeStaleDatabases(PDO $maint, int $currentPid): void
    {
        $dbs = $maint->query("SELECT datname FROM pg_database WHERE datname LIKE 'phillip_test_%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($dbs as $dbName) {
            if (preg_match('/^phillip_test_(\d+)_[0-9a-f]{8}$/', $dbName, $m)) {
                $pid = (int)$m[1];
                if ($pid === $currentPid) continue;
                if (self::isPidAlive($pid)) continue;
                try {
                    $maint->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '" . $dbName . "'");
                    $maint->exec('DROP DATABASE IF EXISTS "' . $dbName . '" WITH (FORCE)');
                } catch (Throwable $e) {
                    fwrite(STDERR, 'Failed to remove stale database ' . $dbName . ': ' . $e->getMessage() . "\n");
                }
            }
        }
    }

    private static function isPidAlive(int $pid): bool
    {
        if ($pid <= 0) return false;
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0) !== false;
        }
        return is_dir('/proc/' . $pid);
    }
}
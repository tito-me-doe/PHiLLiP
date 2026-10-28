<?php
require __DIR__ . '/helpers.php';
$db = Agent\Database::connection();
check($db->query('SELECT 1')->fetchColumn() == 1, 'PostgreSQL connection');
$db->exec('CREATE EXTENSION IF NOT EXISTS vector');
check((float)$db->query("SELECT '[1,0]'::vector <=> '[1,0]'::vector")->fetchColumn() === 0.0, 'Vector extension distance query');
foreach (['pgsql', 'mysql', 'sqlite'] as $driver) check(in_array($driver, PDO::getAvailableDrivers()), 'PDO driver: ' . $driver);
$sqlite = new PDO('sqlite::memory:');
check($sqlite->query('SELECT 1')->fetchColumn() == 1, 'SQLite execution');
check(function_exists('curl_multi_init'), 'Concurrent HTTP transport');

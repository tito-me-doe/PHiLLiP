<?php
/**
 * Borrow a model profile from production for a test that needs the real model.
 *
 * Production is only read (the profile's configuration); the profile is then verified against the live model endpoint and
 * enabled in this test process's own disposable database. Nothing is written to production.
 */
function borrowLiveProfile($name = 'default')
{
    $root = dirname(__DIR__, 2);
    $env = is_readable($root . '/.env') ? parse_ini_file($root . '/.env') : [];
    $user = getenv('DB_USER') ?: ($env['DB_USER'] ?? '');
    $password = getenv('DB_PASSWORD') ?: ($env['DB_PASSWORD'] ?? '');
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . getenv('PHILLIP_PRODUCTION_DATABASE'), (string)getenv('DB_DSN'));
    $production = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $q = $production->prepare('SELECT config FROM profiles WHERE name = ? AND enabled');
    $q->execute([$name]);
    $config = $q->fetchColumn();
    $production = null;
    if (!$config) {
        fwrite(STDERR, "No enabled production profile named '$name' to borrow; pass another profile name.\n");
        exit(2);
    }
    return Agent\Profiles::save($name, json_decode($config, true), true);
}

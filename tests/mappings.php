<?php
require __DIR__ . '/helpers.php';
$file = tempnam(sys_get_temp_dir(), 'phillip-map-');
$source = new PDO('sqlite:' . $file);
$source->exec('CREATE TABLE employees (empid INTEGER PRIMARY KEY, login TEXT, password TEXT, fullname TEXT)');
$source->prepare('INSERT INTO employees VALUES (?, ?, ?, ?)')->execute([42, 'erp-alice', password_hash('mapped-password', PASSWORD_DEFAULT), 'Mapped Alice']);
$config = ['dsn' => 'sqlite:' . $file];
try {
    check(Agent\Mappings::inspect($config) === ['employees'], 'Onboarding inspects existing SQLite tables');
    check(in_array('empid', Agent\Mappings::inspect($config, 'employees')), 'Onboarding inspects existing columns');
    Agent\Installation::$override = ['auth' => 'standalone', 'connection' => $config, 'mappings' => ['users' => ['table' => 'employees', 'writable' => false, 'columns' => ['id' => 'empid', 'username' => 'login', 'password_hash' => 'password', 'display_name' => 'fullname']]]];
    $user = Agent\Identity::authenticate('erp-alice', 'mapped-password');
    check($user['id'] === '42' && $user['display_name'] === 'Mapped Alice', 'Standalone authentication against mapped columns');
    check(is_array(Agent\Identity::teams('42')), 'Mapped users coexist with PHiLLiP-managed teams');
    expectException(function () { Agent\Identity::createUser('new', 'password', 'New'); }, 'read-only');
    Agent\Installation::$override['mappings']['users']['writable'] = true;
    Agent\Installation::$override['mappings']['users']['auto_id'] = true;
    $created = Agent\Identity::createUser('new', 'password', 'New');
    check($created['username'] === 'new', 'Writable mapped user table supports generated IDs');
} finally { Agent\Installation::$override = null; unset($source); unlink($file); }

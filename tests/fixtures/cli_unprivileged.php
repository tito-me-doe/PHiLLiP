#!/usr/bin/env php
<?php
require dirname(__DIR__, 2) . '/bootstrap.php';
$requested = getenv('PHILLIP_TEST_UID');
$uid = $requested === false || $requested === '' ? (posix_geteuid() === 0 ? 33 : posix_geteuid()) : filter_var($requested, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($uid === false) { fwrite(STDERR, "Fixture requires a positive Unix UID\n"); exit(1); }
if (posix_geteuid() === 0 && (!posix_setgid($uid) || !posix_setuid($uid))) {
    fwrite(STDERR, "Fixture could not switch to requested Unix UID\n"); exit(1);
}
if (posix_geteuid() !== $uid) { fwrite(STDERR, "Fixture cannot switch Unix UID from an unprivileged process\n"); exit(1); }
pcntl_exec(dirname(__DIR__, 2) . '/bin/ok-phill', array_slice($argv, 1));
exit(127);

<?php
require __DIR__ . '/helpers.php';
// Browser check of log delivery (header/footer, audiences, browser-only messages). The child process inherits this test's
// disposable database through the environment, so it never touches production.
$python = proc_open(['python3', __DIR__ . '/ui_messages.py'], [['file', '/dev/null', 'r'], STDOUT, STDERR], $pipes, dirname(__DIR__));
check(proc_close($python) === 0, 'Log messages reach the right audience, in the right place, in a real browser');
echo "ALL PASS\n";

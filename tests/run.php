<?php
// Local convenience runner. Tests still bootstrap themselves through tests/helpers.php.
$directory = __DIR__;
$tests = [];
foreach (glob($directory . '/*.php') as $path) {
    $name = basename($path, '.php');
    if ($name !== 'helpers' && $name !== 'run') $tests[$name] = $path;
}
ksort($tests);
$default = ['utilities', 'utilities_adversarial', 'messages', 'tool_arguments', 'request_failures', 'scanner'];

if (isset($argv[1]) && $argv[1] === '--list' && count($argv) === 2) {
    foreach (array_keys($tests) as $name) echo $name . "\n";
    exit(0);
}
$requested = count($argv) > 1 ? array_slice($argv, 1) : $default;
foreach ($requested as $name) {
    if (!is_string($name) || !preg_match('/^[A-Za-z0-9_-]+$/D', $name) || !isset($tests[$name])) {
        fwrite(STDERR, 'Unknown test: ' . (string)$name . ". Use --list to see known top-level PHP tests.\n");
        exit(2);
    }
}

$passed = 0;
$failed = 0;
$interrupted = false;
$child = null;
$childPid = 0;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    call_user_func('pcntl_async_signals', true);
    pcntl_signal(SIGINT, function () use (&$interrupted, &$child, &$childPid) {
        $interrupted = true;
        if ($childPid && function_exists('posix_kill')) @posix_kill(-$childPid, SIGTERM);
        elseif (is_resource($child)) @proc_terminate($child);
    });
}
foreach ($requested as $name) {
    if ($interrupted) break;
    echo "\n== $name ==\n";
    // Isolate the test and its fixture servers in one process group for interrupt cleanup.
    $command = 'exec /usr/bin/setsid ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tests[$name]);
    $pipes = [];
    $child = proc_open($command, [
        ['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']
    ], $pipes);
    if (!is_resource($child)) {
        fwrite(STDERR, "Could not start test process.\n");
        $failed++;
        $child = null;
        continue;
    }
    $childPid = proc_get_status($child)['pid'];
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $exitCode = null;
    do {
        foreach ([1, 2] as $index) {
            $output = stream_get_contents($pipes[$index]);
            if ($output !== '') fwrite($index === 1 ? STDOUT : STDERR, $output);
        }
        $status = proc_get_status($child);
        if (!$status['running']) $exitCode = $status['exitcode'];
        elseif (!$interrupted) usleep(20000);
    } while ($status['running'] && !$interrupted);
    if ($interrupted) {
        if ($childPid && function_exists('posix_kill')) @posix_kill(-$childPid, SIGTERM);
        else @proc_terminate($child);
        $deadline = microtime(true) + 1;
        do {
            $status = proc_get_status($child);
            if (!$status['running']) break;
            usleep(20000);
        } while (microtime(true) < $deadline);
        if (!empty($status['running'])) {
            if ($childPid && function_exists('posix_kill')) @posix_kill(-$childPid, SIGKILL);
            @proc_terminate($child, 9);
        }
    }
    foreach ([1, 2] as $index) {
        $output = stream_get_contents($pipes[$index]);
        if ($output !== '') fwrite($index === 1 ? STDOUT : STDERR, $output);
        fclose($pipes[$index]);
    }
    $closedCode = proc_close($child);
    $child = null;
    $childPid = 0;
    if ($interrupted) break;
    if ($exitCode === null || $exitCode < 0) $exitCode = $closedCode;
    if ($exitCode === 0) { echo "PASS: $name\n"; $passed++; }
    else { fwrite(STDERR, "FAIL: $name (exit $exitCode)\n"); $failed++; }
}
echo "\nTests: $passed passed, $failed failed, " . ($interrupted ? 'interrupted' : 'complete') . ".\n";
exit($interrupted ? 130 : ($failed ? 1 : 0));

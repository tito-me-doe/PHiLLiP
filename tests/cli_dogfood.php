<?php
require __DIR__ . '/helpers.php';

// Explicit live-model smoke test, not a mock and not an alternate model client.
// docker compose exec -T --user 1002:1002 app php tests/cli_dogfood.php [PROFILE]
if (Agent\Runtime::unixIdentity()['root']) throw new RuntimeException('Run this live CLI test as an unprivileged Unix user');
$profile = isset($argv[1]) ? $argv[1] : 'nemotron-vl-work';
$prompt = 'Use Source.read to inspect classes/Agent/Cli.php and classes/Agent/Terminal.php. '
    . 'Find where ok-phill sanitizes its final answer before stdout, and identify the shared method that removes terminal escape sequences. '
    . 'Answer concisely with the two file paths and the method call chain. Do not propose changes or run commands; inspect only.';
$diagnostics = tmpfile();
if (!$diagnostics) throw new RuntimeException('Could not allocate CLI diagnostic capture');
$diagnosticPath = stream_get_meta_data($diagnostics)['uri'];
$command = 'exec env NO_COLOR=1 LOG_MIN_FILE=3 LOG_MIN_DATABASE=3 '
    . escapeshellarg(dirname(__DIR__) . '/bin/ok-phill') . ' --debug -p ' . escapeshellarg($profile)
    . ' -t Source::read ' . escapeshellarg($prompt);
$pipes = [];
try {
    $process = proc_open($command, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', $diagnosticPath, 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($process)) throw new RuntimeException('Could not start ok-phill');
    $answer = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $status = proc_close($process);
    rewind($diagnostics); $stderr = stream_get_contents($diagnostics);
    check($status === 0, 'Live ok-phill delegation completes successfully');
    check(strpos($stderr, 'Using Agent_Tools_Source_read') !== false, 'Live CLI agent actually reads source through the registered tool');
    check(strpos($answer, 'classes/Agent/Cli.php') !== false && strpos($answer, 'classes/Agent/Terminal.php') !== false
        && strpos($answer, 'strip') !== false && strpos($answer, 'stdout') !== false,
        'Live CLI answer identifies the verified final-answer sanitization path');
    check(strpos($answer, '[Debug]') === false && strpos($answer, '[Info]') === false && strpos($answer, "\033") === false,
        'Live tool execution preserves clean final-only stdout');
    check(strpos($stderr, '[Debug] CLI request completed') !== false && strpos($stderr, 'Model request {') === false,
        'Live debug diagnostics stay on stderr without verbose raw model payloads');
} finally { fclose($diagnostics); }

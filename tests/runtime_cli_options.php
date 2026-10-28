<?php
require __DIR__ . '/helpers.php';

$savedArgv = $GLOBALS['argv'];
$savedArgc = $GLOBALS['argc'];
$savedServerArgv = isset($_SERVER['argv']) ? $_SERVER['argv'] : null;
$savedServerArgc = isset($_SERVER['argc']) ? $_SERVER['argc'] : null;

$GLOBALS['argv'] = ['bin/ok-phill', '--debug', 'first', '--verbose', '--', '--debug', 'literal'];
$GLOBALS['argc'] = count($GLOBALS['argv']);
$_SERVER['argv'] = $GLOBALS['argv'];
$_SERVER['argc'] = $GLOBALS['argc'];
check(Agent\Cli::consumeLoggingOptions() === -4, 'Verbose wins when both global logging flags are present');
check($GLOBALS['argv'] === ['bin/ok-phill', 'first', '--', '--debug', 'literal'], 'Global flags are stripped only before the option terminator');
check($GLOBALS['argc'] === 5 && $_SERVER['argv'] === $GLOBALS['argv'] && $_SERVER['argc'] === 5, 'Normalized arguments and counts are shared with server globals');

$GLOBALS['argv'] = ['bin/phill', '--verbose', 'prompt'];
check(Agent\Cli::consumeLoggingOptions() === -4 && $GLOBALS['argv'] === ['bin/phill', 'prompt'], 'Verbose-only invocation is normalized');
$GLOBALS['argv'] = ['bin/phill-scan', '--debug', '--json'];
check(Agent\Cli::consumeLoggingOptions() === -3 && $GLOBALS['argv'] === ['bin/phill-scan', '--json'], 'Debug-only invocation is normalized');
$GLOBALS['argv'] = ['bin/phill', '--', '--verbose'];
check(Agent\Cli::consumeLoggingOptions() === null && $GLOBALS['argv'] === ['bin/phill', '--', '--verbose'], 'A prompt flag after -- remains literal');
$GLOBALS['argv'] = ['bin/phill', 'plain'];
check(Agent\Cli::consumeLoggingOptions() === null && $GLOBALS['argv'] === ['bin/phill', 'plain'], 'Invocations without logging flags retain their arguments');
check(strpos(Agent\Cli::help(), '--verbose') !== false && strpos(Agent\Cli::help(), '--debug') !== false, 'CLI help documents both global logging flags');

$code = '$options = getopt("", array("json", "verbose", "debug")); echo json_encode($options);';
$command = 'exec ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' -- --debug --json';
$pipes = [];
$process = proc_open($command, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
$stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$status = proc_close($process);
$options = json_decode($stdout, true);
check($status === 0 && $stderr === '' && isset($options['json'], $options['debug']), 'Native getopt accepts global flags alongside entrypoint options');

$bootstrap = var_export(dirname(__DIR__) . '/bootstrap.php', true);
$code = 'putenv("LOG_MIN_USER=3"); putenv("LOG_MIN_ADMIN=3"); putenv("LOG_MIN_CONSOLE=3"); putenv("LOG_MIN_FILE=3"); putenv("LOG_MIN_DATABASE=3");'
    . ' require ' . $bootstrap . '; echo json_encode(array("argv" => array_slice($argv, 1), "user" => Agent\\Log::$minUserPriority,'
    . ' "admin" => Agent\\Log::$minAdminPriority, "console" => Agent\\Log::$minConsolePriority,'
    . ' "file" => Agent\\Log::$minFilePriority, "database" => Agent\\Log::$minDatabasePriority));';
$command = 'exec ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' -- --debug prompt -- --verbose';
$pipes = [];
$process = proc_open($command, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
$stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$status = proc_close($process);
$runtime = json_decode($stdout, true);
check($status === 0 && $runtime['argv'] === ['prompt', '--', '--verbose'] && $runtime['user'] === -3
    && $runtime['admin'] === -3 && $runtime['console'] === -3 && $runtime['file'] === 3 && $runtime['database'] === 3,
    'Bootstrap strips global flags and lowers enabled diagnostic audiences without enabling disabled sinks');
check(strpos($stderr, '[Debug] CLI diagnostic priority overridden') !== false, 'Bootstrap reports the active debug override on stderr');

$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$listener) throw new RuntimeException('Could not allocate a loopback fixture port: ' . $error);
$address = stream_socket_get_name($listener, false);
fclose($listener);
$fixture = __DIR__ . '/fixtures/cli_engine.php';
$serverCommand = 'exec ' . escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($address) . ' ' . escapeshellarg($fixture);
$server = proc_open($serverCommand, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $serverPipes);
if (!is_resource($server)) throw new RuntimeException('Could not start CLI fixture server');
try {
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $socket = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
        if ($socket) { fclose($socket); $ready = true; break; }
        usleep(20000);
    }
    if (!$ready) throw new RuntimeException('CLI fixture server did not become ready');
    $profile = json_encode(['url' => 'http://' . $address . '/v1/chat/completions', 'model' => 'inspect', 'max_retries' => 0]);
    foreach (['--debug', '--verbose'] as $flag) {
        $prompt = 'fixture prompt ' . substr($flag, 2);
        $command = 'exec env LOG_MIN_FILE=3 LOG_MIN_DATABASE=3 NO_COLOR=1 ' . escapeshellarg(dirname(__DIR__) . '/bin/ok-phill')
            . ' ' . escapeshellarg($flag) . ' -p ' . escapeshellarg($profile) . ' ' . escapeshellarg($prompt);
        $pipes = [];
        $process = proc_open($command, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $status = proc_close($process);
        $answer = json_decode($stdout, true);
        check($status === 0 && is_array($answer) && $answer['messages'][0]['content'] === $prompt
            && strpos($stdout, '[Debug]') === false && strpos($stdout, '[Verbose]') === false,
            $flag . ' runs ok-phill against a local fixture with answer-only stdout');
        check(strpos($stderr, '[Debug] CLI options parsed') !== false && strpos($stderr, '[Debug] CLI request completed') !== false,
            $flag . ' writes CLI checkpoints to stderr');
    }
} finally { proc_terminate($server); proc_close($server); }

$GLOBALS['argv'] = $savedArgv;
$GLOBALS['argc'] = $savedArgc;
if ($savedServerArgv === null) unset($_SERVER['argv']); else $_SERVER['argv'] = $savedServerArgv;
if ($savedServerArgc === null) unset($_SERVER['argc']); else $_SERVER['argc'] = $savedServerArgc;

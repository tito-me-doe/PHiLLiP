<?php
namespace Agent\Tools;

use Agent\WorkspaceAccess;

/** Fixed verification commands for local CLI agents: repository unit tests and syntax checks. No arbitrary command execution. */
class Verify
{
    const MAX_TESTS = 10;
    const TEST_TIMEOUT = 120;
    const OUTPUT_BYTES = 4000;

    /**
     * Run PHP unit test scripts from the workspace tests/ directory and report pass/fail with exit code and the tail of their output. Tests run sequentially, each limited to 120 seconds. Test scripts execute code with the Unix user's authority.
     * @param array $list Up to 10 test names, e.g. ["agents", "tests/context.php"]; live and ui_ tests are refused
     */
    public static function testUnits(array $list)
    {
        self::cli();
        if (!$list || count($list) > self::MAX_TESTS) throw new \InvalidArgumentException('Provide 1 to ' . self::MAX_TESTS . ' test names');
        $results = [];
        foreach ($list as $name) {
            if (!is_string($name)) throw new \InvalidArgumentException('Test names must be strings');
            $path = preg_match('#^tests/#', $name) ? $name : 'tests/' . $name;
            if (substr($path, -4) !== '.php') $path .= '.php';
            if (!preg_match('#^tests/[A-Za-z0-9_]+\.php$#D', $path) || preg_match('#^tests/(ui_|.*live)#', $path)) throw new \InvalidArgumentException("Not an allowed unit test: $name");
            $file = WorkspaceAccess::path($path);
            if (!is_file($file)) { $results[] = ['test' => $path, 'passed' => false, 'exit' => null, 'output' => 'No such test file']; continue; }
            // Tests use fixed ports and shared fixtures, so concurrent callers (parallel agents) must take turns.
            $lock = fopen(sys_get_temp_dir() . '/phillip-testunits.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('Cannot take the unit test lock');
            try { $results[] = array_merge(['test' => $path], self::run([PHP_BINARY, $file], self::TEST_TIMEOUT)); }
            finally { flock($lock, LOCK_UN); fclose($lock); }
        }
        return $results;
    }

    /**
     * Syntax-check one workspace file without executing it: .php (php -l), .js (node --check) or .py (Python ast parse).
     * @param string $file Workspace-relative file
     */
    public static function lint(string $file)
    {
        self::cli();
        $path = WorkspaceAccess::path($file);
        if (!is_file($path)) throw new \InvalidArgumentException('Lint requires an existing regular file');
        switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            case 'php': $command = [PHP_BINARY, '-l', $path]; break;
            case 'js': $command = ['node', '--check', $path]; break;
            case 'py': $command = ['python3', '-I', '-c', 'import ast,sys; ast.parse(open(sys.argv[1], encoding="utf-8").read(), sys.argv[1])', $path]; break;
            default:
                if (preg_match('/^#[^\r\n]*\bphp\b/', file_get_contents($path, false, null, 0, 1024))) {
                    $command = [PHP_BINARY, '-l', $path];
                    break;
                }
                throw new \InvalidArgumentException('Lint supports .php, .js and .py files');
        }
        return array_merge(['file' => $file], self::run($command, 30));
    }

    private static function cli()
    {
        if (PHP_SAPI !== 'cli' || \Agent\ToolContext::get('frontend')) throw new \RuntimeException('Verify is only available to local CLI agents');
    }

    private static function run(array $command, $timeout)
    {
        if (is_executable('/usr/bin/setsid')) { array_unshift($command, '/usr/bin/setsid'); }
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, WorkspaceAccess::root());
        if (!is_resource($process)) throw new \RuntimeException('Cannot start ' . $command[0]);
        foreach ([1, 2] as $i) stream_set_blocking($pipes[$i], false);
        $output = ''; $deadline = microtime(true) + $timeout; $timedOut = false; $exit = null;
        while (true) {
            $read = [$pipes[1], $pipes[2]]; $w = $e = null;
            if (stream_select($read, $w, $e, 0, 200000)) foreach ($read as $stream) $output .= fread($stream, 65536);
            if (strlen($output) > 1048576) $output = substr($output, -1048576);
            $state = proc_get_status($process);
            if (!$state['running']) { $exit = $state['exitcode']; break; }
            if (microtime(true) > $deadline) { $timedOut = true; if (!(function_exists('posix_kill') && @posix_kill(-$state['pid'], 9))) proc_terminate($process, 9); break; }
        }
        foreach ([1, 2] as $i) { $output .= (string)stream_get_contents($pipes[$i]); fclose($pipes[$i]); }
        proc_close($process);
        // Diagnostic log lines drown out the assertion that failed; keep PASS/FAIL and error lines.
        $output = implode("\n", array_filter(explode("\n", $output), function ($line) { return !preg_match('/^\[(Debug|Info|Success)\]/', $line); }));
        $output = strlen($output) > self::OUTPUT_BYTES ? '[truncated] ' . substr($output, -self::OUTPUT_BYTES) : $output;
        return ['passed' => !$timedOut && $exit === 0, 'exit' => $exit, 'output' => $timedOut ? "Timed out after {$timeout}s\n" . $output : $output];
    }
}

<?php
namespace Agent;

class BenchmarkCli
{
    public static function run(array $args)
    {
        Log::configure(['cli_stream' => 'stderr']);
        try {
            $options = self::options($args);
            if (isset($options['help'])) { Terminal::write("Usage: phill-bench -p PROFILE -b TEST [--json] [--debug|--verbose]\n\nRun one saved test against one saved profile. Tests/profile names or IDs are accepted.\n--list lists tests without inference. Results are saved, including failed questions.\nExit 0: execution completed (inspect score); 1: execution failed; 2: invalid usage; 130: interrupted.\n", 'stdout'); return 0; }
            if (isset($options['list'])) {
                Terminal::write(json_encode(Database::rows('SELECT t.*,COUNT(q.id) AS questions FROM benchmark_tests t LEFT JOIN benchmark_questions q ON q.testid=t.id GROUP BY t.id ORDER BY t.id'), JSON_PRETTY_PRINT) . "\n", 'stdout'); return 0;
            }
            if (!isset($options['profile'], $options['test'])) throw new \InvalidArgumentException('Use phill-bench -p PROFILE -b TEST, or --help');
            $stop = false;
            if (function_exists('pcntl_signal')) foreach ([SIGINT, SIGTERM] as $signal) pcntl_signal($signal, function () use (&$stop) { $stop = true; });
            $job = Benchmarks::enqueue($options['profile'], $options['test'], Cli::identity()['owner'], true);
            $job = Benchmarks::execute($job['id'], function () use (&$stop) { if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch(); return $stop; });
            $summary = Benchmarks::summary($job);
            if (isset($options['json'])) Terminal::write(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 'stdout');
            else {
                Terminal::write(sprintf("Benchmark #%d: %s / %s — %s\nScore: %s  Recorded: %d/%d  Seconds: %.3f\n", $job['id'], Terminal::strip($summary['profile']), Terminal::strip($summary['test']), $job['status'], $summary['score'] === null ? 'unavailable' : sprintf('%.2f%%', $summary['score'] * 100), $summary['recorded'], $summary['questions'], $summary['seconds']), 'stdout');
                foreach ($summary['results'] as $row) Terminal::write(sprintf("  %2d  %-9s score=%.2f seconds=%.3f thread=%s%s\n", $row['position'], $row['status'], $row['score'], $row['elapsed_seconds'], $row['threadid'], $row['error'] ? ' error=' . Terminal::strip($row['error']) : ''), 'stdout');
            }
            if ($job['status'] === 'completed') Log::writeSuccess('Benchmark completed', ['benchmark_id' => $job['id'], 'score' => $job['score']]);
            else Log::writeError('Benchmark ended without completion', ['benchmark_id' => $job['id'], 'status' => $job['status']]);
            return $stop ? 130 : ($job['status'] === 'completed' ? 0 : 1);
        } catch (\Throwable $e) { Log::writeError($e->getMessage()); Log::trace('Benchmark CLI exception', $e); return $e instanceof \InvalidArgumentException ? 2 : 1; }
    }

    public static function options(array $args)
    {
        $options = []; $names = ['-p' => 'profile', '--profile' => 'profile', '-b' => 'test', '--benchmark' => 'test', '--test' => 'test'];
        for ($i = 0; $i < count($args); $i++) {
            $parts = explode('=', $args[$i], 2); $name = $parts[0];
            if (in_array($name, ['--help', '-h', '--list', '--json'], true)) {
                if (isset($parts[1])) throw new \InvalidArgumentException('Flag does not accept a value: ' . $name);
                $options[$name === '-h' ? 'help' : substr($name, 2)] = true; continue;
            }
            if (!isset($names[$name])) throw new \InvalidArgumentException('Unknown benchmark option: ' . $name);
            $key = $names[$name];
            if (isset($options[$key])) throw new \InvalidArgumentException('Duplicate benchmark option: ' . $name);
            $value = isset($parts[1]) ? $parts[1] : (isset($args[$i + 1]) ? $args[++$i] : '');
            if (trim($value) === '' || $value[0] === '-') throw new \InvalidArgumentException('Missing value for ' . $name);
            $options[$key] = trim($value);
        }
        if (isset($options['list']) && (isset($options['profile']) || isset($options['test']))) throw new \InvalidArgumentException('--list cannot run a benchmark');
        return $options;
    }
}

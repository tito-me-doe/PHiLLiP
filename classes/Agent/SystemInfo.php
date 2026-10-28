<?php
namespace Agent;

/** Bounded, read-only health facts; never environment dumps, credentials, or process arguments. */
class SystemInfo
{
    public static function collect($config = null)
    {
        $memory = [];
        if (is_readable('/proc/meminfo')) foreach (file('/proc/meminfo') as $line) if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)/', $line, $m)) $memory[$m[1] . '_MiB'] = round((int)$m[2] / 1024);
        $result = ['host' => gethostname(), 'os' => php_uname('s') . ' ' . php_uname('r'), 'php' => PHP_VERSION,
            'load' => function_exists('sys_getloadavg') ? sys_getloadavg() : null, 'memory' => $memory,
            'disk_free_GiB' => round(disk_free_space(Runtime::root()) / 1073741824, 2), 'checks' => []];
        if (is_readable('/proc/uptime')) $result['uptime_hours'] = round((float)file_get_contents('/proc/uptime') / 3600, 1);
        try { Database::connection()->query('SELECT 1')->fetchColumn(); $result['checks']['database'] = ['status' => 'reachable']; }
        catch (\Throwable $e) { $result['checks']['database'] = ['status' => 'unavailable', 'note' => 'Not reachable from this process. This does not establish that the service is stopped: it may run on another network/container. PostgreSQL enables persisted threads and memory; JSON profiles still support chat']; }
        if ($config) {
            $url = preg_replace('~/(chat/completions|responses)/?$~', '/models', $config['url']);
            $headers = isset($config['headers']) ? $config['headers'] : (isset($config['head']) ? $config['head'] : []);
            $result['checks']['model_registry'] = self::probe($url, $headers);
        } else $result['checks']['model_registry'] = ['status' => 'unconfigured'];
        foreach (['searxng', 'camoufox'] as $service) {
            try {
                $settings = Config::service($service);
                $url = rtrim($settings['url'], '/') . ($service === 'camoufox' ? '/health' : '/');
                $result['checks'][$service] = self::probe($url);
            } catch (\Throwable $e) { $result['checks'][$service] = ['status' => 'unconfigured']; }
        }
        $result['systemd'] = 'not available in this environment';
        if (is_dir('/run/systemd/system') && is_executable('/usr/bin/systemctl') && is_executable('/usr/bin/timeout')) {
            $pipes = []; $process = proc_open('exec /usr/bin/timeout 3s /usr/bin/systemctl --failed --plain --no-legend --no-pager', [['file', '/dev/null', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']], $pipes);
            if (is_resource($process)) { $output = stream_get_contents($pipes[1], 16384); fclose($pipes[1]); $code = proc_close($process); $result['systemd'] = $code === 0 ? ['failed_units' => trim($output)] : 'health check unavailable'; }
        }
        return $result;
    }
    private static function probe($url, array $headers = [])
    {
        $response = Http::request($url, null, $headers, 3);
        return ['status' => $response['status'] >= 200 && $response['status'] < 400 ? 'reachable' : 'unavailable', 'http_status' => $response['status']];
    }
}

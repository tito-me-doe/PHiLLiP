<?php
/** Disposable PostgreSQL schema, HTTP processes, and cookie jars shared by browser/API suites. */
class WebFixture
{
    public $base;
    public $tokens = [];
    private $db;
    private $schema;
    private $searchPath;
    private $environment = [];
    private $processes = [];
    private $cookies = [];
    private $log;

    public function __construct()
    {
        $this->db = Agent\Database::connection();
        $this->schema = 'web_fixture_' . bin2hex(random_bytes(6));
        $this->searchPath = $this->db->query('SHOW search_path')->fetchColumn();
        $this->log = tmpfile();
        foreach (['PGOPTIONS', 'PHP_CLI_SERVER_WORKERS', 'APP_BASE_PATH'] as $key) {
            $this->environment[$key] = getenv($key);
        }
        $this->db->exec('CREATE SCHEMA ' . $this->schema);
        $this->db->exec('SET search_path TO ' . $this->schema . ',public');
        putenv('PGOPTIONS=-c search_path=' . $this->schema . ',public');
        putenv('PHP_CLI_SERVER_WORKERS');
        putenv('APP_BASE_PATH=');
        foreach (glob(dirname(__DIR__, 2) . '/database/*.sql') as $file) {
            $this->db->exec(file_get_contents($file));
        }
    }

    public static function address()
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$socket) {
            throw new RuntimeException('Cannot allocate fixture port: ' . $error);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        return $address;
    }

    public function launch(array $args, $inherit = false)
    {
        $process = proc_open(
            'exec ' . implode(' ', array_map('escapeshellarg', $args)),
            [['file', '/dev/null', 'r'], $inherit ? STDOUT : $this->log, $inherit ? STDERR : $this->log],
            $pipes,
            dirname(__DIR__, 2),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start fixture process');
        }
        $this->processes[] = $process;
        return $process;
    }

    public function server($script, $app = false)
    {
        $address = self::address();
        $args = [PHP_BINARY, '-S', $address];
        if ($app) {
            $args = array_merge($args, ['-t', dirname(__DIR__, 2) . '/public']);
        }
        $this->launch(array_merge($args, [$script]));
        for ($i = 0; $i < 100; $i++) {
            $socket = @stream_socket_client('tcp://' . $address, $errno, $error, 0.02);
            if ($socket) {
                fclose($socket);
                if ($app) {
                    $this->base = 'http://' . $address;
                }
                return 'http://' . $address;
            }
            usleep(20000);
        }
        throw new RuntimeException('Fixture server did not start');
    }

    public function request($path, $body = null, $client = 'admin', $form = false, $csrf = null)
    {
        if (!isset($this->cookies[$client])) {
            $this->cookies[$client] = tmpfile();
        }
        $cookie = stream_get_meta_data($this->cookies[$client])['uri'];
        $curl = curl_init($this->base . $path);
        $headers = [];
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_COOKIEFILE => $cookie,
            CURLOPT_COOKIEJAR => $cookie,
            CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$headers) {
                $headers[] = $line;
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $form ? http_build_query($body) : json_encode($body),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: ' . ($form ? 'application/x-www-form-urlencoded' : 'application/json'),
                    'X-CSRF-Token: ' . ($csrf === null ? (isset($this->tokens[$client]) ? $this->tokens[$client] : '') : $csrf),
                ],
            ]);
        }
        $text = curl_exec($curl);
        if ($text === false) {
            throw new RuntimeException(curl_error($curl));
        }
        return [
            'status' => curl_getinfo($curl, CURLINFO_HTTP_CODE),
            'text' => $text,
            'json' => json_decode($text, true),
            'headers' => $headers,
        ];
    }

    public function login($username, $password, $client = 'admin')
    {
        $state = $this->request('/api/state', null, $client)['json'];
        $reply = $this->request('/api/login', ['username' => $username, 'password' => $password], $client, false, $state['csrf']);
        if ($reply['status'] !== 200) {
            throw new RuntimeException('Fixture login failed: ' . $username);
        }
        $this->tokens[$client] = $reply['json']['csrf'];
        return $reply['json'];
    }

    public function diagnostics()
    {
        rewind($this->log);
        return substr(stream_get_contents($this->log), -12000);
    }

    public function close()
    {
        foreach (array_reverse($this->processes) as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        foreach ($this->cookies as $cookie) {
            fclose($cookie);
        }
        fclose($this->log);
        foreach ($this->environment as $key => $value) {
            $value === false ? putenv($key) : putenv($key . '=' . $value);
        }
        $this->db->exec('SET search_path TO ' . $this->searchPath);
        $this->db->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
    }
}

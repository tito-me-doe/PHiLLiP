<?php
namespace Agent;

/** Common messages, independent sinks, scoped overrides, and safe transport adapters. */
class Log
{
    const VERBOSE = -4;
    const DEBUG = -3;
    const INFO = -2;
    const WARNING = -1;
    const SUCCESS = 0;
    const ERROR = 1;
    const FATAL = 2;
    const OFF = 3;
    const SENSITIVE_KEYS = '/password|passwd|secret|token|authorization|cookie|session.?id|phpsessid|api.?key|csrf/i';
    const LABELS = [-4 => 'Verbose', -3 => 'Debug', -2 => 'Info', -1 => 'Warning', 0 => 'Success', 1 => 'Error', 2 => 'Fatal'];
    public static $minUserPriority = self::WARNING;
    public static $minAdminPriority = self::INFO;
    public static $minConsolePriority = self::DEBUG;
    public static $minFilePriority = self::INFO;
    public static $minDatabasePriority = self::OFF;
    public static $file;
    private static $records = [];
    private static $routes = [];
    private static $sequence = 0;
    private static $requestId;
    private static $busy = false;
    private static $headerIds = [];
    private static $settings = ['cli_stream' => 'stderr', 'http_status' => true];

    public static function initialize()
    {
        self::$requestId = bin2hex(random_bytes(8));
        $unix = Runtime::isCli() ? Runtime::unixIdentity() : ['uid' => function_exists('posix_geteuid') ? posix_geteuid() : 'web'];
        self::$file = getenv('LOG_FILE') ?: Runtime::root() . '/storage/phillip-' . $unix['uid'] . '-' . gmdate('Ymd') . '.log';
        foreach (['USER' => 'minUserPriority', 'ADMIN' => 'minAdminPriority', 'CONSOLE' => 'minConsolePriority', 'FILE' => 'minFilePriority', 'DATABASE' => 'minDatabasePriority'] as $key => $property) {
            $value = getenv('LOG_MIN_' . $key);
            if ($value !== false) {
                try { self::$$property = self::priority($value); }
                catch (\Throwable $e) { self::writeWarning('Invalid LOG_MIN_%s threshold; keeping the default', $key); }
            }
        }
    }
    public static function priority($value)
    {
        if (is_string($value) && !is_numeric($value)) {
            $names = array_flip(array_map('strtolower', self::LABELS)); $names['warn'] = self::WARNING; $names['off'] = self::OFF;
            $key = strtolower(trim($value));
            if (!isset($names[$key])) throw new \InvalidArgumentException('Unknown log priority: ' . $value);
            return $names[$key];
        }
        if (!is_numeric($value) || (int)$value != $value || $value < self::VERBOSE || $value > self::OFF) throw new \InvalidArgumentException('Log priority must be -4 through 2, or 3 to disable a sink');
        return (int)$value;
    }
    public static function configure(array $settings)
    {
        $map = ['user' => 'minUserPriority', 'admin' => 'minAdminPriority', 'console' => 'minConsolePriority', 'file' => 'minFilePriority', 'database' => 'minDatabasePriority'];
        foreach ($settings as $key => $value) {
            if (isset($map[$key])) { $property = $map[$key]; self::$$property = self::priority($value); }
            elseif ($key === 'path') self::$file = $value;
            elseif ($key === 'cli_stream' && in_array($value, ['stderr', 'stdout', 'split'], true)) self::$settings[$key] = $value;
            elseif ($key === 'http_status' && is_bool($value)) self::$settings[$key] = $value;
            else throw new \InvalidArgumentException('Unknown or invalid logging setting: ' . $key);
        }
    }
    public static function with(array $settings, $callback)
    {
        $saved = [self::$minUserPriority, self::$minAdminPriority, self::$minConsolePriority, self::$minFilePriority, self::$minDatabasePriority, self::$file, self::$settings];
        try { self::configure($settings); return call_user_func($callback); }
        finally { list(self::$minUserPriority, self::$minAdminPriority, self::$minConsolePriority, self::$minFilePriority, self::$minDatabasePriority, self::$file, self::$settings) = $saved; }
    }
    public static function writeVerbose(...$args) { return self::write(self::VERBOSE, ...$args); }
    public static function writeVerbos(...$args) { return self::writeVerbose(...$args); }
    public static function writeDebug(...$args) { return self::write(self::DEBUG, ...$args); }
    public static function writeInfo(...$args) { return self::write(self::INFO, ...$args); }
    public static function writeWarning(...$args) { return self::write(self::WARNING, ...$args); }
    public static function writeSuccess(...$args) { return self::write(self::SUCCESS, ...$args); }
    public static function writeError(...$args) { return self::write(self::ERROR, ...$args); }
    public static function writeFatal(...$args) { return self::write(self::FATAL, ...$args); }

    /** Raw diagnostic traces, never ordinary user messages; disabled unless a trace sink is enabled. */
    public static function trace(...$values)
    {
        if (min(self::$minConsolePriority, self::$minFilePriority, self::$minDatabasePriority) > self::VERBOSE) return null;
        foreach ($values as &$value) if ($value instanceof \Throwable) {
            $frames = [];
            foreach (array_slice($value->getTrace(), 0, 20) as $frame) $frames[] = array_intersect_key($frame, ['file' => true, 'line' => true, 'class' => true, 'function' => true, 'type' => true]);
            $value = ['exception' => get_class($value), 'message' => $value->getMessage(), 'file' => $value->getFile(), 'line' => $value->getLine(), 'trace' => $frames];
        }
        unset($value);
        return self::with(['user' => self::OFF, 'admin' => self::OFF, 'http_status' => false], function () use ($values) { return self::writeVerbose(...$values); });
    }

    /** Carry already-logged form feedback across a redirect without writing the same event twice. */
    public static function flash($record)
    {
        if (!is_array($record) || !isset(self::$routes[$record['id']])) return;
        $own = session_status() !== PHP_SESSION_ACTIVE;
        if ($own && (session_id() === '' || headers_sent() || !@session_start())) return;
        try {
            $_SESSION['phillip_messages'][] = ['record' => $record, 'routes' => self::$routes[$record['id']]];
            $_SESSION['phillip_messages'] = array_slice($_SESSION['phillip_messages'], -10);
        } finally { if ($own) session_write_close(); }
    }
    public static function restoreFlash()
    {
        if (empty($_SESSION['phillip_messages'])) return;
        $own = session_status() !== PHP_SESSION_ACTIVE;
        if ($own && (session_id() === '' || headers_sent() || !@session_start())) return;
        try { $messages = isset($_SESSION['phillip_messages']) ? $_SESSION['phillip_messages'] : []; unset($_SESSION['phillip_messages']); }
        finally { if ($own) session_write_close(); }
        $existing = array_flip(array_column(self::$records, 'id'));
        foreach ($messages as $message) if (!isset($existing[$message['record']['id']])) {
            self::$records[] = $message['record']; self::$routes[$message['record']['id']] = $message['routes'];
        }
    }

    public static function write($priority, ...$message)
    {
        if (self::$busy) return null;
        self::$busy = true;
        try {
            try { $priority = self::priority($priority); } catch (\Throwable $e) { $priority = self::ERROR; }
            if ($priority === self::OFF) $priority = self::ERROR;
            $record = ['id' => self::$requestId . '-' . ++self::$sequence, 'timestamp' => gmdate('c'), 'priority' => $priority,
                'label' => self::LABELS[$priority], 'message' => self::format(...$message)];
            if (ToolContext::get('run_id')) $record['scope'] = ['thread_id' => ToolContext::get('thread_id'), 'run_id' => ToolContext::get('run_id')];
            self::$routes[$record['id']] = ['user' => $priority >= self::$minUserPriority, 'admin' => $priority >= self::$minAdminPriority, 'console' => $priority >= self::$minConsolePriority];
            self::$records[] = $record;
            if (count(self::$records) > 500) { $oldRecord = array_shift(self::$records); unset(self::$routes[$oldRecord['id']]); }
            if (ToolContext::get('run_id') && array_filter(self::$routes[$record['id']])) {
                try { Run::event(ToolContext::get('run_id'), ['type' => 'log.message', 'data' => ['record' => $record, 'routes' => self::$routes[$record['id']]]]); }
                catch (\Throwable $e) { error_log('PHiLLiP: workflow message delivery unavailable'); }
            }
            if ($priority >= self::$minFilePriority && self::$file) {
                $old = umask(0077);
                try {
                    if (@file_put_contents(self::$file, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX) === false) error_log('PHiLLiP: log file is not writable; configure LOG_FILE');
                } finally { umask($old); }
            }
            if ($priority >= self::$minDatabasePriority) {
                try { Database::execute('INSERT INTO logs (priority, raw) VALUES (?, ?::jsonb)', [$priority, json_encode($record)]); }
                catch (\Throwable $e) { error_log('PHiLLiP: database logging unavailable; run migrations or disable this sink'); }
            }
            if (Runtime::isCli()) {
                $identity = Runtime::identity(); $unix = Runtime::unixIdentity();
                $threshold = min(self::$minConsolePriority, self::$minUserPriority, $unix['root'] || ($identity && $identity['is_admin']) ? self::$minAdminPriority : self::OFF);
                if ($priority >= $threshold) {
                    $stream = self::$settings['cli_stream'] === 'split' ? ($priority > 0 ? 'stderr' : 'stdout') : self::$settings['cli_stream'];
                    $styles = [-4 => 'dim', -3 => 'cyan', -2 => '', -1 => 'yellow', 0 => 'green', 1 => 'red', 2 => 'bold bright-red'];
                    Terminal::write(Terminal::format('[' . $record['label'] . '] ' . $record['message'], $styles[$priority], $stream) . "\n", $stream);
                }
            } elseif (!headers_sent()) {
                $status = http_response_code();
                if (self::$settings['http_status'] && $priority >= self::ERROR && (!$status || $status < ($priority === self::FATAL ? 500 : 400))) http_response_code($priority === self::FATAL ? 500 : 400);
                self::httpHeaders();
            }
            return $record;
        } catch (\Throwable $e) {
            // Logging must never replace the original operation's result or exception.
            error_log('PHiLLiP: a message could not be delivered (' . get_class($e) . ')');
            return null;
        } finally { self::$busy = false; }
    }
    public static function format(...$values)
    {
        $seen = new \SplObjectStorage();
        $values = array_map(function ($value) use ($seen) { return self::normalize($value, 0, $seen); }, $values);
        $strings = array_map(function ($value) { return is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }, $values);
        if (count($strings) > 1 && is_string($values[0]) && preg_match('/%(?:\d+\$)?[-+0 #\x27]*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/', $values[0])) {
            try {
                $arguments = array_map(function ($value) { return is_array($value) || is_object($value) ? json_encode($value) : $value; }, array_slice($values, 1));
                $formatted = @vsprintf($values[0], $arguments);
                if ($formatted !== false) {
                    preg_match_all('/%%|%(?:(\d+)\$)?[-+0 #\x27]*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/', $values[0], $tokens, PREG_SET_ORDER);
                    $used = []; $next = 0;
                    foreach ($tokens as $token) if ($token[0] !== '%%') $used[isset($token[1]) && $token[1] !== '' ? (int)$token[1] : ++$next] = true;
                    foreach ($strings as $index => $string) if ($index > 0 && !isset($used[$index])) $formatted .= ' ' . $string;
                    return mb_substr(Terminal::strip($formatted), 0, 32768);
                }
            } catch (\Throwable $e) { /* Malformed formatting remains useful as ordinary arguments. */ }
        }
        return mb_substr(Terminal::strip(implode(' ', $strings)), 0, 32768);
    }
    private static function isSensitiveField($key, $value)
    {
        $harmless = ['max_tokens', 'max_completion_tokens', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'cached_tokens', 'reasoning_tokens', 'token_count'];
        if (!in_array($key, $harmless, true)) return (bool)preg_match(self::SENSITIVE_KEYS, $key);
        if (is_int($value)) return $value < 0;
        if (is_float($value)) return !is_finite($value) || $value < 0 || floor($value) !== $value;
        if (is_string($value)) return !preg_match('/^[0-9]+$/D', $value) || !is_finite((float)$value);
        return true;
    }
    private static function normalize($value, $depth, $seen)
    {
        if ($depth > 8) return '[depth limit]';
        if (is_string($value)) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            if (in_array(substr(ltrim($value), 0, 1), ['{', '['], true)) {
                $tmp = strlen($value) <= 1048576 ? json_decode($value, true, 64) : null;
                if (is_array($tmp) && $tmp) $value = json_encode(self::normalize($tmp, $depth + 1, $seen), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                elseif (!is_array($tmp) && (strlen($value) > 1048576 || preg_match(self::SENSITIVE_KEYS, $value))) $value = '[unparsed or oversized JSON trace]';
                unset($tmp);
            }
            $value = preg_replace_callback('/^([ \t]*([A-Za-z0-9_-]+)[ \t]*:[ \t]*).*$/m', function ($match) {
                return self::isSensitiveField($match[2], null) ? $match[1] . '[redacted]' : $match[0];
            }, $value);
            $value = preg_replace('~(https?://)[^/\s@]+@~i', '$1[redacted]@', $value);
            return preg_replace_callback('/([?&])([^\s=&#]+)=([^\s&#"\x27]*)/', function ($match) {
                return self::isSensitiveField(rawurldecode($match[2]), null) ? $match[1] . $match[2] . '=[redacted]' : $match[0];
            }, $value);
        }
        if (is_float($value) && !is_finite($value)) return (string)$value;
        if (is_resource($value)) return '[resource:' . get_resource_type($value) . ']';
        if ($value instanceof \Throwable) return ['exception' => get_class($value), 'message' => self::normalize($value->getMessage(), $depth + 1, $seen), 'code' => $value->getCode()];
        if (is_object($value)) {
            if ($seen->contains($value)) return '[circular:' . get_class($value) . ']';
            $seen->attach($value);
            try {
                if ($value instanceof \DateTimeInterface) return $value->format('c');
                $data = $value instanceof \JsonSerializable ? $value->jsonSerialize() : get_object_vars($value);
                return ['class' => get_class($value), 'value' => self::normalize($data, $depth + 1, $seen)];
            } catch (\Throwable $e) { return '[unprintable:' . get_class($value) . ']'; }
        }
        if (is_array($value)) {
            $result = []; $count = 0;
            foreach ($value as $key => $item) {
                if (++$count > 200) { $result['...'] = '[item limit]'; break; }
                $result[$key] = is_string($key) && self::isSensitiveField($key, $item) ? '[redacted]' : self::normalize($item, $depth + 1, $seen);
            }
            return $result;
        }
        return $value;
    }
    public static function audience()
    {
        $user = Runtime::identity(); $admin = $user && !empty($user['is_admin']);
        $developerIds = array_filter(explode(',', getenv('APP_DEVELOPER_IDS') ?: ''));
        $developer = $user && (!empty($user['is_developer']) || in_array((string)$user['id'], $developerIds, true));
        return ['visible' => $admin ? min(self::$minUserPriority, self::$minAdminPriority) : self::$minUserPriority,
            'console' => $admin || $developer ? self::$minConsolePriority : self::OFF, 'admin' => (bool)$admin, 'developer' => (bool)$developer];
    }
    public static function browserMessages()
    {
        if (!self::$records) return [];
        $audience = self::audience(); $result = [];
        foreach (self::$records as $record) {
            $route = self::$routes[$record['id']];
            $visible = $route['user'] || ($audience['admin'] && $route['admin']);
            $console = ($audience['admin'] || $audience['developer']) && $route['console'];
            if ($visible || $console) $result[] = $record + ['visible' => $visible, 'console' => $console];
        }
        return $result;
    }
    public static function delivery(array $data, $viewerId)
    {
        $current = Runtime::identity();
        $user = $current && (string)$current['id'] === (string)$viewerId ? $current : Identity::user(['id' => $viewerId]); $admin = $user && $user['is_admin'];
        $developer = $user && (!empty($user['is_developer']) || in_array((string)$user['id'], array_filter(explode(',', getenv('APP_DEVELOPER_IDS') ?: '')), true));
        $route = $data['routes'];
        $visible = $route['user'] || ($admin && $route['admin']); $console = ($admin || $developer) && $route['console'];
        return $visible || $console ? $data['record'] + ['visible' => (bool)$visible, 'console' => (bool)$console] : null;
    }
    public static function restoreThread($threadId)
    {
        $q = Database::connection()->prepare("SELECT data FROM events e JOIN runs r ON r.id=e.run_id WHERE r.thread_id=? AND e.type='log.message' ORDER BY e.id DESC LIMIT 50"); $q->execute([$threadId]);
        $existing = array_flip(array_column(self::$records, 'id'));
        foreach (array_reverse($q->fetchAll(\PDO::FETCH_COLUMN)) as $json) {
            $data = json_decode($json, true); $record = $data['record'];
            if (!isset($existing[$record['id']])) { self::$records[] = $record; self::$routes[$record['id']] = $data['routes']; $existing[$record['id']] = true; }
        }
    }
    public static function httpHeaders()
    {
        if (Runtime::isCli() || headers_sent()) return;
        $records = array_slice(self::browserMessages(), -10);
        foreach ($records as &$record) $record['message'] = mb_substr($record['message'], 0, 250);
        unset($record);
        while ($records && strlen(base64_encode(json_encode(array_values($records)))) > 6000) array_shift($records);
        if ($records) header('X-PHiLLiP-Messages: ' . base64_encode(json_encode(array_values($records))));
        else header_remove('X-PHiLLiP-Messages');
    }
    public static function headerHtml()
    {
        $html = '<div id="phillip-messages" class="phillip-messages" aria-live="polite" aria-label="Application messages">';
        foreach (self::browserMessages() as $record) if ($record['visible']) { self::$headerIds[] = $record['id']; $html .= self::messageHtml($record); }
        return $html . '</div>';
    }
    public static function messageHtml(array $record)
    {
        $escape = function ($value) { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        return '<div class="phillip-message log-' . strtolower($record['label']) . '" data-log-id="' . $escape($record['id']) . '" role="' . ($record['priority'] >= self::ERROR ? 'alert' : 'status') . '"><strong>' . $escape($record['label']) . '</strong><span>' . $escape($record['message']) . '</span><button type="button" data-dismiss-log aria-label="Dismiss message">×</button></div>';
    }
    public static function footerHtml()
    {
        $data = ['thresholds' => self::audience(), 'rendered' => self::$headerIds, 'messages' => self::browserMessages()];
        return '<script type="application/json" id="phillip-log-data">' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) . '</script>';
    }
    public static function event(array $event)
    {
        $type = $event['type']; $data = $event['data'];
        if ($type === 'tool.start') self::writeInfo('Using %s', ToolRegistry::label($data['name']));
        elseif (strpos($type, 'fallback') !== false) self::writeWarning(isset($data['message']) ? $data['message'] : $type);
        elseif ($type === 'tool.progress' || $type === 'attachment.progress') self::writeInfo(isset($data['message']) ? $data['message'] : 'Working');
        elseif ($type === 'context.pruned') self::writeDebug('Context reduced', $data);
    }
}

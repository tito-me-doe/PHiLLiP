<?php
namespace Agent;

/** One initialization path; no sessions or database connections merely to load classes. */
class Runtime
{
    private static $root;
    private static $identity;
    private static $identityResolved = false;
    private static $resolvingIdentity = false;
    private static $handling = false;
    private static $outputLevel;
    private static $reserve;

    public static function boot($root)
    {
        if (self::$root !== null) return;
        self::$root = $root;
        if (is_readable($root . '/.env')) foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos(ltrim($line), '#') === 0 || strpos($line, '=') === false) continue;
            list($key, $value) = explode('=', $line, 2);
            if (getenv(trim($key)) === false) putenv(trim($key) . '=' . trim($value, " \t\r\n\"'"));
        }
        $loader = null;
        foreach (spl_autoload_functions() ?: [] as $autoload) {
            if (is_array($autoload) && is_object($autoload[0]) && $autoload[0] instanceof \Composer\Autoload\ClassLoader) { $loader = $autoload[0]; break; }
        }
        if (!$loader && is_file($root . '/vendor/autoload.php')) $loader = require $root . '/vendor/autoload.php';
        if ($loader) $loader->addPsr4('Agent\\', $root . '/classes/Agent/');
        else spl_autoload_register([self::class, 'autoload']);
        date_default_timezone_set('UTC');
        // The logger owns diagnostics; native output would duplicate fatals or corrupt responses.
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');
        error_reporting(E_ALL);
        self::$reserve = str_repeat(' ', 262144);
        set_error_handler([self::class, 'error']);
        set_exception_handler([self::class, 'exception']);
        register_shutdown_function([self::class, 'shutdown']);
        Log::initialize();
        if (self::isCli()) {
            $priority = Cli::consumeLoggingOptions();
            if ($priority !== null) {
                $settings = ['user' => $priority, 'admin' => $priority, 'console' => $priority];
                if (Log::$minFilePriority !== Log::OFF) $settings['file'] = $priority;
                if (Log::$minDatabasePriority !== Log::OFF) $settings['database'] = $priority;
                Log::configure($settings);
                Log::writeDebug('CLI diagnostic priority overridden', ['priority' => $priority]);
            }
        }
        if (!self::isCli() && !headers_sent() && stripos(isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '', 'text/event-stream') === false) {
            self::$outputLevel = ob_get_level();
            ob_start(function ($body) { Log::httpHeaders(); return $body; });
        }
    }
    public static function root() { return self::$root; }
    public static function isCli() { return in_array(PHP_SAPI, ['cli', 'phpdbg'], true); }
    public static function expectsJson()
    {
        foreach (headers_list() as $header) if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'json') !== false) return true;
        return stripos(isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '', 'json') !== false
            || stripos(isset($_SERVER['HTTP_EXPECT']) ? $_SERVER['HTTP_EXPECT'] : '', 'json') !== false
            || stripos(isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '', 'json') !== false
            || preg_match('~/api/|^/health(?:\?|$)~', isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '');
    }
    public static function bindIdentity($user) { self::$identity = $user; self::$identityResolved = true; Log::httpHeaders(); }
    public static function identity()
    {
        if (!self::$identityResolved && !self::$resolvingIdentity && !self::isCli()) {
            self::$resolvingIdentity = true;
            try {
                if (session_status() === PHP_SESSION_ACTIVE || !empty($_COOKIE) || !empty($_SERVER['REMOTE_USER'])) Auth::current();
            } catch (\Throwable $e) { self::$identity = null; /* Never guess privileged identity. */ }
            finally { self::$identityResolved = true; self::$resolvingIdentity = false; }
        }
        return self::$identity;
    }
    public static function context()
    {
        return ['sapi' => PHP_SAPI, 'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null,
            'format' => self::isCli() ? 'terminal' : (self::expectsJson() ? 'json' : 'html'), 'user' => self::identity()];
    }
    public static function unixIdentity()
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if ($uid === null && is_readable('/proc/self/status') && preg_match('/^Uid:\s+\d+\s+(\d+)/m', file_get_contents('/proc/self/status'), $match)) $uid = (int)$match[1];
        $entry = $uid !== null && function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;
        // Environment usernames are user-controlled, not authentication inputs.
        return ['uid' => $uid, 'username' => $entry ? $entry['name'] : null, 'root' => $uid === 0];
    }
    public static function autoload($class)
    {
        if (strpos($class, 'Agent\\') !== 0 || !preg_match('/^Agent\\\\[A-Za-z0-9_\\\\]+$/D', $class)) return;
        $file = self::$root . '/classes/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) require_once $file;
    }
    public static function error($severity, $message, $file, $line)
    {
        if (!(error_reporting() & $severity)) return false;
        if (self::$handling) return true;
        // Let the caller catch recoverable/user fatals. Uncaught exceptions are reported once.
        if (in_array($severity, [E_USER_ERROR, E_RECOVERABLE_ERROR], true)) throw new \ErrorException($message, 0, $severity, $file, $line);
        self::$handling = true;
        try {
            $priority = in_array($severity, [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED, 2048], true) ? Log::DEBUG : Log::WARNING;
            self::report($priority, $message, ['severity' => $severity, 'file' => $file, 'line' => $line]);
        } finally { self::$handling = false; }
        return true;
    }

    public static function isExpected(\Throwable $error)
    {
        return in_array(get_class($error), ['RuntimeException', 'InvalidArgumentException', 'Agent\\StateConflict', 'Agent\\SubmissionConflict', 'Agent\\Cancelled'], true);
    }
    public static function publicMessage(\Throwable $error)
    {
        return self::isExpected($error) ? $error->getMessage() : 'An internal error occurred.';
    }

    /** Engine diagnostics are private on HTTP; explicit application Log messages keep their normal routes. */
    private static function report($priority, $message, array $source)
    {
        $record = Log::with(self::isCli() ? ['cli_stream' => 'stderr'] : ['user' => Log::OFF], function () use ($priority, $message, $source) {
            return Log::write($priority, $message, $source);
        });
        if (self::isCli()) return $record;
        $message = $priority >= Log::ERROR ? 'An internal error occurred.' : 'An internal diagnostic occurred.';
        if ($record) $message .= ' Reference: ' . $record['id'];
        return Log::write($priority, $message);
    }

    public static function exception($error)
    {
        self::$reserve = null;
        if (self::$handling) { error_log('PHiLLiP: fatal error while delivering diagnostics'); exit(1); }
        self::$handling = true;
        try {
            $source = ['exception' => get_class($error), 'file' => $error->getFile(), 'line' => $error->getLine()];
            if ($error instanceof \ErrorException) $source['severity'] = $error->getSeverity();
            $record = self::report(Log::FATAL, $error->getMessage(), $source);
            Log::trace('Unhandled exception', $error);
            if (!self::isCli()) {
                $stream = stripos(isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '', 'text/event-stream') !== false;
                foreach (headers_list() as $header) if (stripos($header, 'Content-Type: text/event-stream') === 0) $stream = true;
                // Discard only buffers created since bootstrap, never an outer application's buffer.
                if (self::$outputLevel !== null) {
                    while (ob_get_level() > self::$outputLevel) {
                        if (!@ob_end_clean()) break;
                    }
                }
                if (!headers_sent()) {
                    foreach (['Content-Length', 'Content-Disposition', 'Content-Encoding', 'Location'] as $name) header_remove($name);
                    http_response_code(500); header('Cache-Control: no-store'); Log::httpHeaders();
                    $message = $record ? $record['message'] : 'An internal error occurred.';
                    if ($stream) {
                        header('Content-Type: text/event-stream; charset=utf-8');
                        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'HEAD') echo "event: error\ndata: " . json_encode(['error' => $message]) . "\n\n";
                    } elseif (self::expectsJson()) {
                        header('Content-Type: application/json; charset=utf-8');
                        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'HEAD') echo json_encode(['error' => $message]);
                    } else {
                        header('Content-Type: text/html; charset=utf-8');
                        $base = htmlspecialchars(rtrim(getenv('APP_BASE_PATH') ?: '', '/'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'HEAD') echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>PHiLLiP · Unable to complete request</title><link rel="stylesheet" href="' . $base . '/assets/messages.css"><script defer src="' . $base . '/assets/messages.js"></script></head><body>' . Log::headerHtml() . Log::footerHtml() . '</body></html>';
                    }
                }
            }
        } catch (\Throwable $failure) {
            // Final fallback must not recurse into either the logger or response rendering.
            error_log('PHiLLiP: fatal diagnostic delivery failed (' . get_class($failure) . ')');
        }
        exit(1);
    }
    public static function shutdown()
    {
        self::$reserve = null;
        $error = error_get_last();
        if (!self::$handling && $error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) self::exception(new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
    }
}

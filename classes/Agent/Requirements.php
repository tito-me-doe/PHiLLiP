<?php
namespace Agent;

/**
 * Declared dependencies of optional capabilities, answered in one place. A need is "kind:name":
 *   binary:NAME     an executable on PATH            extension:NAME  a loaded PHP extension
 *   service:NAME    a configured service (Config)    role:NAME       a task role assigned to an enabled profile
 * This reports what is PRESENT and CONFIGURED, never whether a remote service is reachable right now
 * (SystemInfo probes that). A capability that is not satisfied is unavailable until configured; callers
 * offer a fallback or hide it instead of failing deep inside a call.
 */
class Requirements
{
    private static $memo = [];

    /** @return array{available: bool, missing: list<array{need: string, reason: string}>} */
    public static function check(array $needs)
    {
        $missing = [];
        foreach ($needs as $need) {
            $reason = self::need((string)$need);
            if ($reason !== null) $missing[] = ['need' => (string)$need, 'reason' => $reason];
        }
        return ['available' => !$missing, 'missing' => $missing];
    }

    /** Null when satisfied, otherwise a short human reason. */
    public static function need($need)
    {
        if (!preg_match('/^(binary|extension|service|role):([A-Za-z0-9_.+-]+)$/D', $need, $m)) return "unknown requirement '$need'";
        if (array_key_exists($need, self::$memo)) return self::$memo[$need];
        list(, $kind, $name) = $m;
        $reason = null;
        if ($kind === 'binary') {
            $found = false;
            foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) if ($dir !== '' && is_file($dir . '/' . $name) && is_executable($dir . '/' . $name)) { $found = true; break; }
            if (!$found) $reason = "the '$name' program is not installed";
        } elseif ($kind === 'extension') {
            if (!extension_loaded($name)) $reason = "the PHP extension '$name' is not loaded";
        } elseif ($kind === 'service') {
            try { Config::service($name); }
            catch (\Throwable $e) { $reason = "the '$name' service is not configured"; }
        } else {
            try {
                $row = Database::row('SELECT 1 AS ok FROM profile_defaults d JOIN profiles p ON p.id = d.profileid WHERE d.default_for = ? AND p.enabled', [$name]);
                if (!$row) $reason = "no enabled profile is assigned to the '$name' role";
            } catch (\Throwable $e) { $reason = "the '$name' role cannot be checked (database unavailable)"; }
        }
        // Binaries and extensions cannot change within a process; services and roles can (settings are edited), so they are not cached.
        if ($kind === 'binary' || $kind === 'extension') self::$memo[$need] = $reason;
        return $reason;
    }

    /** Needs a class declares with `const REQUIRES = ['service:searxng', ...]`. */
    public static function forClass($class)
    {
        return is_string($class) && class_exists($class) && defined($class . '::REQUIRES') ? (array)constant($class . '::REQUIRES') : [];
    }

    /** Availability of one tool class. */
    public static function tool($class)
    {
        return self::check(self::forClass($class));
    }

    /** Availability of every shipped tool class, by short name. */
    public static function tools()
    {
        $result = [];
        foreach (glob(Runtime::root() . '/classes/Agent/Tools/*.php') as $file) {
            $name = basename($file, '.php');
            $result[$name] = self::tool('Agent\\Tools\\' . $name);
        }
        ksort($result);
        return $result;
    }

    /** One line per unavailable tool, e.g. "SearchWeb (the 'searxng' service is not configured)". */
    public static function unavailableSummary(array $classes)
    {
        $lines = [];
        foreach ($classes as $class) {
            $check = self::tool($class);
            if (!$check['available']) $lines[] = substr(strrchr('\\' . $class, '\\'), 1) . ' (' . implode('; ', array_column($check['missing'], 'reason')) . ')';
        }
        return $lines;
    }

    /** Forget cached binary/extension answers (tests). */
    public static function reset() { self::$memo = []; }
}

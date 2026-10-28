<?php
namespace Agent;

class ToolContext
{
    private static $values = [];

    public static function get($key, $default = null) { return isset(self::$values[$key]) ? self::$values[$key] : $default; }
    public static function values() { return self::$values; }

    public static function withValues(array $values, $callback)
    {
        $previous = self::$values;
        self::$values = $values;
        try { return call_user_func($callback); }
        finally { self::$values = $previous; }
    }
}

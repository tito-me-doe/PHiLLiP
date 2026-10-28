<?php
namespace Agent;

class Events
{
    private static $listener;

    public static function emit($type, array $data = [])
    {
        Log::event(['type' => $type, 'data' => $data]);
        if (self::$listener) call_user_func(self::$listener, ['type' => $type, 'data' => $data, 'time' => microtime(true)]);
    }

    public static function withListener($listener, $callback)
    {
        $previous = self::$listener;
        self::$listener = $listener;
        try { return call_user_func($callback); }
        finally { self::$listener = $previous; }
    }
}

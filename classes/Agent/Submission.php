<?php
namespace Agent;

class Submission
{
    public static function key($key)
    {
        if (!is_string($key) || !preg_match('/^[a-zA-Z0-9._:-]{8,128}$/', $key)) throw new \InvalidArgumentException('A stable submission_key of 8 to 128 identifier characters is required');
        return $key;
    }
    public static function hash(array $payload) { return hash('sha256', State::encode(self::canonical($payload))); }
    private static function canonical($value)
    {
        if (!is_array($value)) return $value;
        if ($value && array_keys($value) !== range(0, count($value) - 1)) ksort($value);
        foreach ($value as &$item) $item = self::canonical($item);
        return $value;
    }
}

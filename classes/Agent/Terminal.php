<?php
namespace Agent;

/** Terminal presentation only. Logger chooses destinations; terminal never opens sessions. */
class Terminal
{
    private static $bottom = '';
    private static $styles = ['reset' => 0, 'bold' => 1, 'dim' => 2, 'italic' => 3, 'underline' => 4,
        'black' => 30, 'red' => 31, 'green' => 32, 'yellow' => 33, 'blue' => 34, 'magenta' => 35,
        'cyan' => 36, 'white' => 37, 'gray' => 90, 'grey' => 90, 'bright-red' => 91];
    public static function stream($stream = 'stderr')
    {
        if (is_resource($stream)) return $stream;
        if (!in_array($stream, ['stdout', 'stderr', 'stdin'], true)) throw new \InvalidArgumentException('Use stdout, stderr, stdin or a stream resource');
        static $streams = [];
        if (!isset($streams[$stream])) $streams[$stream] = fopen('php://' . $stream, $stream === 'stdin' ? 'r' : 'w');
        return $streams[$stream];
    }
    public static function isTTY($stream = 'stderr')
    {
        $stream = self::stream($stream);
        if (function_exists('stream_isatty')) {
            return @stream_isatty($stream);
        }
        return function_exists('posix_isatty') && @posix_isatty($stream);
    }
    public static function isColor($stream = 'stderr')
    {
        if (getenv('NO_COLOR') !== false && getenv('NO_COLOR') !== '') return false;
        if (getenv('FORCE_COLOR') !== false) return !in_array(strtolower(getenv('FORCE_COLOR')), ['', '0', 'false'], true);
        return self::isTTY($stream) && getenv('TERM') !== 'dumb';
    }
    public static function strip($text)
    {
        $text = preg_replace('/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)|\x1b\[[0-?]*[ -\/]*[@-~]|\x1b[@-_]/', '', (string)$text);
        // UTF-8 C1 and directional controls can manipulate terminals without an ASCII ESC.
        // Match complete byte sequences, preserving ordinary Unicode and tolerating invalid input bytes.
        $text = preg_replace('/\xC2[\x80-\x9F]|\xD8\x9C|\xE2\x80[\x8E\x8F\xAA-\xAE]|\xE2\x81[\xA6-\xA9]/', '', $text);
        return preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $text);
    }
    public static function format($text, $styles = '', $stream = 'stderr')
    {
        $text = self::strip($text); $codes = [];
        foreach (preg_split('/[\s,]+/', is_array($styles) ? implode(' ', $styles) : $styles, -1, PREG_SPLIT_NO_EMPTY) as $style) {
            if (!isset(self::$styles[$style])) throw new \InvalidArgumentException('Unknown terminal style: ' . $style);
            $codes[] = self::$styles[$style];
        }
        return $codes && self::isColor($stream) ? "\033[" . implode(';', $codes) . 'm' . $text . "\033[0m" : $text;
    }
    public static function write($text, $stream = 'stderr')
    {
        $handle = self::stream($stream);
        if ($stream === 'stderr' && self::$bottom !== '' && self::isTTY($handle)) fwrite($handle, "\r\033[2K");
        $result = fwrite($handle, $text);
        if ($stream === 'stderr' && self::$bottom !== '' && self::isTTY($handle)) fwrite($handle, self::$bottom);
        return $result;
    }
    public static function progress($message = '')
    {
        if (!self::isTTY()) return;
        self::$bottom = self::strip($message);
        fwrite(self::stream(), "\r\033[2K" . self::$bottom);
    }
    public static function clearProgress() { self::progress(''); }
    public static function size()
    {
        $rows = (int)getenv('LINES'); $cols = (int)getenv('COLUMNS');
        if ((!$rows || !$cols) && self::isTTY() && is_readable('/dev/tty') && function_exists('shell_exec')) {
            $size = @shell_exec('stty size </dev/tty 2>/dev/null');
            if (preg_match('/^(\d+)\s+(\d+)\s*$/D', (string)$size, $match)) { $rows = $rows ?: (int)$match[1]; $cols = $cols ?: (int)$match[2]; }
        }
        return ['rows' => $rows > 0 ? $rows : 24, 'cols' => $cols > 0 ? $cols : 80];
    }
}

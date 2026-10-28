<?php
namespace Agent\Tools;

/** Read-only source inspection for explicitly enabled local CLI agents. */
class Source
{
    const MAX_FILE_SIZE = 2097152;
    const BYTE_LIMIT = 32768;
    const PRIVATE_NAMES = ['config', 'storage', 'vendor', 'node_modules'];

    /**
     * List source files within the current CLI workspace, excluding private paths and symlinks.
     * @param string $path Workspace-relative directory
     * @param int $limit Maximum returned files, clamped to 1..1000
     */
    public static function files(string $path = '.', int $limit = 1000)
    {
        list($root, $directory) = self::resolve($path);
        if (!is_dir($directory)) throw new \InvalidArgumentException('Source listing requires a directory');
        $results = [];
        self::collect($directory, $root, $results, max(1, min(1000, $limit)));
        sort($results);
        return $results;
    }

    /**
     * Read a bounded line range from a UTF-8 source file within the current CLI workspace.
     * A unique bare filename can be resolved within the workspace. Check next_line/truncated before treating an excerpt as the whole file.
     * Set numbered=true to prefix each text line with its absolute line number, e.g. "12: ". The 32 KiB source budget applies before these bounded prefixes (at most 500).
     * @param string $path Workspace-relative file path
     * @param int $startLine First line, starting at 1
     * @param int $maxLines Maximum lines, clamped to 1..500
     * @param bool $numbered Prefix returned text lines with absolute line numbers
     */
    public static function read(string $path, int $startLine = 1, int $maxLines = 500, bool $numbered = false)
    {
        list($root, $file) = self::resolve($path);
        if (!is_file($file)) throw new \InvalidArgumentException('Source read requires a regular file');
        $content = file_get_contents($file, false, null, 0, self::MAX_FILE_SIZE + 1);
        if ($content === false) throw new \RuntimeException('Unable to read source file');
        if (strlen($content) > self::MAX_FILE_SIZE) throw new \RuntimeException('Source file exceeds 2 MiB');
        if (strpos($content, "\0") !== false || !preg_match('//u', $content)) throw new \RuntimeException('Source requires UTF-8 text, not binary data');
        $startLine = max(1, $startLine);
        $lines = explode("\n", $content);
        if (end($lines) === '') array_pop($lines);
        $slice = array_slice($lines, $startLine - 1, max(1, min(500, $maxLines)));
        $text = implode("\n", $slice);
        $truncated = count($slice) > 0 && $startLine - 1 + count($slice) < count($lines);
        $lineTruncated = false;
        if (strlen($text) > self::BYTE_LIMIT) {
            $length = self::BYTE_LIMIT;
            while ($length > 0 && (ord($text[$length]) & 0xC0) === 0x80) $length--;
            $text = substr($text, 0, $length);
            $newline = strrpos($text, "\n");
            if ($newline !== false) $text = substr($text, 0, $newline);
            else $lineTruncated = true;
            $truncated = true;
        }
        $endLine = $slice ? $startLine + substr_count($text, "\n") : $startLine - 1;
        if ($numbered && $slice) {
            $numberedLines = explode("\n", $text);
            foreach ($numberedLines as $index => &$line) $line = ($startLine + $index) . ': ' . $line;
            unset($line);
            $text = implode("\n", $numberedLines);
        }
        return ['path' => substr($file, strlen($root) + 1), 'start_line' => $startLine, 'end_line' => $endLine, 'total_lines' => count($lines),
            'next_line' => $truncated && !$lineTruncated ? $endLine + 1 : null, 'text' => $text, 'truncated' => $truncated, 'line_truncated' => $lineTruncated,
            'sha256' => hash('sha256', $content)];
    }

    /**
     * Find literal text in source filenames and UTF-8 lines within a file or directory. Bounded to 1000 files and 8 MiB; private paths and symlinks are excluded. Use returned paths/line numbers for read.
     * @param string $query Literal filename or text to find, case-insensitive
     * @param string $path Workspace-relative file or directory, default current workspace
     * @param int $limit Maximum matches, 1..100
     */
    public static function search(string $query, string $path = '.', int $limit = 30)
    {
        $query = trim($query);
        if ($query === '' || strlen($query) > 500 || preg_match('//u', $query) !== 1 || strpos($query, "\0") !== false) throw new \InvalidArgumentException('Search needs 1..500 bytes of UTF-8 text');
        list($root, $target) = self::resolve($path);
        $files = is_file($target) ? [substr($target, strlen($root) + 1)] : self::files($path, 1000);
        $matches = []; $bytes = 0; $limit = max(1, min(100, $limit));
        foreach ($files as $file) {
            if (stripos($file, $query) !== false) $matches[] = ['path' => $file, 'line' => null, 'text' => $file];
            if (count($matches) >= $limit) return ['matches' => $matches, 'truncated' => true];
            list($root, $resolved) = self::resolve($file);
            if (filesize($resolved) > self::MAX_FILE_SIZE) continue;
            $content = file_get_contents($resolved, false, null, 0, self::MAX_FILE_SIZE + 1);
            if ($content === false) continue;
            $bytes += strlen($content);
            if ($bytes > 8388608) return ['matches' => $matches, 'truncated' => true];
            if (strpos($content, "\0") !== false || preg_match('//u', $content) !== 1) continue;
            foreach (explode("\n", $content) as $number => $line) {
                if (stripos($line, $query) === false) continue;
                $matches[] = ['path' => $file, 'line' => $number + 1, 'text' => mb_substr($line, 0, 500)];
                if (count($matches) >= $limit) return ['matches' => $matches, 'truncated' => true];
            }
        }
        return ['matches' => $matches, 'truncated' => count($files) === 1000];
    }

    private static function resolve($path)
    {
        if (PHP_SAPI !== 'cli' || \Agent\ToolContext::get('frontend')) throw new \RuntimeException('Source is only available to local CLI agents');
        $root = realpath(getcwd());
        if ($root === false || $root === DIRECTORY_SEPARATOR) throw new \RuntimeException('A specific CLI workspace is required');
        // Validate before trimming: whitespace is forgiving, embedded control bytes are not.
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $path)) throw new \InvalidArgumentException('Invalid source path characters');
        $path = trim($path);
        if ($path === '' || $path[0] === '/' || preg_match('/^[A-Za-z]:/', $path)) throw new \InvalidArgumentException('Source requires a workspace-relative path');
        $full = $root;
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.') continue;
            if ($segment === '' || $segment === '..' || $segment[0] === '.' || in_array(strtolower($segment), self::PRIVATE_NAMES, true)) throw new \InvalidArgumentException('Private or invalid source path');
            $full .= '/' . $segment;
            if (is_link($full)) throw new \InvalidArgumentException('Source paths cannot traverse symlinks');
        }
        $canonical = realpath($full);
        if ($canonical === false && strpos($path, '/') === false) {
            $files = self::files('.', 1000);
            $matches = array_values(array_filter($files, function ($file) use ($path) { return basename($file) === $path; }));
            if (count($matches) > 1 || (count($matches) === 1 && count($files) === 1000)) throw new \InvalidArgumentException('Ambiguous or incomplete filename lookup; supply its workspace-relative path');
            if (count($matches) === 1) {
                \Agent\Log::writeWarning('Resolved source filename %s to %s', $path, $matches[0]);
                return self::resolve($matches[0]);
            }
        }
        if ($canonical === false || ($canonical !== $root && strpos($canonical, $root . '/') !== 0)) throw new \InvalidArgumentException('Source path is missing or outside the workspace');
        return [$root, $canonical];
    }

    private static function collect($directory, $root, array &$results, $limit)
    {
        $entries = scandir($directory);
        if ($entries === false) throw new \RuntimeException('Unable to list source directory');
        foreach ($entries as $name) {
            if ($name[0] === '.' || in_array(strtolower($name), self::PRIVATE_NAMES, true)) continue;
            // JSON tool responses and resolve() must be able to represent the same path.
            // Skip unsupported names; never rewrite one filename into a different file.
            if ($name !== trim($name) || preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x1f\x7f\\\\]/', $name)
                || ($directory === $root && preg_match('/^[A-Za-z]:/', $name))) continue;
            $full = $directory . '/' . $name;
            if (is_link($full)) continue;
            if (is_dir($full)) self::collect($full, $root, $results, $limit);
            elseif (is_file($full)) $results[] = substr($full, strlen($root) + 1);
            if (count($results) >= $limit) return;
        }
    }
}

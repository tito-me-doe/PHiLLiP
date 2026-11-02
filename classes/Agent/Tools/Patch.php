<?php
namespace Agent\Tools;

use Agent\WorkspaceAccess;

/** Workspace file edits for agents explicitly given this tool. The tool grant is the authority: there is no per-file approval and no command execution. */
class Patch
{
    /**
     * Replace one occurrence of text in an existing UTF-8 file in the current workspace, trying an exact match first and falling back to a unique whitespace-insensitive match; never guesses among matches, so include enough context to match once.
     * @param string $path Workspace-relative file
     * @param string $search Existing text, occurring once (exactly, or uniquely when ignoring whitespace differences)
     * @param string $replacement New text
     */
    public static function replace(string $path, string $search, string $replacement)
    {
        $file = self::resolve($path);
        if ($search === '' || trim($search) === '') throw new \InvalidArgumentException('Replacement search cannot be empty');
        self::text($search); self::text($replacement);
        return self::locked($file, function () use ($file, $path, $search, $replacement) {
            if (!is_file($file) || is_link($file)) throw new \RuntimeException('Replacement requires an existing regular file');
            $content = file_get_contents($file, false, null, 0, 2097153);
            if ($content === false) throw new \RuntimeException('Cannot read file');
            self::text($content);
            $exact = substr_count($content, $search);
            if ($exact === 1) {
                return self::publish($file, $path, str_replace($search, $replacement, $content), 'exact');
            }
            if ($exact > 1) {
                throw new \RuntimeException('Search must match exactly once; read the current file and retry with unambiguous context');
            }
            $tokens = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY);
            $pattern = '';
            foreach ($tokens as $token) {
                if ($pattern !== '') $pattern .= '\s+';
                $pattern .= preg_quote($token, '/');
            }
            $count = preg_match_all('/' . $pattern . '/u', $content, $matches, PREG_OFFSET_CAPTURE);
            if ($count === false) throw new \RuntimeException('Whitespace-insensitive search failed; use a shorter search');
            if ($count === 0) {
                throw new \RuntimeException('Search must match exactly once; read the current file and retry with unambiguous context (also tried ignoring whitespace differences)');
            }
            if ($count > 1) {
                throw new \RuntimeException('Search matches ' . $count . ' places even ignoring whitespace differences; include more surrounding text');
            }
            $matchStart = $matches[0][0][1];
            $matchEnd = $matchStart + strlen($matches[0][0][0]);
            if (preg_match('/^(\s*)/u', $search, $lead) && $lead[1] !== '' && strpos($replacement, $lead[1]) === 0) {
                $replacement = substr($replacement, strlen($lead[1]));
            }
            if (preg_match('/(\s*)$/u', $search, $trail) && $trail[1] !== '' && substr($replacement, -strlen($trail[1])) === $trail[1]) {
                $replacement = substr($replacement, 0, -strlen($trail[1]));
            }
            // A file that uses CRLF throughout keeps CRLF: never introduce mixed line endings through the forgiving path.
            if (strpos($content, "\r\n") !== false && !preg_match('/(?<!\r)\n/', $content)) $replacement = preg_replace('/(?<!\r)\n/', "\r\n", $replacement);
            $updated = substr($content, 0, $matchStart) . $replacement . substr($content, $matchEnd);
            return self::publish($file, $path, $updated, 'whitespace-normalized');
        });
    }

    /**
     * Replace an inclusive 1-based line range of an existing UTF-8 file in the current workspace. Easier than replace for multi-line edits: read the file with Source::read (numbered=true) and pass its sha256, which must still match.
     * An empty replacement deletes the range; a replacement missing its final newline keeps the selected range's line ending.
     * @param string $path Workspace-relative existing file
     * @param int $startLine First line to replace, starting at 1
     * @param int $endLine Last line to replace, inclusive
     * @param string $replacement Replacement lines
     * @param string $expectedSha256 Current whole-file sha256 from Source::read, required
     */
    public static function replaceLines(string $path, int $startLine, int $endLine, string $replacement, string $expectedSha256)
    {
        $file = self::resolve($path);
        self::text($replacement);
        if ($startLine < 1 || $endLine < $startLine) throw new \InvalidArgumentException('Line range must be inclusive with 1 <= startLine <= endLine');
        if (!preg_match('/^[a-f0-9]{64}$/D', $expectedSha256)) throw new \InvalidArgumentException('Line replacement requires the current whole-file sha256 from Source::read: 64 lowercase hexadecimal characters');
        return self::locked($file, function () use ($file, $path, $startLine, $endLine, $replacement, $expectedSha256) {
            if (!is_file($file) || is_link($file)) throw new \RuntimeException('Line replacement requires an existing regular file');
            $content = file_get_contents($file, false, null, 0, 2097153);
            if ($content === false) throw new \RuntimeException('Cannot read file');
            self::text($content);
            if (hash('sha256', $content) !== $expectedSha256) throw new \RuntimeException('Current file hash does not match expected; read the file again before replacing lines');
            $lineCount = $content === '' ? 0 : substr_count($content, "\n") + (substr($content, -1) === "\n" ? 0 : 1);
            if ($endLine > $lineCount) throw new \InvalidArgumentException("Line range exceeds the file's $lineCount lines");
            $start = 0;
            for ($line = 1; $line < $startLine; $line++) $start = strpos($content, "\n", $start) + 1;
            $end = $start;
            for ($line = $startLine; $line <= $endLine; $line++) {
                $newline = strpos($content, "\n", $end);
                $end = $newline === false ? strlen($content) : $newline + 1;
            }
            if ($replacement !== '' && substr($replacement, -1) !== "\n" && $content[$end - 1] === "\n") $replacement .= $end > 1 && substr($content, $end - 2, 2) === "\r\n" ? "\r\n" : "\n";
            return self::publish($file, $path, substr($content, 0, $start) . $replacement . substr($content, $end));
        });
    }

    /** Stage, validate and atomically rename new content over an existing file, preserving its mode. Called under the file lock. */
    private static function publish($file, $path, $updated, $matched = null)
    {
        self::text($updated, $path);
        $temp = tempnam(dirname($file), '.phillip-');
        if ($temp === false) throw new \RuntimeException('Cannot stage change');
        try {
            if (file_put_contents($temp, $updated) !== strlen($updated) || !chmod($temp, fileperms($file) & 0777) || !rename($temp, $file)) throw new \RuntimeException('Cannot publish change');
        } finally { if (is_file($temp)) unlink($temp); }
        $result = ['path' => $path, 'sha256' => hash('sha256', $updated), 'bytes' => strlen($updated)];
        if ($matched !== null) {
            $result['matched'] = $matched;
        }
        return $result;
    }

    /**
     * Create a new UTF-8 file in the current workspace without overwriting an existing file. The parent directory must already exist.
     * @param string $path Workspace-relative new file
     * @param string $content File contents
     */
    public static function create(string $path, string $content)
    {
        $file = self::resolve($path);
        self::text($content, $path);
        return self::locked($file, function () use ($file, $path, $content) {
            $handle = @fopen($file, 'x');
            if (!$handle) throw new \RuntimeException('Cannot create file; it may already exist');
            $created = true;
            try { if (fwrite($handle, $content) !== strlen($content)) { $created = false; throw new \RuntimeException('Could not write complete file'); } }
            finally { fclose($handle); if (!$created && is_file($file)) unlink($file); }
            return ['path' => $path, 'sha256' => hash('sha256', $content), 'bytes' => strlen($content)];
        });
    }

    private static function resolve($path)
    {
        if (PHP_SAPI !== 'cli' || \Agent\ToolContext::get('frontend')) throw new \RuntimeException('Patch is only available to local CLI agents');
        return WorkspaceAccess::path($path);
    }

    /** Serialize cooperating writers (parallel agents) on the same file. */
    private static function locked($file, $callback)
    {
        $lock = fopen(sys_get_temp_dir() . '/phillip-patch-' . hash('sha256', $file) . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('Cannot lock file');
        try { return $callback(); } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function text($text, $path = '')
    {
        if (strlen($text) > 2097152 || strpos($text, "\0") !== false || preg_match('//u', $text) !== 1) throw new \InvalidArgumentException('Edits require UTF-8 text without NUL bytes, at most 2 MiB');
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php' || preg_match('/^#[^\r\n]*\bphp\b/', $text)) {
            try { token_get_all($text, TOKEN_PARSE); }
            catch (\ParseError $error) { throw new \RuntimeException('Refusing invalid PHP for ' . $path . ': ' . $error->getMessage()); }
        }
    }
}

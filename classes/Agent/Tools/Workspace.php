<?php
namespace Agent\Tools;

use Agent\WorkspaceAccess;

/** Opt-in local edits and commands. Every target requires a separate operator grant. */
class Workspace
{
    /**
     * Replace one exact, non-empty occurrence in an existing UTF-8 file. Never guesses among matches. Requires file PATCH approval; ask the operator if denied.
     * @param string $path Workspace-relative file
     * @param string $search Exact existing text, occurring once
     * @param string $replacement New text
     */
    public static function replace(string $path, string $search, string $replacement)
    {
        $file = WorkspaceAccess::path($path);
        if ($search === '') throw new \InvalidArgumentException('Replacement search cannot be empty');
        self::text($search); self::text($replacement);
        // Serialize cooperating PHiLLiP writers, including agents in different threads.
        return WorkspaceAccess::authorized('patch', $path, function () use ($file, $path, $search, $replacement) {
            \Agent\Database::execute('SELECT pg_advisory_xact_lock(hashtextextended(?::text, 0))', [$file]);
            if (!is_file($file) || is_link($file)) throw new \RuntimeException('Replacement requires an existing regular file');
            $content = file_get_contents($file, false, null, 0, 2097153);
            if ($content === false) throw new \RuntimeException('Cannot read workspace file');
            self::text($content);
            if (substr_count($content, $search) !== 1) throw new \RuntimeException('Search must match exactly once; read the current file and retry with unambiguous context');
            $updated = str_replace($search, $replacement, $content); self::text($updated);
            return self::publish($file, $path, $updated, $content);
        });
    }

    /**
     * Replace an inclusive 1-based line range using the current whole-file SHA-256 from Source::read. Requires PATCH approval for this exact path.
     * Lines use LF boundaries, matching Source::read; a trailing LF adds no extra line and an empty file has no lines. Lone CR is content.
     * Selected lines include their LF terminators (including CRLF bytes). A nonempty replacement missing its final LF retains the last selected line's LF/CRLF terminator and emits a warning; an empty replacement deletes the lines. Bytes outside the range are preserved exactly. Use replace/write for intentional joins. PHP is parsed before publication.
     * @param string $path Workspace-relative existing file
     * @param int $startLine First line to replace, starting at 1
     * @param int $endLine Last line to replace, inclusive
     * @param string $replacement Replacement lines; a missing final LF retains the selected terminator, empty deletes the range
     * @param string $expectedSha256 Current whole-file SHA-256 from Source::read, required
     */
    public static function replaceLines(string $path, int $startLine, int $endLine, string $replacement, string $expectedSha256)
    {
        $file = WorkspaceAccess::path($path);
        self::text($replacement);
        if ($startLine < 1 || $endLine < $startLine) throw new \InvalidArgumentException('Line range must be inclusive with 1 <= startLine <= endLine');
        if (!preg_match('/^[a-f0-9]{64}$/D', $expectedSha256)) throw new \InvalidArgumentException('Line replacement requires a current whole-file SHA-256: 64 lowercase hexadecimal characters');
        return WorkspaceAccess::authorized('patch', $path, function () use ($file, $path, $startLine, $endLine, $replacement, $expectedSha256) {
            \Agent\Database::execute('SELECT pg_advisory_xact_lock(hashtextextended(?::text, 0))', [$file]);
            if (!is_file($file) || is_link($file)) throw new \RuntimeException('Line replacement requires an existing regular file');
            $content = file_get_contents($file, false, null, 0, 2097153);
            if ($content === false) throw new \RuntimeException('Cannot read workspace file');
            self::text($content);
            if (hash('sha256', $content) !== $expectedSha256) throw new \RuntimeException('Current file hash does not match expected; read the file again before replacing lines');
            $lineCount = $content === '' ? 0 : substr_count($content, "\n") + (substr($content, -1) === "\n" ? 0 : 1);
            if ($endLine > $lineCount) throw new \InvalidArgumentException('Line range exceeds the current file line count');
            $start = 0;
            for ($line = 1; $line < $startLine; $line++) $start = strpos($content, "\n", $start) + 1;
            $end = $start;
            for ($line = $startLine; $line <= $endLine; $line++) {
                $newline = strpos($content, "\n", $end);
                $end = $newline === false ? strlen($content) : $newline + 1;
            }
            if ($replacement !== '' && substr($replacement, -1) !== "\n" && $content[$end - 1] === "\n") {
                $replacement .= $end > 1 && substr($content, $end - 2, 2) === "\r\n" ? "\r\n" : "\n";
                \Agent\Log::writeWarning('Preserved the selected line terminator for ' . $path . ' because replacement omitted its final LF');
            }
            return self::publish($file, $path, substr($content, 0, $start) . $replacement . substr($content, $end), $content);
        });
    }

    /**
     * Write bounded UTF-8 content to a workspace file with hash-guarded overwrite.
     *
     * If the file is absent and no expected hash is given, creates it exclusively (existing create semantics).
     * If the file exists, an exact current SHA-256 (from Source::read of the whole original file) is required;
     * a missing or wrong hash fails without modifying the file. Existing files are updated through the shared
     * staged-rename publisher, preserving file mode. PHP files are parsed before publication, never executed. Requires PATCH approval for this exact path.
     * @param string $path Workspace-relative file
     * @param string $content File contents
     * @param string $expectedSha256 SHA-256 of the current whole file, required to overwrite an existing file
     */
    public static function write(string $path, string $content, string $expectedSha256 = '')
    {
        $file = WorkspaceAccess::path($path);
        self::text($content);
        return WorkspaceAccess::authorized('patch', $path, function () use ($file, $path, $content, $expectedSha256) {
            \Agent\Database::execute('SELECT pg_advisory_xact_lock(hashtextextended(?::text, 0))', [$file]);
            $exists = is_file($file) && !is_link($file);
            if ($exists) {
                if ($expectedSha256 === '') throw new \RuntimeException('Write to an existing file requires its current SHA-256');
                $before = file_get_contents($file, false, null, 0, 2097153);
                if ($before === false) throw new \RuntimeException('Cannot read workspace file');
                self::text($before);
                if (hash('sha256', $before) !== $expectedSha256) throw new \RuntimeException('Current file hash does not match expected; refusing to overwrite');
                return self::publish($file, $path, $content, $before);
            }
            if ($expectedSha256 !== '') throw new \RuntimeException('Cannot verify the SHA-256 of an absent file');
            return self::create($path, $content);
        });
    }

    /**
     * Stage, verify, and atomically publish a rewritten file, preserving its mode.
     *
     * @param string $file Resolved workspace file path
     * @param string $path Workspace-relative path (for logging/verification)
     * @param string $updated New full content
     * @param string $before Current whole content, required to detect concurrent changes
     * @return array ['path' => string, 'sha256' => string, 'bytes' => int]
     */
    private static function publish(string $file, string $path, string $updated, string $before)
    {
        self::text($updated, $path);
        $temp = tempnam(dirname($file), '.phillip-');
        if ($temp === false) throw new \RuntimeException('Cannot stage workspace change');
        try {
            if (file_put_contents($temp, $updated) !== strlen($updated) || !chmod($temp, fileperms($file) & 0777)) throw new \RuntimeException('Cannot stage workspace change');
            clearstatcache(true, $file);
            if (WorkspaceAccess::path($path) !== $file || file_get_contents($file) !== $before) throw new \RuntimeException('File changed during patch; read it again');
            if (!rename($temp, $file)) throw new \RuntimeException('Cannot publish workspace change');
        } finally { if (is_file($temp)) unlink($temp); }
        \Agent\Events::emit('tool.progress', ['message' => 'Updated ' . $path]);
        \Agent\Log::writeSuccess('Workspace file updated', ['path' => $path]);
        return ['path' => $path, 'sha256' => hash('sha256', $updated), 'bytes' => strlen($updated)];
    }

    /**
     * Create a new UTF-8 file without overwriting an existing file. Parent directory must exist. Requires PATCH approval for this exact path.
     * @param string $path Workspace-relative new file
     * @param string $content File contents
     */
    public static function create(string $path, string $content)
    {
        $file = WorkspaceAccess::path($path); self::text($content, $path);
        return WorkspaceAccess::authorized('patch', $path, function () use ($file, $path, $content) {
            $handle = @fopen($file, 'x');
            if (!$handle) throw new \RuntimeException('Cannot create file; it may already exist');
            try {
                if (fwrite($handle, $content) !== strlen($content)) throw new \RuntimeException('Could not write complete file');
            } finally { fclose($handle); }
            \Agent\Events::emit('tool.progress', ['message' => 'Created ' . $path]);
            \Agent\Log::writeSuccess('Workspace file created', ['path' => $path]);
            return ['path' => $path, 'sha256' => hash('sha256', $content), 'bytes' => strlen($content)];
        });
    }

    private static function text($text, $path = '')
    {
        if (strlen($text) > 2097152 || strpos($text, "\0") !== false || preg_match('//u', $text) !== 1) throw new \InvalidArgumentException('Workspace edits require UTF-8 text without NUL bytes, at most 2 MiB');
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php' || preg_match('/^#![^\r\n]*\bphp\b/', $text)) {
            try { token_get_all($text, TOKEN_PARSE); }
            catch (\ParseError $error) { throw new \RuntimeException('Refusing invalid PHP for ' . $path . ': ' . $error->getMessage()); }
        }
    }

    /**
     * Run a command as literal argv, never shell syntax, in this local workspace. Requires operator approval matching the first three argv words. Output is bounded; timeout and cancellation stop the process group.
     * @param array $command Non-empty list of string arguments, e.g. ["php", "-l", "classes/Example.php"]
     * @param int $timeout Maximum seconds, 1..120
     */
    public static function run(array $command, int $timeout = 30)
    {
        $command = WorkspaceAccess::command($command); $root = WorkspaceAccess::root();
        if ($timeout < 1 || $timeout > 120) throw new \InvalidArgumentException('Command timeout must be 1..120 seconds');
        if (!is_executable('/usr/bin/setsid') || !function_exists('posix_kill')) throw new \RuntimeException('Command execution requires setsid and PHP POSIX support for safe cancellation');
        $shell = 'exec /usr/bin/setsid ' . implode(' ', array_map('escapeshellarg', $command));
        $process = WorkspaceAccess::authorized('command', $command, function () use ($shell, $root, &$pipes) {
            return proc_open($shell, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $root);
        });
        if (!is_resource($process)) throw new \RuntimeException('Cannot launch command');
        foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
        $start = microtime(true); $out = ''; $err = ''; $truncated = false; $timedOut = false; $cancelled = false; $exit = null; $pid = proc_get_status($process)['pid'];
        try {
            WorkspaceAccess::trackProcess($pid);
            do {
                foreach ([1, 2] as $index) {
                    $chunk = stream_get_contents($pipes[$index], 32768);
                    if ($index === 1) { $remaining = 32768 - strlen($out); if (strlen($chunk) > $remaining) $truncated = true; $out .= substr($chunk, 0, $remaining); }
                    else { $remaining = 32768 - strlen($err); if (strlen($chunk) > $remaining) $truncated = true; $err .= substr($chunk, 0, $remaining); }
                }
                $status = proc_get_status($process);
                if (!$status['running']) { $exit = $status['exitcode']; break; }
                $control = \Agent\ToolContext::get('control');
                $cancelled = $control && call_user_func($control) >= 2;
                $timedOut = microtime(true) - $start >= $timeout;
                if ($cancelled || $timedOut) {
                    posix_kill(-$pid, SIGKILL); proc_terminate($process, 9); break;
                }
                usleep(50000);
            } while (true);
        } finally {
            // Kill any background descendants left by an otherwise completed command too.
            @posix_kill(-$pid, SIGKILL);
            WorkspaceAccess::trackProcess($pid, false);
            foreach ([1, 2] as $index) {
                $chunk = stream_get_contents($pipes[$index], 32769); fclose($pipes[$index]);
                if ($index === 1) { if (strlen($out) + strlen($chunk) > 32768) $truncated = true; $out .= substr($chunk, 0, 32768 - strlen($out)); }
                else { if (strlen($err) + strlen($chunk) > 32768) $truncated = true; $err .= substr($chunk, 0, 32768 - strlen($err)); }
            }
            $closed = proc_close($process); if ($exit === null || $exit < 0) $exit = $closed;
        }
        if ($cancelled) throw new \Agent\Cancelled('Workspace command cancelled');
        \Agent\Log::write($timedOut || $exit !== 0 ? \Agent\Log::ERROR : \Agent\Log::SUCCESS, 'Workspace command finished', ['command' => $command, 'exit_code' => $exit, 'timed_out' => $timedOut]);
        return ['command' => $command, 'exit_code' => $exit, 'stdout' => mb_convert_encoding($out, 'UTF-8', 'UTF-8'), 'stderr' => mb_convert_encoding($err, 'UTF-8', 'UTF-8'), 'timed_out' => $timedOut, 'truncated' => $truncated];
    }
}

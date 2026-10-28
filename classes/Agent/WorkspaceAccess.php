<?php
namespace Agent;

/** Local workspace boundaries and operator-issued, per-thread grants. Not an agent tool. */
class WorkspaceAccess
{
    public static function root()
    {
        $unix = Runtime::unixIdentity();
        if (!Runtime::isCli() || ToolContext::get('frontend') || $unix['root'] || $unix['uid'] === null) throw new \RuntimeException('Workspace tools require an unprivileged local CLI process');
        $root = realpath(getcwd());
        if (!$root || $root === DIRECTORY_SEPARATOR) throw new \RuntimeException('A specific local workspace is required');
        return $root;
    }

    /** Resolve, never repair, a security-sensitive path. A missing leaf may be created. */
    public static function path($path)
    {
        $root = self::root();
        if (!is_string($path) || trim($path) === '' || $path !== trim($path) || preg_match('/[\x00-\x1f\x7f\\\\]/', $path) || $path[0] === '/' || preg_match('/^[A-Za-z]:/', $path)) throw new \InvalidArgumentException('Use a workspace-relative path without traversal or control bytes');
        $parts = explode('/', $path); $current = $root;
        foreach ($parts as $index => $part) {
            if ($part === '' || $part[0] === '.' || in_array(strtolower($part), ['config', 'storage', 'vendor', 'node_modules'], true)) throw new \InvalidArgumentException('Private or invalid workspace path');
            $current .= '/' . $part;
            if (is_link($current)) throw new \RuntimeException('Workspace paths cannot traverse symlinks');
            if ($index < count($parts) - 1 && !is_dir($current)) throw new \RuntimeException('Workspace parent directory must already exist');
        }
        if (file_exists($current) && !is_file($current)) throw new \RuntimeException('Workspace target must be a regular file');
        return $current;
    }

    /** Commands are argv, not shell text. Whitespace inside an argument is preserved. */
    public static function command($input)
    {
        if (is_string($input)) { $tmp = json_decode($input, true); if (json_last_error() === JSON_ERROR_NONE && is_array($tmp) && $tmp) $input = $tmp; unset($tmp); }
        if (!is_array($input) || !$input || array_keys($input) !== range(0, count($input) - 1) || count($input) > 128) throw new \InvalidArgumentException('Command must be a non-empty JSON argv list, not shell text');
        foreach ($input as $argument) if (!is_string($argument) || strpos($argument, "\0") !== false || strlen($argument) > 32768) throw new \InvalidArgumentException('Command arguments must be bounded strings without NUL bytes');
        if ($input[0] === '' || preg_match('/[\x00-\x20\x7f]/', $input[0])) throw new \InvalidArgumentException('Command executable must be a name or path without whitespace');
        return $input;
    }

    private static function key($kind, $target)
    {
        return hash('sha256', self::root() . "\0" . ($kind === 'command' ? 'command' : 'file') . "\0" . ($kind === 'command' ? State::encode(array_slice(self::command($target), 0, 3)) : $target));
    }

    /** Called by a human CLI adapter after ownership checks; models cannot approve their own tools. */
    public static function approve($threadId, $kind, $target)
    {
        return self::change($threadId, $kind, $target, false);
    }

    public static function revoke($threadId, $kind, $target)
    {
        return self::change($threadId, $kind, $target, true);
    }

    private static function change($threadId, $kind, $target, $revoke)
    {
        if (ToolContext::get('run_id')) throw new \RuntimeException('An agent cannot approve its own workspace access');
        if (!in_array($kind, ['read', 'patch', 'command'], true)) throw new \InvalidArgumentException('Unknown workspace approval kind');
        $thread = new Thread($threadId);
        $identity = Cli::identity();
        if ((string)$thread->get()['owner_id'] !== (string)$identity['owner']) throw new \RuntimeException('Only the local thread owner may approve workspace access');
        if ($kind === 'command') $target = self::command($target); else self::path($target);
        $key = self::key($kind, $target);
        return State::transaction(function () use ($thread, $key, $kind, $target, $identity, $revoke) {
            Database::execute('SELECT id FROM threads WHERE id = ? FOR UPDATE', [$thread->id]);
            $state = State::thread($thread->id); $value = $state->read()['value'];
            $old = isset($value['approvals'][$key]) ? $value['approvals'][$key] : null;
            if ($revoke) {
                unset($value['approvals'][$key]);
                $state->patch(['approvals' => (object)(isset($value['approvals']) ? $value['approvals'] : [])]);
                Log::writeSuccess('Workspace access revoked', ['thread_id' => $thread->id, 'kind' => $kind, 'target' => $target]);
                return ['revoked' => true, 'target' => $target];
            }
            $grant = ['kind' => $old && $old['kind'] === 'patch' ? 'patch' : $kind, 'target' => $kind === 'command' ? array_slice($target, 0, 3) : $target, 'workspace' => self::root(), 'unix_uid' => $identity['unix']['uid']];
            $state->mergeMap('approvals', [$key => $grant]);
            Log::writeSuccess('Workspace access approved', ['thread_id' => $thread->id, 'kind' => $grant['kind'], 'target' => $grant['target']]);
            return $grant;
        });
    }

    public static function requireGrant($kind, $target)
    {
        $threadId = ToolContext::get('thread_id');
        if (!$threadId) throw new \RuntimeException('Workspace changes and commands require a saved thread and operator approval');
        $key = self::key($kind, $target); $value = State::thread($threadId)->read()['value'];
        $grant = isset($value['approvals'][$key]) ? $value['approvals'][$key] : null;
        if (!$grant || ($kind === 'patch' && $grant['kind'] !== 'patch') || $grant['unix_uid'] !== Runtime::unixIdentity()['uid']) throw new \RuntimeException('Operator approval required for ' . $kind . ': ' . (is_array($target) ? State::encode(array_slice($target, 0, 3)) : $target) . '. Ask the operator; do not retry until approved.');
    }

    /** Serialize grant changes with file mutations/command acceptance, not the command's lifetime. */
    public static function authorized($kind, $target, $callback)
    {
        return State::transaction(function () use ($kind, $target, $callback) {
            Database::row('SELECT id FROM threads WHERE id = ? FOR UPDATE', [ToolContext::get('thread_id')]);
            self::requireGrant($kind, $target);
            return call_user_func($callback);
        });
    }

    /** Durable child-process ownership lets a supervisor clean up even after a forced worker kill. */
    public static function trackProcess($pid, $active = true)
    {
        $run = ToolContext::get('run_id');
        if (!$run) return;
        $state = State::run($run)->child('local.processes', (string)$run);
        $record = $active ? ['hostname' => gethostname(), 'unix_uid' => Runtime::unixIdentity()['uid'], 'start' => self::processStart($pid)] : null;
        $state->mergeMap('groups', [(string)$pid => $record]);
    }

    public static function stopProcesses($runId)
    {
        $value = State::run($runId)->child('local.processes', (string)$runId)->read()['value'];
        foreach (isset($value['groups']) ? $value['groups'] : [] as $pid => $record) {
            if (!$record || $record['hostname'] !== gethostname() || $record['unix_uid'] !== Runtime::unixIdentity()['uid'] || !$record['start'] || $record['start'] !== self::processStart($pid)) continue;
            @posix_kill(-(int)$pid, SIGKILL);
        }
    }

    private static function processStart($pid)
    {
        $stat = @file_get_contents('/proc/' . (int)$pid . '/stat');
        if ($stat === false) return null;
        $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
        return isset($fields[19]) ? $fields[19] : null;
    }
}

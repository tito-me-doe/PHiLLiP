<?php
namespace Agent;

/** Owner-authored evidence plus fixed, read-only local Git observations. */
class ProjectEvidence
{
    private static function git(array $args, array $ok = [0])
    {
        $argv = array_merge(['git', '-c', 'core.fsmonitor=false'], $args);
        $process = proc_open($argv, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, WorkspaceAccess::root());
        if (!is_resource($process)) throw new \RuntimeException('Could not inspect Git workspace');
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1], 4096); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2], 4096); fclose($pipes[2]);
        $status = proc_close($process);
        if (!in_array($status, $ok, true)) throw new \RuntimeException('Git inspection failed: ' . trim($error));
        return [$status, trim($out)];
    }

    public static function record(Project $project, $todoId, $evidence)
    {
        if (!Runtime::isCli() || ToolContext::get('run_id') !== null || ToolContext::get('agent_id') !== null || ToolContext::get('frontend')) throw new \RuntimeException('Evidence requires the local owner');
        $identity = ProjectAccess::local($project);
        if ($identity['unix']['root'] || $identity['unix']['uid'] === null) throw new \RuntimeException('Evidence requires an unprivileged local owner');
        if (!is_scalar($todoId) || !preg_match('/^[1-9][0-9]*$/D', (string)$todoId)) throw new \InvalidArgumentException('ToDo must be a positive integer');
        if (!$evidence instanceof \stdClass) throw new \InvalidArgumentException('Evidence must be a JSON object');
        $json = State::encode($evidence);
        if (strlen($json) > 65536) throw new \InvalidArgumentException('Evidence is too large');
        foreach ($evidence as $key => $value) if (!in_array($key, ['lint', 'test', 'review', 'remote_identity'], true) || !is_string($value) || strlen($value) > 16000) throw new \InvalidArgumentException('Evidence fields must be bounded strings: lint, test, review, remote_identity');
        foreach (['lint', 'test', 'review'] as $key) if (!isset($evidence->$key) || trim($evidence->$key) === '') throw new \InvalidArgumentException('Evidence requires lint, test, and review descriptions');
        return State::transaction(function () use ($project, $todoId, $identity, $evidence) {
            $p = Database::row('SELECT revision FROM projects WHERE id = ? FOR UPDATE', [$project->id]);
            $todo = Database::row('SELECT revision FROM project_todos WHERE id = ? AND project_id = ? FOR UPDATE', [$todoId, $project->id]);
            if (!$todo) throw new \RuntimeException('ToDo not found in this project');
            $link = Database::row('SELECT id, run_id, todo_revision FROM project_todo_worker_links WHERE project_id = ? AND todo_id = ? ORDER BY id DESC LIMIT 1', [$project->id, $todoId]);
            if (!$link || (string)$link['todo_revision'] !== (string)$todo['revision']) throw new \RuntimeException('A current worker link is required for evidence');
            $root = self::git(['rev-parse', '--show-toplevel'])[1];
            if (realpath($root) !== WorkspaceAccess::root()) throw new \RuntimeException('Run from the Git workspace root');
            $head = self::git(['rev-parse', '--verify', 'HEAD'])[1];
            if (!preg_match('/^[a-f0-9]{40,64}$/D', $head)) throw new \RuntimeException('Invalid Git HEAD');
            $dirty = self::git(['diff', '--no-ext-diff', '--no-textconv', '--quiet', 'HEAD', '--'], [0, 1])[0] === 1;
            $data = ['actor' => (string)$identity['owner'], 'project_revision' => (int)$p['revision'], 'todo_revision' => (int)$todo['revision'], 'link_id' => (int)$link['id'], 'run_id' => (int)$link['run_id'], 'workspace' => $root, 'git_head' => $head, 'tracked_dirty' => $dirty, 'observed_at' => gmdate('c'), 'owner_attestation' => $evidence];
            $data['event_id'] = $project->event('engineering.evidence', $data, $todoId);
            return $data;
        });
    }
}

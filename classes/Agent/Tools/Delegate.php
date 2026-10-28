<?php
namespace Agent\Tools;

/** Local subagents have isolated conversations, copied personas, and no additional tool authority. */
class Delegate
{
    /**
     * Create a named subagent. Persona is a saved name/ID or a definition with name, prompt, optional tools and contract. Parent tool grants are a strict ceiling. No task starts until assign is called.
     * @param string $name Unique name for this agent in the team
     * @param mixed $persona Saved persona name/ID or object with name and prompt
     * @param string $profile Profile name, empty inherits the current parent's model configuration
     * @param string $tools Optional CSV tool class or method names; empty uses persona defaults
     */
    public static function create(string $name, $persona, string $profile = '', string $tools = '')
    {
        $options = ['persona' => $persona];
        if (trim($profile) !== '') $options['profile'] = $profile;
        if (trim($tools) !== '') $options['tools'] = $tools;
        return \Agent\Agents::create($name, $options);
    }

    /** List the subagents managed by this agent, including current status and queued tasks. */
    public static function agents() { return \Agent\Agents::all(); }

    /**
     * Queue an isolated task or follow-up. Each agent processes its tasks sequentially; different agents work in parallel.
     * @param string $agent Agent ID or unique name returned by create
     * @param string $task Complete task brief with exact workspace-relative source paths, needed evidence and expected deliverable; parent history is never shared
     * @param string $key Optional stable submission key to make retries idempotent
     */
    public static function assign(string $agent, string $task, string $key = '') { return \Agent\Agents::assign($agent, $task, $key === '' ? null : $key); }

    /**
     * Inspect a managed subagent's status, queue, workspace and identity.
     * @param string $agent Agent ID or unique name
     */
    public static function status(string $agent) { return \Agent\Agents::get($agent); }

    /**
     * Get a task's status, final answer and error. An omitted run ID selects the latest task.
     * @param string $agent Agent ID or unique name
     * @param int $runId Run ID from assign, or zero for latest
     */
    public static function result(string $agent, int $runId = 0) { return \Agent\Agents::result($agent, $runId > 0 ? $runId : null); }

    /**
     * Wait at most 30 seconds for an assigned task. A running/queued result is not a final answer: wait or poll again.
     * @param string $agent Agent ID or unique name
     * @param int $runId Run ID from assign, or zero for latest
     * @param int $seconds Wait duration, 0 through 30 seconds
     */
    public static function wait(string $agent, int $runId = 0, int $seconds = 30) { return \Agent\Agents::wait($agent, $runId > 0 ? $runId : null, max(0, min(30, $seconds))); }

    /**
     * Pause new tasks; an active response continues.
     * @param string $agent Agent ID or unique name
     */
    public static function pause(string $agent) { return \Agent\Agents::pause($agent); }

    /**
     * Resume paused tasks.
     * @param string $agent Agent ID or unique name
     */
    public static function resume(string $agent) { return \Agent\Agents::resume($agent); }

    /**
     * Stop active work progressively on repeated calls and cancel all queued tasks.
     * @param string $agent Agent ID or unique name
     */
    public static function stop(string $agent) { return \Agent\Agents::stop($agent); }
}

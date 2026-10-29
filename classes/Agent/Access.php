<?php
namespace Agent;

class Access
{
    public static function predicate($userId, $alias = 't')
    {
        if ($userId instanceof MemoryAudience) {
            return $userId->threadId === null
                ? ["$alias.visibility = 'organization'", []]
                : ["($alias.visibility = 'organization' OR $alias.id = ?)", [$userId->threadId]];
        }
        if ($userId === null) {
            return ['TRUE', []];
        }
        self::authenticated($userId);
        $teams = array_column(Identity::teams($userId), 'id');
        $sql = "($alias.visibility = 'organization' OR ($alias.visibility = 'private' AND $alias.owner_id = ?)";
        if ($teams) {
            $sql .= " OR ($alias.visibility = 'team' AND $alias.team_id IN (" . implode(',', array_fill(0, count($teams), '?')) . '))';
        }
        return [$sql . ')', array_merge([(string) $userId], $teams)];
    }
    public static function authenticated($userId)
    {
        if ($userId === null || $userId === '') {
            throw new \RuntimeException('Authentication required');
        }
        $user = Identity::user(['id' => $userId]);
        if (!$user || !$user['active']) {
            throw new \RuntimeException('Authentication required');
        }
        return $user;
    }

    public static function admin($userId)
    {
        $user = self::authenticated($userId);
        if (!$user['is_admin']) {
            throw new \RuntimeException('Administrator permission required');
        }
        return $user;
    }

    public static function inTeam($userId, $teamId)
    {
        foreach (Identity::teams($userId) as $team) {
            if ((string) $team['id'] === (string) $teamId) {
                return true;
            }
        }
        return false;
    }

    public static function visible($userId, array $resource)
    {
        if ($userId === null || $userId === '') {
            return false;
        }
        $user = Identity::user(['id' => $userId]);
        if (!$user || !$user['active']) {
            return false;
        }
        if ($resource['visibility'] === 'private') {
            return (string) $resource['owner_id'] === (string) $userId;
        }
        if ($resource['visibility'] === 'team') {
            return self::inTeam($userId, $resource['team_id']);
        }
        return $resource['visibility'] === 'organization';
    }

    public static function read($userId, Thread $thread)
    {
        $project = Project::forThread($thread->id);
        $resource = $project ? $project->get() : $thread->get();
        if ($userId instanceof MemoryAudience) {
            if ($resource['visibility'] === 'organization' || ($userId->threadId !== null && $thread->id === $userId->threadId)) {
                return;
            }
            throw new \RuntimeException('Thread access denied');
        }
        Pages::authorize($userId, $project ? 'projects' : 'conversations');
        if (!self::visible($userId, $resource)) {
            throw new \RuntimeException('Thread access denied');
        }
    }

    public static function owner($userId, Thread $thread)
    {
        self::read($userId, $thread);
        if ((string) $thread->get()['owner_id'] !== (string) $userId) {
            throw new \RuntimeException('Only the thread creator may change chat settings');
        }
    }

    public static function editMessage($userId, Thread $thread, $messageId)
    {
        self::read($userId, $thread);
        foreach ($thread->messages() as $message) {
            if ($message['id'] == $messageId) {
                if ($message['role'] !== 'user' || (string) $message['author_id'] !== (string) $userId) {
                    throw new \RuntimeException('Only the message author may edit a user message');
                }
                return;
            }
        }
        throw new \RuntimeException('Message not found');
    }

    /** Active Administrators have all permissions; other users require additive grants. */
    public static function allows($userId, $resource, $default = false)
    {
        $resource = ltrim($resource, '\\');
        return self::decisions($userId, [$resource => $default])[$resource];
    }

    /** Batch a render's policy reads, without caching mutable authorization between actions. */
    public static function decisions($userId, array $defaults)
    {
        $result = array_fill_keys(array_keys($defaults), false);
        if ($userId === null || $userId === '') {
            return $result;
        }
        $user = Identity::user(['id' => $userId]);
        if (!$user || !$user['active']) {
            return $result;
        }
        if ($user['is_admin']) {
            return array_fill_keys(array_keys($defaults), true);
        }
        $teams = array_map('strval', array_column(Identity::teams($userId), 'id'));
        foreach (Mappings::rows('permissions') as $rule) {
            $resource = ltrim($rule['tool'], '\\');
            if (array_key_exists($resource, $result) && $rule['allowed'] &&
                (($rule['scope_type'] === 'user' && (string)$rule['scope_id'] === (string)$userId) ||
                 (in_array($rule['scope_type'], ['team','group'], true) && in_array((string)$rule['scope_id'], $teams, true)))) {
                $result[$resource] = true;
            }
        }
        return $result;
    }

    public static function tool($userId, $tool)
    {
        return self::allows($userId, $tool);
    }

    public static function tools($userId, Thread $thread, array $requested)
    {
        self::read($userId, $thread);
        $ownerId = $thread->get()['owner_id'];
        return array_values(
            array_filter($requested, function ($tool) use ($userId, $ownerId, $thread) {
                $policy = is_string($tool) ? explode('::', $tool)[0] : $tool;
                if ($policy === 'Agent\\Tools\\Project') {
                    return Project::forThread($thread->id) !== null;
                }
                if (in_array($policy, ['Agent\\Tools\\History', 'Agent\\Tools\\Attachments'], true)) {
                    return true;
                }
                return self::tool($userId, $policy) && self::tool($ownerId, $policy);
            })
        );
    }

    public static function personaEdit($userId, array $persona)
    {
        $user = self::authenticated($userId);
        $owner = (string) $persona['owner_id'] === (string) $userId;
        if ($persona['visibility'] === 'private' && !$owner) {
            throw new \RuntimeException('Private persona belongs to its owner');
        }
        if (!$owner && !($user['is_admin'] && ($persona['is_default'] || $persona['visibility'] !== 'private'))) {
            throw new \RuntimeException('Persona edit denied');
        }
    }
}

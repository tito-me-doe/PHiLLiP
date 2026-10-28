<?php
namespace Agent;

/** All frontend collaborators follow the planning thread's existing visibility rules. */
class ProjectAccess
{
    public static function read($userId, Project $project) { Access::read($userId, new Thread($project->get()['thread_id'])); }
    public static function edit($userId, Project $project) { self::read($userId, $project); }
    public static function owner($userId, Project $project) { Access::owner($userId, new Thread($project->get()['thread_id'])); }
    public static function local(Project $project)
    {
        $identity = Cli::identity();
        if ((string)$project->get()['owner_id'] !== (string)$identity['owner']) throw new \RuntimeException('Only the local project owner may operate this project');
        return $identity;
    }
}

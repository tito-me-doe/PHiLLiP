<?php
namespace Agent;

/** User-facing policy boundary. Direct application classes remain unrestricted. */
class Frontend
{
    const TOOLS = [
        'Agent\\Tools\\SearchWeb',
        'Agent\\Tools\\Fetch',
        'Agent\\Tools\\BrowseWeb',
        'Agent\\Tools\\Memory',
        'Agent\\Tools\\Tasks'
    ];
    private $userId;

    public function __construct($userId)
    {
        $resolved = Identity::user($userId);
        $user = Access::authenticated($resolved ? $resolved['id'] : null);
        $this->userId = (string) $user['id'];
    }

    public function thread($id)
    {
        $thread = new Thread($id);
        Access::read($this->userId, $thread);
        return $thread;
    }

    public function threads()
    {
        $chat = Pages::allowed($this->userId, 'conversations');
        $projects = Pages::allowed($this->userId, 'projects');
        if (!$chat && !$projects) {
            return [];
        }
        [$predicate, $params] = Access::predicate($this->userId, 't');
        $kind = $chat && $projects ? 'TRUE' : ($projects ? 'p.id IS NOT NULL' : 'p.id IS NULL');
        return Database::rows(
            "SELECT t.*,p.id AS project_id,p.status AS project_status,
            GREATEST(t.updated_at,COALESCE(p.updated_at,t.created_at),COALESCE((SELECT MAX(m.created_at) FROM messages m WHERE m.thread_id=t.id),t.created_at)) AS activity_at
            FROM threads t LEFT JOIN projects p ON p.thread_id=t.id
            WHERE t.deletedon IS NULL AND COALESCE(t.settings->>'benchmark','false') <> 'true'
              AND NOT EXISTS (SELECT 1 FROM project_todos td WHERE td.thread_id=t.id)
              AND ($predicate) AND ($kind)
            ORDER BY activity_at DESC,t.id DESC",
            $params
        );
    }

    public function create($title = 'New conversation', array $settings = [], $visibility = 'private', $teamId = null, $creationKey = null)
    {
        Pages::authorize($this->userId, ToolContext::get('project_creation') ? 'projects' : 'conversations');
        if ($title instanceof \stdClass) {
            $title = (array) $title;
        }
        if (is_array($title) && isset($title['settings'])) {
            $title['settings'] = Config::object($title['settings'], 'Chat settings');
        }
        if (is_array($title) && isset($title['settings']['persona'])) {
            $title['settings'] = $this->persona($title['settings']['persona'], $title['settings']);
        } elseif (isset($settings['persona'])) {
            $settings = $this->persona($settings['persona'], $settings);
        }
        $options = Thread::options($title, $settings, $visibility, $teamId, $creationKey);
        if (
            array_key_exists('project_frontend', $options['settings']) ||
            array_key_exists('execution_context', $options['settings']) ||
            array_key_exists('project_manual', $options['settings'])
        ) {
            throw new \InvalidArgumentException('Execution identity is managed by the server');
        }
        $options['settings'] = $this->validateSettings($options['settings']);
        $this->validateVisibility($options['visibility'], $options['team_id']);
        $options['title'] = mb_substr($options['title'], 0, 160);
        $thread = Thread::create($this->userId, $options);
        Log::writeDebug('Conversation created or recovered', [
            'user_id' => $this->userId,
            'thread_id' => $thread->id,
            'visibility' => $options['visibility']
        ]);
        return $thread;
    }

    public function send($id, $message)
    {
        $message = Message::normalize($message);
        if (!isset($message['submission_key'])) {
            throw new \InvalidArgumentException('A stable submission_key is required to prevent duplicate submissions');
        }
        Submission::key($message['submission_key']);
        if (!empty($message['attachments'])) {
            foreach ($message['attachments'] as $file) {
                $this->attachment($file);
            }
        }
        $this->planningThread($id);
        $project = Project::forThread($id);
        $run = $project ? $project->plan($this->userId, $message, true) : $this->thread($id)->enqueue($this->userId, $message, true);
        Log::writeDebug('Submission accepted or recovered', [
            'user_id' => $this->userId,
            'thread_id' => $run['thread_id'],
            'run_id' => $run['id'],
            'status' => $run['status']
        ]);
        return $run;
    }

    public function attachment($id)
    {
        $file = Attachments::get($id);
        $id = $file['id'];
        $q = Database::connection()->prepare(
            'SELECT DISTINCT t.* FROM threads t JOIN messages m ON m.thread_id = t.id JOIN attachment_links l ON l.message_id = m.id WHERE l.attachment_id = ?'
        );
        $q->execute([$id]);
        $threads = $q->fetchAll();
        if (!$threads && $file['owner_id'] === $this->userId) {
            return $file;
        }
        foreach ($threads as $thread) {
            if (Access::visible($this->userId, $thread)) {
                return $file;
            }
        }
        throw new \RuntimeException('Attachment access denied');
    }

    public function discardAttachment($id)
    {
        $file = $this->attachment($id);
        $id = $file['id'];
        if ($file['owner_id'] !== $this->userId) {
            throw new \RuntimeException('Only the upload owner may discard it');
        }
        // Unselecting a recovered draft must never remove a file already committed to history.
        $q = Database::connection()->prepare('SELECT 1 FROM attachment_links WHERE attachment_id = ?');
        $q->execute([$id]);
        if ($q->fetchColumn()) {
            return;
        }
        Attachments::discard($id);
    }

    public function configure($id, array $settings, $systemPrompt = null)
    {
        $this->planningThread($id);
        if (
            array_key_exists('project_frontend', $settings) ||
            array_key_exists('execution_context', $settings) ||
            array_key_exists('project_manual', $settings)
        ) {
            throw new \InvalidArgumentException('Execution identity is managed by the server');
        }
        $thread = $this->thread($id);
        Access::owner($this->userId, $thread);
        $currentSettings = $thread->get()['settings'];
        if (isset($settings['persona'])) {
            $settings = $this->persona($settings['persona'], $settings);
        } elseif (isset($currentSettings['persona_defaults'])) {
            $settings['persona_defaults'] = $currentSettings['persona_defaults'];
            foreach (['tools', 'contract'] as $key) {
                if (array_key_exists($key, $settings)) {
                    $settings['persona_defaults'][$key] = $settings[$key];
                }
            }
        }
        $validated = $this->validateSettings(array_replace($currentSettings, $settings));
        if (array_key_exists('profile', $settings)) {
            $settings['profile'] = $validated['profile'];
        }
        foreach (['tools', 'contract', 'persona_defaults'] as $key) {
            if (array_key_exists($key, $settings)) {
                $settings[$key] = $validated[$key];
            }
        }
        State::transaction(function () use ($thread, $settings, $systemPrompt) {
            $project = Project::forThread($thread->id);
            if ($project) {
                $project->invalidatePlan();
            }
            $thread->configure($settings, $systemPrompt);
        });
        Log::writeDebug('Conversation settings updated', [
            'user_id' => $this->userId,
            'thread_id' => $thread->id,
            'fields' => array_keys($settings),
            'system_prompt_changed' => $systemPrompt !== null
        ]);
    }

    /** Prepare authorized persona defaults for a draft or existing conversation. No authority is granted. */
    public function persona($reference, array $settings = [])
    {
        $persona = Personas::resolve($reference, $this->userId);
        unset($settings['persona']);
        return $this->validateSettings(Personas::settings($persona, $settings));
    }

    public function share($id, $visibility, $teamId = null)
    {
        $this->planningThread($id);
        $thread = $this->thread($id);
        Access::owner($this->userId, $thread);
        if ($visibility === 'team' && $teamId === null && $thread->get()['visibility'] === 'team') {
            $teamId = $thread->get()['team_id'];
        }
        $this->validateVisibility($visibility, $teamId);
        State::transaction(function () use ($thread, $visibility, $teamId) {
            $project = Project::forThread($thread->id);
            if ($project) {
                $project->invalidatePlan();
                Database::execute(
                    'UPDATE threads SET visibility = ?, team_id = ? WHERE id IN (SELECT thread_id FROM project_todos WHERE project_id = ?)',
                    [$visibility, $teamId, $project->id]
                );
            }
            Database::execute('UPDATE threads SET visibility = ?, team_id = ? WHERE id = ?', [$visibility, $teamId, $thread->id]);
        });
        Log::writeDebug('Conversation sharing updated', [
            'user_id' => $this->userId,
            'thread_id' => $thread->id,
            'visibility' => $visibility,
            'team_id' => $teamId
        ]);
    }

    public function edit($id, $messageId, $content)
    {
        $this->planningThread($id);
        $thread = $this->thread($id);
        Access::editMessage($this->userId, $thread, $messageId);
        $run = State::transaction(function () use ($thread, $messageId, $content) {
            $project = Project::forThread($thread->id);
            if ($project) {
                $project->invalidatePlan();
            }
            return $thread->edit($messageId, $content);
        });
        Log::writeDebug('Message edit queued', [
            'user_id' => $this->userId,
            'thread_id' => $thread->id,
            'message_id' => $messageId,
            'run_id' => $run['id']
        ]);
        return $run;
    }

    public function stop($runId)
    {
        $run = Run::get($runId);
        $this->thread($run['thread_id']);
        $level = Run::requestStop($runId);
        Log::writeDebug('Run stop requested', [
            'user_id' => $this->userId,
            'thread_id' => $run['thread_id'],
            'run_id' => $runId,
            'level' => $level
        ]);
        return $level;
    }

    public function delete($id)
    {
        $this->planningThread($id);
        $thread = $this->thread($id);
        Access::owner($this->userId, $thread);
        $project = Project::forThread($thread->id);
        if ($project) {
            throw new \RuntimeException('Manage projects from the Projects screen');
        }
        State::transaction(function () use ($thread) {
            Database::row('SELECT id FROM threads WHERE id = ? FOR UPDATE', [$thread->id]);
            if (Database::row("SELECT id FROM runs WHERE thread_id = ? AND status = 'running'", [$thread->id])) {
                throw new \RuntimeException('Stop the active run before deleting the conversation');
            }
            foreach (
                Database::rows(
                    "UPDATE runs SET status = 'stopped', finished_at = NOW() WHERE thread_id = ? AND status = 'queued' RETURNING id",
                    [$thread->id]
                )
                as $run
            ) {
                Run::event($run['id'], [
                    'type' => 'run.stopped',
                    'data' => ['reason' => 'Conversation deleted']
                ]);
            }
            Database::execute('UPDATE threads SET deletedon = COALESCE(deletedon, NOW()) WHERE id = ?', [$thread->id]);
        });
        Log::writeDebug('Conversation hidden; history retained', [
            'user_id' => $this->userId,
            'thread_id' => $thread->id
        ]);
    }

    public function rename($id, $title)
    {
        $thread = $this->thread($id);
        Access::owner($this->userId, $thread);
        if (!is_string($title) || preg_match('//u', $title) !== 1) {
            throw new \InvalidArgumentException('Title must be valid text');
        }
        $title = trim(preg_replace('/[\x00-\x1f\x7f]/u', ' ', $title));
        if ($title === '' || mb_strlen($title) > 160) {
            throw new \InvalidArgumentException('Title must be between 1 and 160 characters');
        }
        Database::execute('UPDATE threads SET title = ? WHERE id = ?', [$title, $thread->id]);
        Log::writeDebug('Conversation renamed', [
            'user_id' => $this->userId,
            'thread_id' => $thread->id
        ]);
        return $thread->get();
    }

    /** Execution histories are inspectable; the scheduler alone queues and edits their work. */
    private function planningThread($id)
    {
        $this->thread($id);
        $project = Project::forThread($id);
        if ($project && (string) $project->get()['thread_id'] !== (string) $id) {
            throw new \RuntimeException('Project execution conversations are read-only; use the project plan');
        }
    }

    private function validateVisibility($visibility, $teamId)
    {
        if (!in_array($visibility, ['private', 'team', 'organization'], true)) {
            throw new \InvalidArgumentException('Invalid visibility');
        }
        if ($visibility === 'team' && !Access::inTeam($this->userId, $teamId)) {
            throw new \RuntimeException('You must belong to the selected team');
        }
        if ($visibility !== 'team' && $teamId !== null) {
            throw new \InvalidArgumentException('Choose one visibility scope');
        }
    }

    private function validateSettings(array $settings)
    {
        if (
            isset($settings['assistant_name']) &&
            (!is_string($settings['assistant_name']) ||
                trim($settings['assistant_name']) === '' ||
                mb_strlen($settings['assistant_name']) > 160)
        ) {
            throw new \InvalidArgumentException('Assistant name must be nonempty text of at most 160 characters');
        }
        if (array_key_exists('contract', $settings)) {
            $settings['contract'] =
                $settings['contract'] === null || (is_string($settings['contract']) && trim($settings['contract']) === '')
                    ? null
                    : Schema::input(Config::object($settings['contract'], 'Response contract'));
        }
        if (isset($settings['persona_defaults'])) {
            $defaults = Config::object($settings['persona_defaults'], 'Persona defaults');
            $settings['persona_defaults'] = [
                'tools' => ToolRegistry::names(isset($defaults['tools']) ? $defaults['tools'] : []),
                'contract' => !empty($defaults['contract'])
                    ? Schema::input(Config::object($defaults['contract'], 'Persona contract'))
                    : null
            ];
        }
        if (isset($settings['profile'])) {
            $profile = (new Registry())->profile($settings['profile']);
            if (!$profile || !$profile['enabled']) {
                throw new \RuntimeException('Select a model profile enabled and tested by an administrator');
            }
            // Row-shaped references may identify a profile, never supply frontend execution settings.
            $settings['profile'] = $profile['name'];
        }
        if (array_key_exists('tools', $settings)) {
            $settings['tools'] = ToolRegistry::names($settings['tools']);
        }
        foreach (
            ToolRegistry::policies(
                array_merge(
                    isset($settings['tools']) ? $settings['tools'] : [],
                    isset($settings['persona_defaults']['tools']) ? $settings['persona_defaults']['tools'] : []
                )
            )
            as $policy
        ) {
            if (!in_array($policy, self::TOOLS, true) || !Access::tool($this->userId, $policy)) {
                throw new \RuntimeException('Tool selection is not permitted: ' . $policy);
            }
        }
        foreach (isset($settings['parameters']) ? $settings['parameters'] : [] as $key => $value) {
            if (!in_array($key, ['temperature', 'top_p', 'min_p', 'max_tokens', 'seed', 'reasoning_effort', 'stream'], true)) {
                throw new \InvalidArgumentException('Unsupported chat parameter: ' . $key);
            }
            if (
                in_array($key, ['temperature', 'top_p', 'min_p'], true) &&
                (!is_numeric($value) || $value < 0 || $value > ($key === 'temperature' ? 2 : 1))
            ) {
                throw new \InvalidArgumentException('Invalid sampling parameter: ' . $key);
            }
            if ($key === 'max_tokens' && (!is_numeric($value) || $value < 1 || $value > 1048576)) {
                throw new \InvalidArgumentException('Invalid max_tokens');
            }
        }
        return $settings;
    }
}

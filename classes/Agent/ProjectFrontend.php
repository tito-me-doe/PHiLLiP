<?php
namespace Agent;

/** Authenticated web boundary for projects. The core Project API remains trusted. */
class ProjectFrontend
{
    private $userId;
    private $frontend;

    public function __construct($user)
    {
        $resolved = Identity::user($user);
        $authenticated = Access::authenticated($resolved ? $resolved['id'] : null);
        $this->userId = (string) $authenticated['id'];
        Pages::authorize($this->userId, 'projects');
        $this->frontend = new Frontend($authenticated);
    }

    public function project($id)
    {
        $project = new Project($id);
        ProjectAccess::read($this->userId, $project);
        return $project;
    }

    public function projects()
    {
        $rows = Database::connection()
            ->query(
                'SELECT p.*, t.title, t.owner_id, t.visibility, t.team_id FROM projects p JOIN threads t ON t.id = p.thread_id ORDER BY p.id DESC'
            )
            ->fetchAll();
        return array_values(
            array_filter($rows, function ($row) {
                return Access::visible($this->userId, $row);
            })
        );
    }

    private function settings(array $settings)
    {
        $allowed = ['profile', 'tools', 'parameters', 'persona', 'assistant_name', 'contract', 'system_prompt'];
        foreach ($settings as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException('Unsupported project setting: ' . $key);
            }
        }
        return $settings;
    }

    public function create(array $body)
    {
        $title = isset($body['title']) ? $body['title'] : (isset($body['name']) ? $body['name'] : 'New project');
        if (!is_string($title) || trim($title) === '') {
            throw new \InvalidArgumentException('Enter a project title');
        }
        $settings = $this->settings(isset($body['settings']) ? Config::object($body['settings'], 'Project settings') : []);
        if (isset($body['profile'])) {
            $settings['profile'] = $body['profile'];
        }
        if (isset($body['tools'])) {
            $settings['tools'] = $body['tools'];
        }
        $visibility = isset($body['visibility']) ? $body['visibility'] : 'private';
        $team = $visibility === 'team' && !empty($body['team_id']) ? $body['team_id'] : null;
        $options = [
            'title' => trim($title),
            'settings' => $settings,
            'visibility' => $visibility,
            'team_id' => $team
        ];
        if (!empty($body['creation_key'])) {
            $options['creation_key'] = $body['creation_key'];
        }
        return State::transaction(function () use ($options) {
            $thread = ToolContext::withValues(
                array_replace(ToolContext::values(), [
                    'project_creation' => true
                ]),
                function () use ($options) {
                    return $this->frontend->create($options);
                }
            );
            $thread->configure(['project_frontend' => true]);
            return ToolContext::withValues(array_replace(ToolContext::values(), ['frontend' => true]), function () use ($thread) {
                return Project::create($this->userId, [
                    'thread_id' => $thread->id
                ]);
            });
        });
    }

    private function todoPayload(array $body)
    {
        $payload = [];
        foreach (['title', 'goal', 'requirements', 'context', 'gates', 'parent_id', 'persona_id', 'max_attempts'] as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $body[$field];
            if ($field === 'gates' && is_string($value)) {
                $value = array_values(
                    array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value)), function ($line) {
                        return $line !== '';
                    })
                );
            }
            if (in_array($field, ['parent_id', 'persona_id'], true) && $value === '') {
                $value = null;
            }
            $payload[$field] = $value;
        }
        if (!empty($payload['persona_id'])) {
            $persona = Personas::resolve($payload['persona_id'], $this->userId);
            $this->frontend->persona($persona['id']);
            $payload['persona_id'] = $persona['id'];
        }
        return $payload;
    }

    public function action($id, $action, array $body = [], $todoId = null)
    {
        $project = $this->project($id);
        if ($action === 'decisions') {
            return (new ProjectWorkflow($project))->decisions();
        }
        ProjectAccess::edit($this->userId, $project);
        if ($action === 'add') {
            $project->addTodo($this->todoPayload($body));
        } elseif ($action === 'update') {
            $project->updateTodo($todoId, $this->todoPayload($body));
        } elseif ($action === 'remove') {
            $project->removeTodo($todoId, !empty($body['descendants']));
        } elseif ($action === 'preflight') {
            $project->requestPreflight();
        } elseif ($action === 'start') {
            ToolContext::withValues(array_replace(ToolContext::values(), ['frontend' => true]), function () use ($project) {
                return $project->start($this->userId);
            });
        } elseif ($action === 'pause') {
            $project->pause($this->userId);
        } elseif ($action === 'retry') {
            $project->retryTodo($todoId !== null ? $todoId : (isset($body['todo_id']) ? $body['todo_id'] : null));
        } elseif ($action === 'settings') {
            ProjectAccess::owner($this->userId, $project);
            $settings = $this->settings(Config::object(isset($body['settings']) ? $body['settings'] : [], 'Project settings'));
            foreach (['profile', 'tools'] as $field) {
                if (array_key_exists($field, $body)) {
                    $settings[$field] = $body[$field];
                }
            }
            $this->frontend->configure(
                $project->get()['thread_id'],
                $settings,
                isset($body['system_prompt']) ? $body['system_prompt'] : null
            );
        } elseif ($action === 'share') {
            ProjectAccess::owner($this->userId, $project);
            $this->frontend->share(
                $project->get()['thread_id'],
                $body['visibility'],
                isset($body['team_id']) && $body['visibility'] === 'team' ? $body['team_id'] : null
            );
        } elseif ($action === 'delete') {
            ProjectAccess::owner($this->userId, $project);
            $this->frontend->delete($project->get()['thread_id']);
            return null;
        } elseif ($action === 'answer') {
            $decisionId = isset($body['decision_id']) ? $body['decision_id'] : null;
            if (!$decisionId) {
                throw new \InvalidArgumentException('A decision id is required');
            }
            $answer = isset($body['answer']) ? $body['answer'] : null;
            if (!is_string($answer) || trim($answer) === '') {
                throw new \InvalidArgumentException('An answer is required');
            }
            (new ProjectWorkflow($project))->answer($decisionId, $answer, $this->userId);
        } else {
            throw new \InvalidArgumentException('Unknown project action');
        }
        return $project;
    }

    public static function form(array $state)
    {
        $action = isset($_POST['action']) ? $_POST['action'] : '';
        Log::writeDebug('Project form action started', ['action' => $action]);
        try {
            if (!hash_equals($state['csrf'], isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
                throw new \RuntimeException('Invalid CSRF token');
            }
            $frontend = new self($state['user']);
            if ($action === 'project-create') {
                $project = $frontend->create($_POST);
            } else {
                $project = $frontend->action(
                    isset($_POST['project_id']) ? $_POST['project_id'] : null,
                    substr($action, 8),
                    $_POST,
                    isset($_POST['todo_id']) ? $_POST['todo_id'] : null
                );
            }
            Log::flash(Log::writeSuccess('Project saved'));
            header('Location: ' . Views::url('?view=projects' . ($project ? '&project=' . $project->id : '')), true, 303);
        } catch (\Throwable $error) {
            Log::trace('Project form action exception', ['action' => $action], $error);
            Log::writeError(Runtime::publicMessage($error));
            throw $error;
        }
    }
}

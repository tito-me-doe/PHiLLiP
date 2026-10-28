<?php
namespace Agent;

class Controller
{
    private static $request = [];

    public static function handle($path)
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        self::$request = ['path' => $path, 'method' => $method, 'started' => microtime(true)];
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            $state = Auth::current();
            $user = $state['user'];
            self::$request['user_id'] = $user ? $user['id'] : null;
            if ($method === 'POST') Log::writeDebug('API action started', ['path' => $path, 'method' => $method, 'user_id' => self::$request['user_id']]);
            $body = [];
            if ($method === 'POST') {
                if (!hash_equals($state['csrf'], isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '')) throw new \RuntimeException('Invalid CSRF token');
                $body = strpos(isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '', 'application/json') !== false ? json_decode(file_get_contents('php://input'), true) : $_POST;
                if (!is_array($body)) throw new \InvalidArgumentException('Invalid JSON request');
                Log::writeDebug('API action validated', ['path' => $path, 'user_id' => $user ? $user['id'] : null]);
                if (strpos($path, '/api/setup') !== 0 && !in_array($path, ['/api/login', '/api/logout'], true)) Log::trace('API action input', ['path' => $path, 'body' => $body, 'files' => $_FILES]);
            }
            if ($path === '/api/state' && $method === 'GET') {
                return self::output(Views::state());
            }
            if ($path === '/api/login' && $method === 'POST') return self::output(Auth::login($body), 'Signed in');
            if ($path === '/api/logout' && $method === 'POST') { Auth::logout(); return self::output(['ok' => true], 'Signed out'); }
            if (Onboarding::pending()) {
                Onboarding::authorize($user, $path === '/api/setup/password');
                if (strpos($path, '/api/setup') !== 0) throw new \RuntimeException('Finish setup before using PHiLLiP');
            }
            if (strpos($path, '/api/setup') === 0) {
                Onboarding::authorize($user, $path === '/api/setup/password');
                if (Installation::get() !== null) { if (!$user) throw new \RuntimeException('Authentication required'); Access::admin($user['id']); }
                if ($path === '/api/setup/status' && $method === 'GET') return self::output(Onboarding::status($user));
                if ($path === '/api/setup/password' && $method === 'POST') { Onboarding::changePassword($user, $body['password'], $body['confirmation']); return self::output(['ok' => true], 'Password changed'); }
                if ($path === '/api/setup/scan' && $method === 'POST') return self::output(Onboarding::scan($user, $body['cidr']), 'Discovery queued');
                if ($path === '/api/setup/endpoint' && $method === 'POST') return self::output(Onboarding::endpoint($user, $body['url'], isset($body['api_key']) ? $body['api_key'] : null), 'Endpoint inspected');
                if ($path === '/api/setup/model' && $method === 'POST') return self::output(Onboarding::selectModel($user, $body['profile_id']), 'Model verification queued');
                if ($path === '/api/setup/finish' && $method === 'POST') { Onboarding::finish($user); return self::output(['ok' => true], 'Ready to chat'); }
                if ($path === '/api/setup/inspect' && $method === 'POST') return self::output(['items' => Mappings::inspect($body['connection'], isset($body['table']) ? $body['table'] : null)], 'Connection inspected');
                if ($path === '/api/setup' && $method === 'POST') {
                    Onboarding::configure($user, $body['configuration']);
                    return self::output(['ok' => true], 'Installation configured');
                }
            }
            if (!$user) { http_response_code(401); return self::output(['error' => 'Authentication required']); }
            $frontend = new Frontend($user);
            if ($path === '/api/projects') {
                $projects = new ProjectFrontend($user);
                if ($method === 'GET') return self::output(['projects' => $projects->projects()]);
                if ($method !== 'POST') throw new \RuntimeException('Method not allowed');
                $project = $projects->create($body);
                return self::output(['project' => $project->get(), 'snapshot' => $project->snapshot()], 'Project created');
            }
            if (preg_match('~^/api/projects/(\d+)(?:/(todos|preflight|start|pause|retry|history|settings|share|delete))?(?:/(\d+))?$~', $path, $matches)) {
                $projects = new ProjectFrontend($user);
                $project = $projects->project((int)$matches[1]);
                $action = isset($matches[2]) ? $matches[2] : '';
                $todoId = isset($matches[3]) ? (int)$matches[3] : null;
                if ($action === '' && $method === 'GET') return self::output(['project' => $project->get(), 'snapshot' => $project->snapshot(), 'issues' => $project->validate()]);
                if ($action === 'history' && $method === 'GET') return self::output(['events' => $project->history(isset($_GET['after']) ? (int)$_GET['after'] : 0)]);
                if ($method !== 'POST') throw new \RuntimeException('Method not allowed');
                if ($action === 'todos') $action = $todoId === null ? 'add' : (!empty($body['delete']) ? 'remove' : 'update');
                $project = $projects->action($matches[1], $action, $body, $todoId);
                return self::output($project ? ['project' => $project->get(), 'snapshot' => $project->snapshot()] : ['ok' => true], 'Project updated');
            }
            if (preg_match('~^/api/projects/(\d+)/decisions(?:/(\d+)/answer)?$~', $path, $matches)) {
                $projects = new ProjectFrontend($user);
                $decisionId = isset($matches[2]) ? (int)$matches[2] : null;
                if ($method === 'GET' && $decisionId === null) return self::output(['decisions' => $projects->action((int)$matches[1], 'decisions')]);
                if ($method !== 'POST' || $decisionId === null) throw new \RuntimeException('Method not allowed');
                $body['decision_id'] = $decisionId;
                $project = $projects->action((int)$matches[1], 'answer', $body);
                return self::output(['project' => $project->get(), 'snapshot' => $project->snapshot()], 'Decision answered');
            }
            if ($path === '/api/attachments' && $method === 'POST') {
                if (empty($_FILES['file'])) throw new \InvalidArgumentException('Select a file within the configured upload limit');
                $file = Attachments::upload($_FILES['file'], $user['id'], isset($body['upload_key']) ? $body['upload_key'] : null);
                return self::output(['attachment' => $file, 'html' => Views::render('attachments', ['files' => [$file], 'pending' => true])], 'Attachment uploaded');
            }
            if (preg_match('~^/api/attachments/([a-f0-9]{32})(/delete)?$~', $path, $matches)) {
                $file = $frontend->attachment($matches[1]);
                if ($method === 'POST' && !empty($matches[2])) { $frontend->discardAttachment($matches[1]); return self::output(['ok' => true], 'Attachment selection cleared'); }
                if ($method !== 'GET' || !empty($matches[2])) throw new \RuntimeException('Method not allowed');
                $data = Attachments::get($matches[1], true);
                header('Content-Type: application/octet-stream'); header('X-Content-Type-Options: nosniff');
                header('Content-Disposition: attachment; filename=\"attachment\"; filename*=UTF-8\'\'' . rawurlencode($file['name']));
                header('Content-Security-Policy: sandbox; default-src \'none\'');
                header('Content-Length: ' . $file['size']); echo base64_decode($data['base64']); return;
            }
            if ($path === '/api/threads') {
                if ($method === 'GET') return self::output(['threads' => $frontend->threads()]);
                if ($method !== 'POST') throw new \RuntimeException('Method not allowed');
                $thread = $frontend->create($body);
                return self::output(['thread' => $thread->get()], 'Conversation created');
            }
            if (preg_match('~^/api/threads/(\d+)(?:/(messages|settings|share|events|delete|persona|title))?$~', $path, $matches)) {
                $id = (int)$matches[1]; $action = isset($matches[2]) ? $matches[2] : '';
                $thread = $frontend->thread($id);
                if ($action === '' && $method === 'GET') {
                    $messages = Views::messageAuthors($thread->messages());
                    $q = Database::connection()->prepare('SELECT id, status, stop_level, error, created_at FROM runs WHERE thread_id = ? ORDER BY id'); $q->execute([$id]);
                    return self::output(['thread' => $thread->get(), 'messages' => $messages, 'runs' => $q->fetchAll()]);
                }
                if ($action === 'events' && $method === 'GET') {
                    return self::output(WorkflowState::feed($id, isset($_GET['after']) ? (int)$_GET['after'] : 0, $user['id']));
                }
                if ($method !== 'POST') throw new \RuntimeException('Method not allowed');
                if ($action === 'messages') return self::output(['run' => $frontend->send($id, $body)], 'Message accepted for processing');
                if ($action === 'settings') { $frontend->configure($id, $body['settings'], isset($body['system_prompt']) ? $body['system_prompt'] : null); return self::output(['ok' => true], 'Conversation settings saved'); }
                if ($action === 'share') { $frontend->share($id, $body['visibility'], isset($body['team_id']) ? $body['team_id'] : null); return self::output(['ok' => true], 'Conversation sharing updated'); }
                if ($action === 'delete') { $frontend->delete($id); return self::output(['ok' => true], 'Conversation deleted'); }
                if ($action === 'title') return self::output(['thread' => $frontend->rename($id, isset($body['title']) ? $body['title'] : null)], 'Conversation renamed');
                if ($action === 'persona') {
                    $frontend->configure($id, ['persona' => $body['persona_id']]); return self::output(['ok' => true], 'Persona applied');
                }
            }
            if (preg_match('~^/api/messages/(\d+)/edit$~', $path, $matches) && $method === 'POST') {
                $q = Database::connection()->prepare('SELECT thread_id FROM messages WHERE id = ?'); $q->execute([$matches[1]]);
                return self::output(['run' => $frontend->edit($q->fetchColumn(), (int)$matches[1], $body['content'])], 'Message updated; replacement run queued');
            }
            if (preg_match('~^/api/runs/(\d+)/stop$~', $path, $matches) && $method === 'POST') {
                $level = $frontend->stop((int)$matches[1]);
                return self::output(['level' => $level], $level ? 'Stop requested' : 'No active run to stop');
            }
            if ($path === '/api/personas' && $method === 'POST') return self::output(['persona' => Personas::save($user['id'], $body, isset($body['id']) ? $body['id'] : null)], 'Persona saved');
            if (preg_match('~^/api/personas/(\d+)/apply$~', $path, $matches) && $method === 'POST') return self::output(['settings' => $frontend->persona((int)$matches[1], isset($body['settings']) ? $body['settings'] : [])], 'Persona prepared');
            if (preg_match('~^/api/personas/(\d+)/delete$~', $path, $matches) && $method === 'POST') { Personas::delete($user['id'], (int)$matches[1]); return self::output(['ok' => true], 'Persona deleted'); }
            if ($path === '/api/knowledge' && $method === 'GET') return self::output(['claims' => Knowledge::recall(isset($_GET['q']) ? $_GET['q'] : '', $user['id'], null, 50)]);
            if (preg_match('~^/api/knowledge/(\d+)(/delete)?$~', $path, $matches) && $method === 'POST') {
                $claim = Knowledge::get((int)$matches[1], $user['id']);
                if ($claim['owner_id'] !== (string)$user['id']) throw new \RuntimeException('Only the memory owner may change it');
                if (!empty($matches[2])) Database::connection()->prepare('DELETE FROM claims WHERE id = ?')->execute([$claim['id']]);
                else {
                    if (empty(trim($body['content']))) throw new \InvalidArgumentException('Memory content cannot be empty');
                    Database::connection()->prepare('UPDATE claims SET content = ? WHERE id = ?')->execute([$body['content'], $claim['id']]);
                    Database::connection()->prepare('DELETE FROM graph_edges WHERE claim_id = ?')->execute([$claim['id']]);
                }
                return self::output(['ok' => true], !empty($matches[2]) ? 'Memory deleted' : 'Memory updated');
            }
            if (strpos($path, '/api/admin/') === 0) {
                Access::admin($user['id']);
                if ($path === '/api/admin/registry' && $method === 'GET') return self::output(['profiles' => (new Registry())->profiles(), 'models' => (new Registry())->models(), 'endpoints' => (new Registry())->endpoints()]);
                if ($path === '/api/admin/attachment-parsers' && $method === 'GET') return self::output(['parsers' => (object)AttachmentParser::configuration()]);
                if ($path === '/api/admin/attachment-parsers' && $method === 'POST') {
                    if (!isset($body['parsers'])) throw new \InvalidArgumentException('Provide a parser configuration object');
                    AttachmentParser::configure($body['parsers']); return self::output(['ok' => true], 'Attachment parsers saved');
                }
                if ($path === '/api/admin/profile' && $method === 'POST') return self::output(['profile' => Profiles::save($body)], 'Profile saved');
                if ($path === '/api/admin/scan' && $method === 'POST') {
                    $payload = Scanner::configuration($body);
                    $q = Database::connection()->prepare("INSERT INTO operations (type, payload) VALUES ('scan', ?::jsonb) RETURNING id"); $q->execute([json_encode($payload)]);
                    return self::output(['id' => $q->fetchColumn()], 'Discovery scan queued');
                }
                if ($path === '/api/admin/operations' && $method === 'GET') return self::output(['operations' => isset($_GET['id']) ? Database::rows('SELECT * FROM operations WHERE id = ?', [(int)$_GET['id']]) : Database::rows('SELECT * FROM operations ORDER BY id DESC LIMIT 5')]);
                if ($path === '/api/admin/access' && $method === 'GET') {
                    return self::output(['users' => Identity::users(), 'teams' => Mappings::rows('teams'), 'memberships' => Mappings::rows('memberships'), 'permissions' => Mappings::rows('permissions')]);
                }
                if ($path === '/api/admin/permission' && $method === 'POST') {
                    if (!in_array($body['tool'], Frontend::TOOLS, true)) throw new \InvalidArgumentException('Unknown tool');
                    Identity::grant($body['scope_type'], $body['scope_id'], $body['tool'], $body['allowed']); return self::output(['ok' => true], 'Tool permission updated');
                }
                if ($path === '/api/admin/user' && $method === 'POST') return self::output(['user' => Identity::createUser($body)], 'User created');
                if ($path === '/api/admin/membership' && $method === 'POST') {
                    if (!Identity::user(['id' => $body['user_id']]) || !Mappings::rows('teams', ['id' => $body['team_id']])) throw new \InvalidArgumentException('Select an existing user and team');
                    $criteria = ['user_id' => (string)$body['user_id'], 'team_id' => (string)$body['team_id']];
                    if (!empty($body['remove'])) Mappings::delete('memberships', $criteria);
                    elseif (!Mappings::rows('memberships', $criteria)) Mappings::insert('memberships', $criteria);
                    return self::output(['ok' => true], !empty($body['remove']) ? 'Team membership removed' : 'Team membership saved');
                }
                if ($path === '/api/admin/team' && $method === 'POST') return self::output(['id' => Mappings::insert('teams', ['id' => bin2hex(random_bytes(8)), 'name' => $body['name']])], 'Team created');
                if ($path === '/api/admin/services' && $method === 'GET') {
                    $services = []; foreach (['searxng', 'camoufox'] as $name) { try { $services[$name] = Config::service($name); } catch (\Exception $e) { $services[$name] = ['error' => $e->getMessage()]; } }
                    return self::output(['services' => $services]);
                }
                if ($path === '/api/admin/services' && $method === 'POST') {
                    Config::configureServices($body['services']);
                    return self::output(['ok' => true], 'Service configuration saved');
                }
            }
            http_response_code(404); return self::output(['error' => 'Unknown API route']);
        } catch (\Throwable $e) {
            http_response_code(!Runtime::isExpected($e) ? 500 : ($e instanceof StateConflict || $e instanceof SubmissionConflict ? 409 : ($e instanceof \InvalidArgumentException ? 400 : 403)));
            Log::writeDebug('API action rejected', ['path' => $path, 'exception' => get_class($e), 'code' => $e->getCode()]);
            Log::trace('API action exception', ['path' => $path], $e);
            return self::output(['error' => Runtime::publicMessage($e)]);
        }
    }

    private static function output($data, $success = null)
    {
        if (isset($data['error'])) Log::writeError($data['error']);
        elseif (self::$request['method'] === 'POST') Log::writeSuccess($success ?: 'Action completed');
        if (self::$request['method'] === 'POST' || isset($data['error'])) {
            $ids = [];
            foreach (['thread', 'run', 'attachment', 'persona', 'profile', 'user', 'project'] as $key) if (isset($data[$key]['id'])) $ids[$key . '_id'] = $data[$key]['id'];
            Log::writeDebug('API action finished', ['path' => self::$request['path'], 'method' => self::$request['method'], 'user_id' => isset(self::$request['user_id']) ? self::$request['user_id'] : null, 'status' => http_response_code() ?: 200,
                'elapsed_ms' => round((microtime(true) - self::$request['started']) * 1000, 2), 'ids' => $ids]);
        }
        if (strpos(self::$request['path'], '/api/setup') !== 0 && !in_array(self::$request['path'], ['/api/login', '/api/logout', '/api/state'], true)) Log::trace('API response output', ['path' => self::$request['path'], 'response' => $data]);
        Log::httpHeaders(); echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

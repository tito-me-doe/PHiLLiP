<?php
namespace Agent;

/** Server-rendered pages and fragments. JavaScript never formats persisted messages. */
class Views
{
    public static function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    public static function url($query = '') { return rtrim(getenv('APP_BASE_PATH') ?: '', '/') . '/' . $query; }
    public static function json($value) { return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); }
    public static function csrf($state, $action) { return '<input type="hidden" name="csrf" value="' . self::h($state['csrf']) . '"><input type="hidden" name="action" value="' . self::h($action) . '">'; }
    public static function visibilityField($state, $selected = 'private', $disabled = false, $allowPrivate = true)
    {
        $html = '<label>Visibility<select name="visibility"' . ($disabled ? ' disabled' : '') . '>';
        foreach (['private' => 'Private', 'team' => 'Team', 'organization' => 'Organization'] as $key => $label) {
            if ($key === 'private' && !$allowPrivate) continue;
            if ($key === 'team' && empty($state['teams']) && $selected !== 'team') continue;
            $html .= '<option value="' . $key . '"' . ($key === $selected ? ' selected' : '') . ($key === 'team' && empty($state['teams']) ? ' hidden' : '') . '>' . $label . '</option>';
        }
        return $html . '</select></label>';
    }
    public static function teamField($state, $selected = null, $disabled = false)
    {
        if (empty($state['teams'])) return '';
        $html = '<label>Team<select name="team_id"' . ($disabled ? ' disabled' : '') . '><option value="">Choose a team</option>';
        foreach ($state['teams'] as $team) $html .= '<option value="' . self::h($team['id']) . '"' . ((string)$selected === (string)$team['id'] ? ' selected' : '') . '>' . self::h($team['name']) . '</option>';
        return $html . '</select></label>';
    }
    public static function pretty($value) { return self::h(json_encode(is_string($value) ? json_decode($value, true) : $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); }
    /** Bounded, escaped presentation of heterogeneous tool arguments and results. */
    public static function toolValue($value, $depth = 0)
    {
        if (is_string($value)) {
            $tmp = json_decode($value, true);
            if (is_array($tmp) && $tmp) $value = $tmp;
            unset($tmp);
        }
        if (is_array($value)) {
            if (!$value) return '<span class="muted">None</span>';
            if ($depth >= 4) return '<span class="muted">' . count($value) . ' items</span>';
            $html = '<dl class="tool-fields">'; $count = 0;
            foreach ($value as $key => $item) {
                if (++$count > 30) { $html .= '<dt>More</dt><dd>' . (count($value) - 30) . ' additional items</dd>'; break; }
                if (is_array($item) && isset($item['type']) && $item['type'] === 'image_url') continue;
                $label = is_int($key) ? 'Item ' . ($key + 1) : ToolRegistry::label($key);
                $html .= '<dt>' . self::h($label) . '</dt><dd>' . self::toolValue($item, $depth + 1) . '</dd>';
            }
            return $html . '</dl>';
        }
        if ($value === null) return '<span class="muted">None</span>';
        if (is_bool($value)) return $value ? 'Yes' : 'No';
        return '<div class="tool-text">' . self::markdown(mb_substr((string)$value, 0, 16000)) . '</div>';
    }
    public static function icon($name)
    {
        $paths = ['plus' => 'M12 5v14M5 12h14', 'send' => 'm5 12 7-7 7 7M12 5v14', 'stop' => 'M6 6h12v12H6z',
            'trash' => 'M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7', 'theme' => 'M20 13A8 8 0 0 1 11 4a8 8 0 1 0 9 9',
            'gear' => 'M9 3h6l1 4 4 2v6l-4 2-1 4H9l-1-4-4-2V9l4-2zM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0',
            'close' => 'm6 6 12 12M6 18 18 6', 'menu' => 'M4 6h16M4 12h16M4 18h16', 'edit' => 'm15 4 5 5-11 11H4v-5zM13 6l5 5',
            'attach' => 'm8 13 7-7a3 3 0 0 1 4 4L9 20a5 5 0 0 1-7-7L12 3', 'tools' => 'm14 5 5 5M4 20l10-10M14 3a6 6 0 0 0-7 7L3 14v6h6l4-4a6 6 0 0 0 7-7l-5 2-2-2z'];
        return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="' . (isset($paths[$name]) ? $paths[$name] : $paths['gear']) . '"/></svg>';
    }
    public static function avatar($state, $extra = '', $name = null)
    {
        $name = $name ?: (isset($state['preferences']['avatar']) ? $state['preferences']['avatar'] : 'terminal');
        if (!in_array($name, ['terminal', 'sprout'], true)) $name = 'terminal';
        return '<span class="avatar ' . self::h($extra) . '" data-avatar="' . $name . '">' . file_get_contents(dirname(__DIR__, 2) . '/public/assets/avatars/' . $name . '.svg') . '</span>';
    }
    public static function markdown($text)
    {
        $parser = new \Parsedown();
        $parser->setSafeMode(true);
        return $parser->text((string)$text);
    }
    public static function state()
    {
        $state = Auth::current();
        Log::restoreFlash();
        $state['installed'] = Installation::get() !== null;
        $state['onboarding'] = Onboarding::pending();
        if (!$state['user']) return $state;
        if ($state['onboarding']) return $state;
        $id = $state['user']['id'];
        $state['teams'] = Identity::sharedTeams($id);
        $state['profiles'] = [];
        foreach ((new Registry())->profiles(true) as $profile) {
            if (!$profile['supports_chat']) continue;
            try {
                $config = Config::resolve($profile);
                $model = isset($config['model']) ? $config['model'] : (isset($config['body']['model']) ? $config['body']['model'] : null);
                if (!is_string($model) || trim($model) === '') throw new \RuntimeException('Profile requires a model name');
            } catch (\Throwable $error) {
                Log::writeWarning('Model profile %s is unavailable. Ask an administrator to check its configuration.', $profile['name']);
                Log::trace('Unavailable chat profile', $error);
                continue;
            }
            $state['profiles'][] = ['name' => $profile['name'], 'model' => $model] + Registry::capabilities($profile);
        }
        usort($state['profiles'], function ($a, $b) { return ($b['name'] === 'default' ? 1 : 0) - ($a['name'] === 'default' ? 1 : 0); });
        if (!$state['profiles'] && $state['user']['is_admin']) $state['onboarding'] = true;
        $state['tools'] = array_values(array_filter(Frontend::TOOLS, function ($tool) use ($id) { return Access::tool($id, $tool); }));
        $state['personas'] = Personas::listFor($id);
        $state['attachment_max_bytes'] = Attachments::maxBytes();
        // A tiny cookie mirrors the local-storage avatar for immediate PHP rendering.
        $state['avatar_cookie'] = 'phillip_avatar_' . substr(hash('sha256', $id), 0, 16);
        $state['theme_cookie'] = 'phillip_theme_' . substr(hash('sha256', $id), 0, 16);
        $avatar = isset($_COOKIE[$state['avatar_cookie']]) ? $_COOKIE[$state['avatar_cookie']] : 'terminal';
        $theme = isset($_COOKIE[$state['theme_cookie']]) ? $_COOKIE[$state['theme_cookie']] : 'light';
        $state['preferences'] = ['avatar' => in_array($avatar, ['terminal', 'sprout'], true) ? $avatar : 'terminal', 'theme' => $theme === 'dark' ? 'dark' : 'light'];
        return $state;
    }
    public static function thread($state, $id)
    {
        if (!$id) return null;
        return State::transaction(function () use ($state, $id) {
            $thread = (new Frontend($state['user']))->thread($id);
            Log::restoreThread($id);
            $messages = self::messageAuthors($thread->messages());
            $workflow = WorkflowState::snapshot($id);
            return ['thread' => $thread->get(), 'messages' => $messages, 'runs' => $workflow['runs'], 'workflow' => $workflow, 'panels' => []];
        }, true);
    }
    public static function messageAuthors(array $messages)
    {
        $names = [];
        foreach ($messages as &$message) {
            if ($message['author_id'] !== null && !isset($names[$message['author_id']])) {
                $author = Identity::user(['id' => $message['author_id']]); $names[$message['author_id']] = $author ? $author['display_name'] : 'Former user';
            }
            $message['author_name'] = $message['author_id'] !== null ? $names[$message['author_id']] : (isset($message['raw']['_assistant_name']) && is_string($message['raw']['_assistant_name']) ? $message['raw']['_assistant_name'] : 'PHiLLiP');
        }
        unset($message);
        return $messages;
    }
    public static function render($name, array $data = [])
    {
        $allowed = ['page', 'login', 'setup', 'shell', 'chat', 'messages', 'threads', 'settings', 'dialog', 'mapping', 'attachments', 'projects'];
        if (!in_array($name, $allowed, true)) throw new \InvalidArgumentException('Unknown view');
        extract($data, EXTR_SKIP);
        ob_start();
        try { require dirname(__DIR__, 2) . '/views/' . $name . '.php'; return ob_get_clean(); }
        catch (\Throwable $e) { ob_end_clean(); throw $e; }
    }
    public static function fragment($path)
    {
        header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
        try {
            $state = self::state(); $user = $state['user'];
            if (Onboarding::pending()) {
                Onboarding::authorize($user);
                if ($path !== '/fragments/mapping') throw new \RuntimeException('Finish setup before opening conversations');
            }
            if ($path === '/fragments/mapping' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                Onboarding::authorize($user);
                $started = microtime(true);
                Log::writeDebug('Mapping inspection started');
                if ($state['installed']) { if (!$user) throw new \RuntimeException('Authentication required'); Access::admin($user['id']); }
                if (!hash_equals($state['csrf'], isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '')) throw new \RuntimeException('Invalid CSRF token');
                $body = json_decode(file_get_contents('php://input'), true);
                Log::trace('Mapping inspection input', $body);
                if (!isset(Mappings::COLUMNS[$body['entity']])) throw new \InvalidArgumentException('Unknown mapping');
                $columns = Mappings::inspect($body['connection'], $body['table']);
                $html = self::render('mapping', ['entity' => $body['entity'], 'columns' => $columns]);
                Log::trace('Mapping inspection output', ['entity' => $body['entity'], 'columns' => $columns, 'html' => $html]);
                Log::writeDebug('Mapping inspection finished', ['entity' => $body['entity'], 'columns' => count($columns), 'elapsed_ms' => round((microtime(true) - $started) * 1000, 2)]);
                Log::writeSuccess('Mapping inspected');
                echo $html; return;
            }
            if (!$user) throw new \RuntimeException('Authentication required');
            if ($path === '/fragments/projects' || $path === '/fragments/project') {
                if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new \RuntimeException('Method not allowed');
                echo self::render('projects', ['state' => $state, 'projectId' => isset($_GET['project']) ? $_GET['project'] : null]); return;
            }
            $current = !empty($_GET['thread']) ? self::thread($state, (int)$_GET['thread']) : null;
            if ($path === '/fragments/attachments') {
                $files = Attachments::normalize(isset($_GET['ids']) ? $_GET['ids'] : ''); $frontend = new Frontend($user);
                foreach ($files as $file) $frontend->attachment($file);
                echo self::render('attachments', ['files' => $files, 'pending' => true]); return;
            }
            $data = ['state' => $state, 'current' => $current, 'tab' => isset($_GET['tab']) ? $_GET['tab'] : 'private'];
            if ($path === '/fragments/threads') $data['threads'] = (new Frontend($user))->threads();
            $names = ['/fragments/thread' => 'chat', '/fragments/messages' => 'messages', '/fragments/threads' => 'threads', '/fragments/settings' => 'settings', '/fragments/dialog' => 'dialog'];
            if (!isset($names[$path])) throw new \InvalidArgumentException('Unknown fragment');
            echo self::render($names[$path], $data);
        } catch (\Throwable $e) {
            http_response_code(Runtime::isExpected($e) ? 403 : 500); Log::trace('Fragment failed', $e);
            $message = Runtime::publicMessage($e); Log::writeError($message);
            echo '<div class="error-panel">' . self::h($message) . '</div>';
        }
    }
}

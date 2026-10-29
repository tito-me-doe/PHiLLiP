<?php
namespace Agent;

/** A page is a fixed PHP concept, with editable navigation and a permission-checked route. */
class Pages
{
    const VIEWS = [
        'conversations' => 'chat',
        'projects' => 'projects',
        'personas' => 'personas',
        'knowledge' => 'knowledge',
        'benchmarks' => 'benchmarks',
        'test-suites' => 'test-suites',
        'appearance' => 'appearance',
        'models' => 'models',
        'tools-services' => 'tools-services',
        'access' => 'access',
        'navigation' => 'navigation',
    ];

    public static function all()
    {
        return Database::rows('SELECT * FROM pages ORDER BY lower(path),id');
    }

    public static function get($id)
    {
        if ($id instanceof \stdClass) {
            $id = (array) $id;
        }
        if (is_array($id)) {
            $id = isset($id['id']) ? $id['id'] : null;
        }
        if (!is_string($id) || !preg_match('/^[a-z][a-z0-9-]{0,79}$/D', $id)) {
            throw new \InvalidArgumentException('Unknown page');
        }
        $page = Database::row('SELECT * FROM pages WHERE id=?', [$id]);
        if (!$page) {
            throw new \InvalidArgumentException('Unknown page');
        }
        return $page;
    }

    public static function allowed($userId, $page)
    {
        $page = self::get($page); // Supplied rows are identifiers, not permission authority.
        return Access::allows($userId, 'page.' . $page['id']) &&
            ($page['id'] === $page['handler'] || self::allowed($userId, $page['handler']));
    }

    public static function authorize($userId, $id)
    {
        if (!self::allowed($userId, $id)) {
            throw new \RuntimeException('Page access denied: ' . self::get($id)['title']);
        }
    }

    public static function current(array $query)
    {
        $page = isset($query['view']) ? $query['view'] : 'conversations';
        if ($page === 'settings') {
            $page = isset($query['tab']) ? $query['tab'] : 'appearance';
        }
        if (in_array($page, ['users','groups','memberships'], true)) $page = 'access';
        if (in_array($page, ['permissions','services','parsers'], true)) $page = 'tools-services';
        if ($page === 'benchmarks' && isset($query['edit_test'])) {
            $page = 'test-suites';
        }
        if (!empty($query['project'])) {
            $page = 'projects';
        }
        return self::get($page)['id'];
    }

    public static function link($id, array $query = [])
    {
        $page = self::get($id);
        return rtrim(getenv('APP_BASE_PATH') ?: '', '/') . $page['path'] . ($query ? '?' . http_build_query($query) : '');
    }

    public static function navigation($userId)
    {
        return self::permitted($userId);
    }

    public static function permitted($userId)
    {
        $rows = self::all();
        $defaults = [];
        $byId = [];
        foreach ($rows as $row) {
            $defaults['page.' . $row['id']] = false;
            $byId[$row['id']] = $row;
        }
        $grants = Access::decisions($userId, $defaults);
        return array_values(
            array_filter($rows, function ($row) use ($grants, $byId) {
                return $grants['page.' . $row['id']] &&
                    isset($byId[$row['handler']]) &&
                    $grants['page.' . $row['handler']];
            }),
        );
    }

    /** API aliases use the same page permissions as ordinary forms and HTML routes. */
    public static function api($userId, $path, array $body)
    {
        $page = null;
        if ($path === '/api/admin/benchmarks') {
            $action = isset($body['action']) ? $body['action'] : '';
            $page =
                $action === 'default'
                    ? 'models'
                    : (in_array($action, ['save-test', 'save-question', 'remove-question'], true)
                        ? 'test-suites'
                        : 'benchmarks');
        } elseif (preg_match('~^/api/manage/(users|groups|memberships|permissions|navigation|tools)$~D', $path, $m)) {
            $page = Administration::page($m[1], $body);
        } elseif (preg_match('~^/api/admin/(registry|profile|scan|operations)$~D', $path)) {
            $page = 'models';
        } elseif (in_array($path, ['/api/admin/attachment-parsers','/api/admin/services'], true)) {
            $page = 'tools-services';
        } elseif ($path === '/api/admin/user') {
            $page = 'access';
        } elseif ($path === '/api/admin/team') {
            $page = 'access';
        } elseif ($path === '/api/admin/membership') {
            $page = 'access';
        } elseif ($path === '/api/admin/permission') {
            $page = Administration::page('permissions', $body);
        } elseif ($path === '/api/admin/access') {
            $page = 'access';
        } elseif (strpos($path, '/api/personas') === 0) {
            $page = 'personas';
        } elseif (strpos($path, '/api/knowledge') === 0) {
            $page = 'knowledge';
        }
        if ($page !== null) {
            self::authorize($userId, $page);
        } elseif (strpos($path, '/api/admin/') === 0) {
            throw new \RuntimeException('No page permission is registered for this administrative route');
        }
    }
}

<?php
namespace Agent;

/** A page is a fixed PHP concept, with editable navigation and a permission-checked route. */
class Pages
{
    const VIEWS = [
        'conversations'  => 'chat',
        'projects'       => 'projects',
        'personas'       => 'personas',
        'knowledge'      => 'knowledge',
        'benchmarks'     => 'benchmarks',
        'models'         => 'models',
        'access'         => 'access',
    ];

    /**
     * Sections are the cards of a page. A page grant opens every section on it; a `section.<page>.<section>` grant opens
     * just that one (and the page around it), so a page can be delegated in parts. Sections listed in ADMIN_SECTIONS are
     * never delegated: only administrators see or change them.
     */
    const SECTIONS = [
        'models'    => ['models' => 'Models', 'profiles' => 'Profiles', 'attachment-parsing' => 'Attachment parsing', 'services' => 'Services'],
        'access'    => ['users' => 'Users', 'groups' => 'Groups', 'pages' => 'Pages', 'tools' => 'Tools'],
        'personas'  => ['personas' => 'Personas', 'experts' => 'Experts'],
        'knowledge' => ['memories' => 'Memories', 'graph' => 'Graph'],
    ];
    const ADMIN_SECTIONS = ['access' => ['pages', 'tools']];

    public static function sectionResource($page, $section)
    {
        return 'section.' . $page . '.' . $section;
    }

    /** Resources an administrator may grant to people: every delegable section. @return array<string,string> resource => label */
    public static function sectionResources()
    {
        $items = [];
        foreach (self::SECTIONS as $page => $sections) {
            foreach ($sections as $id => $title) {
                if (!in_array($id, isset(self::ADMIN_SECTIONS[$page]) ? self::ADMIN_SECTIONS[$page] : [], true)) {
                    $items[self::sectionResource($page, $id)] = $title;
                }
            }
        }
        return $items;
    }

    /** The sections of a page this person may use. @return list<string> */
    public static function sections($userId, $page)
    {
        $all = isset(self::SECTIONS[$page]) ? self::SECTIONS[$page] : [];
        if (!$all) return [];
        $user = $userId === null || $userId === '' ? null : Identity::user(['id' => $userId]);
        if (!$user || !$user['active']) return [];
        $adminOnly = isset(self::ADMIN_SECTIONS[$page]) ? self::ADMIN_SECTIONS[$page] : [];
        $defaults = ['page.' . $page => false];
        foreach ($all as $id => $title) $defaults[self::sectionResource($page, $id)] = false;
        $grants = Access::decisions($userId, $defaults);
        $allowed = [];
        foreach ($all as $id => $title) {
            if (in_array($id, $adminOnly, true)) $open = !empty($user['is_admin']);
            else $open = $grants['page.' . $page] || $grants[self::sectionResource($page, $id)];
            if ($open) $allowed[] = $id;
        }
        return $allowed;
    }

    public static function section($userId, $page, $section)
    {
        return in_array($section, self::sections($userId, $page), true);
    }

    /** @param string|list<string> $section one section, or several where any one is enough */
    public static function authorizeSection($userId, $page, $section)
    {
        $sections = (array)$section;
        if (!array_intersect($sections, self::sections($userId, $page))) {
            $titles = array_map(function ($id) use ($page) { return isset(self::SECTIONS[$page][$id]) ? self::SECTIONS[$page][$id] : $id; }, $sections);
            throw new \RuntimeException('Section access denied: ' . implode(' or ', $titles));
        }
    }

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
        if (Access::allows($userId, 'page.' . $page['id']) && ($page['id'] === $page['handler'] || self::allowed($userId, $page['handler']))) return true;
        // A grant on one section opens its core page around that section.
        return $page['id'] === $page['handler'] && self::sections($userId, $page['id']) !== [];
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
        if ($page === 'settings') $page = isset($query['tab']) ? $query['tab'] : 'personas';
        if (in_array($page, ['users','groups','memberships','navigation','permissions','tools-services'], true)) $page = 'access';
        if (in_array($page, ['services','parsers'], true)) $page = 'models';
        if ($page === 'test-suites') $page = 'benchmarks';
        if (!empty($query['project'])) $page = 'projects';
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
        $rows     = self::all();
        $defaults = [];
        $byId     = [];
        foreach ($rows as $row) {
            $defaults['page.' . $row['id']] = false;
            $byId[$row['id']] = $row;
        }
        $grants = Access::decisions($userId, $defaults);
        return array_values(
            array_filter($rows, function ($row) use ($grants, $byId, $userId) {
                if ($grants['page.' . $row['id']] && isset($byId[$row['handler']]) && $grants['page.' . $row['handler']]) return true;
                return $row['id'] === $row['handler'] && self::sections($userId, $row['id']) !== [];
            })
        );
    }

    /** API aliases use the same page and section permissions as ordinary forms and HTML routes. */
    public static function api($userId, $path, array $body)
    {
        $page = null;
        $section = null;
        if ($path === '/api/admin/benchmarks') {
            $action = isset($body['action']) ? $body['action'] : '';
            $page = $action === 'default' ? 'models' : 'benchmarks';
            $section = $action === 'default' ? 'profiles' : null;
        } elseif (preg_match('~^/api/manage/(users|groups|memberships|permissions|navigation|tools|sections)$~D', $path, $m)) {
            $page = Administration::page($m[1], $body);
            $section = Administration::section($m[1], $body);
        } elseif (preg_match('~^/api/admin/(registry|scan|operations)$~D', $path)) {
            $page = 'models';
            $section = 'models';
        } elseif (preg_match('~^/api/admin/(profile|profile-defaults|profile-enabled)$~D', $path)) {
            $page = 'models';
            $section = 'profiles';
        } elseif ($path === '/api/admin/attachment-parsers') {
            $page = 'models';
            $section = 'attachment-parsing';
        } elseif ($path === '/api/admin/services') {
            $page = 'models';
            $section = 'services';
        } elseif (in_array($path, ['/api/admin/user', '/api/admin/team', '/api/admin/membership', '/api/admin/permission', '/api/admin/access'], true)) {
            $entity = ['/api/admin/user' => 'users', '/api/admin/team' => 'groups', '/api/admin/membership' => 'memberships',
                '/api/admin/permission' => 'permissions', '/api/admin/access' => 'access'][$path];
            $page = Administration::page($entity, $body);
            $section = Administration::section($entity, $body);
        } elseif (strpos($path, '/api/personas') === 0) {
            $page = 'personas';
            $section = Personas::sectionFor($path, $body);
        } elseif (strpos($path, '/api/knowledge') === 0) {
            $page = 'knowledge';
            $section = 'memories';
        }
        if ($page !== null) {
            $section === null ? self::authorize($userId, $page) : self::authorizeSection($userId, $page, $section);
        } elseif (strpos($path, '/api/admin/') === 0) {
            throw new \RuntimeException('No page permission is registered for this administrative route');
        }
    }
}

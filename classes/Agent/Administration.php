<?php
namespace Agent;

/** Frontend identity/navigation management. Core identity and mapping APIs stay transport-independent. */
class Administration
{
    private $user;

    public function __construct($user)
    {
        $resolved = Identity::user($user);
        $this->user = Access::authenticated($resolved ? $resolved['id'] : null);
    }

    public static function resources()
    {
        $items = [];
        foreach (Pages::all() as $page) {
            $items['page.' . $page['id']] = 'Page · ' . $page['title'];
        }
        foreach (Frontend::TOOLS as $tool) {
            $items[$tool] = 'Tool · ' . ToolRegistry::label(substr($tool, strrpos($tool, '\\') + 1));
        }
        return $items;
    }

    /** Editors and API aliases share the same authorization boundary. */
    public static function page($entity, array $input = [])
    {
        if ($entity === 'navigation') return 'navigation';
        if ($entity === 'permissions') {
            $resource = $input['resource'] ?? $input['tool'] ?? '';
            return is_string($resource) && strpos($resource, 'page.') === 0 ? 'navigation' : 'tools-services';
        }
        return $entity === 'tools' ? 'tools-services' : 'access';
    }

    public function data($page)
    {
        Pages::authorize($this->user['id'], self::page($page));
        $data = [];
        $data['users'] = Identity::users();
        $data['groups'] = Mappings::rows('teams');
        if (in_array($page, ['navigation','permissions','tools'], true)) {
            $data['permissions'] = Mappings::rows('permissions');
        } else {
            $data['memberships'] = Mappings::rows('memberships');
        }
        if ($page === 'navigation') {
            $data['pages'] = Pages::all();
        }
        return $data;
    }

    public function action($page, array $input)
    {
        foreach (['groups','members','allowed_users','allowed_groups'] as $field) if (isset($input[$field.'_present']) && !isset($input[$field])) $input[$field] = [];
        if (!in_array($page, ['users', 'groups', 'memberships', 'permissions', 'navigation', 'tools'], true)) {
            throw new \InvalidArgumentException('Unknown management page');
        }
        Pages::authorize($this->user['id'], self::page($page, $input));
        return State::transaction(function () use ($page, $input) {
            Database::row("SELECT pg_advisory_xact_lock(hashtext('phillip-identity-management'))");
            $this->user = Access::authenticated($this->user['id']);
            Pages::authorize($this->user['id'], self::page($page, $input));
            if ($page === 'users') {
                $result = $this->user($input);
            } elseif ($page === 'groups') {
                $result = $this->group($input);
            } elseif ($page === 'memberships') {
                $result = $this->membership($input);
            } elseif ($page === 'permissions') {
                $result = $this->permission($input);
            } elseif ($page === 'tools') {
                $resource = self::text($input, 'resource', 200);
                if (!in_array($resource, Frontend::TOOLS, true)) throw new \InvalidArgumentException('Choose an available tool');
                $this->grants($resource, $input);
                $result = ['resource'=>$resource];
            } else {
                $result = $this->navigation($input);
            }
            Log::writeSuccess(ucfirst($page) . ' updated', [
                'actor' => $this->user['id'],
            ]);
            return $result;
        });
    }

    private static function text($input, $key, $maximum = 160)
    {
        if (!isset($input[$key]) || !is_string($input[$key])) {
            throw new \InvalidArgumentException('Provide ' . $key);
        }
        $value = trim($input[$key]);
        if ($value === '' || mb_strlen($value) > $maximum || preg_match('/[\x00-\x1f\x7f]/u', $value) || !preg_match('//u', $value)) {
            throw new \InvalidArgumentException('Provide a valid ' . $key);
        }
        return $value;
    }

    private function user(array $input)
    {
        $target = !empty($input['id']) ? Identity::user(['id' => self::text($input, 'id')]) : null;
        if (!empty($input['id']) && !$target) {
            throw new \InvalidArgumentException('User not found');
        }
        $groups = array_key_exists('groups', $input) ? $this->references($input['groups'], 'teams') : null;
        $isAdmin = $groups !== null ? in_array('1', $groups, true) : (isset($input['is_admin'])
            ? Config::boolean($input['is_admin'], 'Administrator')
            : ($target
                ? $target['is_admin']
                : false));
        if (($isAdmin || ($target && $target['is_admin'])) && !$this->user['is_admin']) {
            throw new \RuntimeException('Only an administrator may manage administrator accounts');
        }
        $active = isset($input['active']) ? Config::boolean($input['active'], 'Active') : ($target ? $target['active'] : true);
        if ($target && $target['id'] === $this->user['id'] && (!$active || ($target['is_admin'] && !$isAdmin))) {
            throw new \RuntimeException('You cannot deactivate or demote your current account');
        }
        if ($target && $target['is_admin'] && (!$active || !$isAdmin)) {
            $others = array_filter(Identity::users(), function ($row) use ($target) {
                return $row['active'] && $row['is_admin'] && $row['id'] !== $target['id'];
            });
            if (!$others) {
                throw new \RuntimeException('Keep at least one active administrator');
            }
        }
        $username = self::text($input, 'username', 100);
        $display = self::text($input, 'display_name');
        foreach (Identity::users() as $row) {
            if ($row['username'] === $username && (!$target || $row['id'] !== $target['id'])) {
                throw new \InvalidArgumentException('Username is already in use');
            }
        }
        $password = isset($input['password']) ? $input['password'] : '';
        if (!is_string($password) || strlen($password) > 4096 || ((!$target || $password !== '') && strlen($password) < 10)) {
            throw new \InvalidArgumentException('Use a password of 10 to 4096 bytes');
        }
        if (!$target) {
            $target = Identity::createUser($username, $password, $display, $isAdmin);
            if (!$active) {
                Mappings::upsert('users', ['id' => $target['id']], ['active' => false]);
            }
        } else {
            $values = [
                'username' => $username,
                'display_name' => $display,
                'is_admin' => $isAdmin,
                'active' => $active,
            ];
            if ($password !== '') {
                $values['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            Mappings::upsert('users', ['id' => $target['id']], $values);
            if (!$isAdmin) {
                $installation = Installation::get();
                $installation['admin_ids'] = array_values(
                    array_diff(isset($installation['admin_ids']) ? $installation['admin_ids'] : [], [$target['id']]),
                );
                Database::execute("UPDATE settings SET value=?::jsonb WHERE key='installation'", [State::encode($installation)]);
            }
        }
        if ($groups !== null) $this->memberships('user_id', $target['id'], 'team_id', $groups);
        elseif ($isAdmin) $this->memberships('user_id', $target['id'], 'team_id', array_unique(array_merge(
            array_column(Mappings::rows('memberships', ['user_id'=>$target['id']]), 'team_id'), ['1'])));
        else Mappings::delete('memberships', ['user_id'=>$target['id'],'team_id'=>'1']);
        return ['user' => Identity::user(['id' => $target['id']])];
    }

    private function group(array $input)
    {
        $id = isset($input['id']) && $input['id'] !== '' ? self::text($input, 'id') : null;
        if ($id !== null && !Mappings::rows('teams', ['id' => $id])) {
            throw new \InvalidArgumentException('Group not found');
        }
        if (Config::boolean(isset($input['remove']) ? $input['remove'] : false, 'Remove group')) {
            if ($id === null) {
                throw new \InvalidArgumentException('Choose a group to delete');
            }
            if (in_array($id, ['0','1','2'], true)) throw new \RuntimeException('Built-in groups cannot be deleted');
            if (!Config::boolean(isset($input['confirm']) ? $input['confirm'] : false, 'Deletion confirmation')) {
                throw new \InvalidArgumentException('Confirm group deletion');
            }
            if (
                Mappings::rows('memberships', ['team_id' => $id]) ||
                Database::row('SELECT id FROM threads WHERE team_id=? UNION ALL SELECT id FROM personas WHERE team_id=? LIMIT 1', [
                    $id,
                    $id,
                ]) ||
                Mappings::rows('permissions', [
                    'scope_type' => 'team',
                    'scope_id' => $id,
                ]) ||
                Mappings::rows('permissions', ['tool' => 'group.' . $id])
            ) {
                throw new \RuntimeException('Remove memberships, group permissions, and shared content before deleting this group');
            }
            Mappings::delete('teams', ['id' => $id]);
            return ['id' => $id];
        }
        $name = self::text($input, 'name');
        $reserved = ['0'=>'Everyone','1'=>'Administrators','2'=>'Developers'];
        if ($id !== null && isset($reserved[$id]) && $name !== $reserved[$id]) throw new \RuntimeException('Built-in group names cannot be changed');
        $members = array_key_exists('members', $input) ? $this->references($input['members'], 'users') : null;
        if ($id === '0' && $members !== null) throw new \RuntimeException('Everyone includes all signed-in users automatically');
        if ($id === '1' && $members !== null) {
            if (!in_array($this->user['id'], $members, true) && $this->user['is_admin']) throw new \RuntimeException('You cannot remove yourself from Administrators');
            if (!$this->user['is_admin']) throw new \RuntimeException('Only an administrator may manage administrator memberships');
        }
        foreach (Mappings::rows('teams') as $row) {
            if (strcasecmp($row['name'], $name) === 0 && $row['id'] !== $id) {
                throw new \InvalidArgumentException('Group name is already in use');
            }
        }
        if ($id !== null) {
            Mappings::upsert('teams', ['id' => $id], ['name' => $name]);
        } else {
            $id = bin2hex(random_bytes(8));
            Mappings::insert('teams', ['id' => $id, 'name' => $name]);
        }
        if ($members !== null) $this->memberships('team_id', $id, 'user_id', $members);
        return ['id' => $id];
    }

    private function references($input, $entity, $everyone = false)
    {
        if (!is_array($input)) throw new \InvalidArgumentException('Membership selections must be an array');
        $ids = [];
        foreach ($input as $id) {
            if (!is_string($id) || !Mappings::rows($entity, ['id'=>$id])) throw new \InvalidArgumentException('Choose an existing member or group');
            if ($entity === 'teams' && $id === '0' && !$everyone) continue;
            $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }

    private function memberships($key, $id, $other, array $selected)
    {
        foreach (Mappings::rows('memberships', [$key=>$id]) as $row) {
            if (!in_array((string)$row[$other], $selected, true)) Mappings::delete('memberships', [$key=>$id,$other=>$row[$other]]);
        }
        foreach ($selected as $value) if (!Mappings::rows('memberships', [$key=>$id,$other=>$value])) {
            Mappings::insert('memberships', [$key=>$id,$other=>$value]);
        }
    }

    private function membership(array $input)
    {
        $user = self::text($input, 'user_id');
        $group = isset($input['group_id']) ? self::text($input, 'group_id') : self::text($input, 'team_id');
        if (!Identity::user(['id' => $user]) || !Mappings::rows('teams', ['id' => $group])) {
            throw new \InvalidArgumentException('Choose an existing user and group');
        }
        if ($group === '0') throw new \RuntimeException('Everyone membership is automatic');
        if ($group === '1' && (!$this->user['is_admin'] || ($user === $this->user['id'] && !empty($input['remove'])))) throw new \RuntimeException('Administrator memberships are protected');
        $key = ['user_id' => $user, 'team_id' => $group];
        if (Config::boolean(isset($input['remove']) ? $input['remove'] : false, 'Remove membership')) {
            Mappings::delete('memberships', $key);
        } elseif (!Mappings::rows('memberships', $key)) {
            Mappings::insert('memberships', $key);
        }
        return ['user_id' => $user, 'group_id' => $group];
    }

    private function permission(array $input)
    {
        $scope = self::text($input, 'scope');
        [$type, $id] = array_pad(explode(':', $scope, 2), 2, '');
        $types = [
            'user' => 'user',
            'group' => 'team',
            'team' => 'team',
            'global' => 'organization',
            'organization' => 'organization',
        ];
        $remove = Config::boolean(isset($input['remove']) ? $input['remove'] : false, 'Remove permission');
        $resource = self::text($input, 'resource', 200);
        if (!isset($types[$type]) || $id === '' || ($types[$type] === 'organization' && $id !== '*')) {
            throw new \InvalidArgumentException('Choose a user, group, or global scope');
        }
        if ($remove) {
            // Old tools or mapped subjects may no longer exist. An exact rule can still be removed.
            Mappings::delete('permissions', ['scope_type' => $types[$type], 'scope_id' => $id, 'tool' => $resource]);
            return ['resource' => $resource];
        }
        if (
            ($types[$type] === 'user' && !Identity::user(['id' => $id])) ||
            ($types[$type] === 'team' && !Mappings::rows('teams', ['id' => $id]))
        ) {
            throw new \InvalidArgumentException('Choose an existing user, group, or global scope');
        }
        if (!array_key_exists($resource, self::resources())) {
            throw new \InvalidArgumentException('Choose a known permission resource');
        }
        Identity::grant($types[$type], $id, $resource, isset($input['allowed']) ? Config::boolean($input['allowed'],'Grant') : true);
        return ['resource' => $resource];
    }

    private function navigation(array $input)
    {
        $id = self::text($input, 'id', 80);
        if (!preg_match('/^[a-z][a-z0-9-]{0,79}$/D', $id)) {
            throw new \InvalidArgumentException('Use a lowercase route identifier');
        }
        $page = Database::row('SELECT * FROM pages WHERE id=?', [$id]);
        if ($page && Config::boolean(isset($input['create']) ? $input['create'] : false, 'Create route')) {
            throw new \InvalidArgumentException('Page identifier is already in use');
        }
        $handler = isset($input['handler']) ? self::text($input, 'handler') : ($page ? $page['handler'] : '');
        if (!isset(Pages::VIEWS[$handler])) {
            throw new \InvalidArgumentException('Choose a registered page concept');
        }
        if ($page && isset(Pages::VIEWS[$id]) && $handler !== $id) {
            throw new \InvalidArgumentException('A core page cannot change its concept');
        }
        $title = self::text($input, 'title', 80);
        $path = self::text($input, 'path', 100);
        if (!preg_match('~^/[a-z][a-z0-9-]*(/[a-z][a-z0-9-]*)?$~D', $path) || preg_match('~^/(api|fragments|assets|health)(/|$)~', $path)) {
            throw new \InvalidArgumentException('Choose a simple page path outside reserved system routes');
        }
        if (Database::row('SELECT id FROM pages WHERE path=? AND id<>?', [$path, $id])) {
            throw new \InvalidArgumentException('Page path is already in use');
        }
        if ($page) {
            Database::execute(
                'UPDATE pages SET title=?,path=?,handler=? WHERE id=?',
                [$title,$path,$handler,$id],
            );
        } else {
            Database::execute(
                'INSERT INTO pages(title,path,handler,id) VALUES(?,?,?,?)',
                [$title,$path,$handler,$id],
            );
        }
        $this->grants('page.'.$id, $input);
        return ['page' => $id];
    }

    private function grants($resource, array $input)
    {
        foreach (['allowed_users'=>['user','users'],'allowed_groups'=>['team','teams']] as $key=>$scope) {
            if (!array_key_exists($key,$input)) continue;
            $selected = $this->references($input[$key],$scope[1],true);
            foreach (Mappings::rows('permissions',['tool'=>$resource,'scope_type'=>$scope[0]]) as $grant) {
                if (!in_array((string)$grant['scope_id'],$selected,true)) Mappings::delete('permissions',[
                    'tool'=>$resource,'scope_type'=>$scope[0],'scope_id'=>$grant['scope_id']
                ]);
            }
            foreach ($selected as $subject) Identity::grant($scope[0],$subject,$resource);
        }
    }

    public static function form(array $state)
    {
        if (!hash_equals($state['csrf'], isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
            throw new \RuntimeException('Invalid CSRF token');
        }
        $page = substr($_POST['action'], 7);
        (new self($state['user']))->action($page, $_POST);
        Log::flash(Log::writeSuccess('Changes saved'));
        header('Location: ' . Pages::link(self::page($page, $_POST)), true, 303);
    }
}

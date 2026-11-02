<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';
$web = new WebFixture();
try {
    $admin = Agent\Installation::install(['auth'=>'standalone'],'admin','phillip-demo','Administrator');
    $people = ['admin'=>$admin];
    foreach (['alice','bob','carol','visitor'] as $name) $people[$name] = Agent\Identity::createUser($name,'phillip-demo',ucfirst($name));
    foreach (['engineering'=>'Engineering','finance'=>'Finance','sales'=>'Sales'] as $id=>$name) Agent\Mappings::insert('teams',['id'=>$id,'name'=>$name]);
    foreach ([['alice','engineering'],['alice','finance'],['bob','engineering'],['carol','sales']] as $member) Agent\Mappings::insert('memberships',['user_id'=>$people[$member[0]]['id'],'team_id'=>$member[1]]);
    $web->server(dirname(__DIR__).'/public/index.php', true);
    $web->login('admin','phillip-demo','admin');
    $web->login('alice','phillip-demo','alice');
    $web->login('bob','phillip-demo','bob');
    $profileName = 'fixture-http-' . bin2hex(random_bytes(3));
    $threadId = null;
    try {
        check($web->request('/api/admin/registry', null, 'alice')['status'] === 403, 'Registry administration rejects ordinary users');
        $result = $web->request('/api/admin/profile', ['name' => $profileName, 'config' => Agent\Config::profile('default'), 'enabled' => true], 'admin');
        check($result['status'] === 200 && $result['json']['profile']['enabled'], 'Admin Save tests and enables a profile over HTTP');
        $created = $web->request('/api/threads', array_merge(['title' => 'HTTP privacy fixture', 'settings' => ['profile' => $profileName, 'manual' => true, 'tools' => [], 'parameters' => []]], ['submission_key' => bin2hex(random_bytes(16))]), 'alice');
        check($created['status'] === 200, 'User creates a private thread through HTTP');
        $threadId = $created['json']['thread']['id'];
        check($web->request('/api/threads/' . $threadId, null, 'bob')['status'] === 403, 'Another user cannot read private HTTP thread');
        check($web->request('/api/threads/' . $threadId, null, 'admin')['status'] === 403, 'Administrator cannot read another users private thread');
        check($web->request('/api/threads/' . $threadId . '/share', ['visibility' => 'team', 'team_id' => 'engineering'], 'alice')['status'] === 200, 'Owner shares with exactly one team');
        check($web->request('/api/threads/' . $threadId, null, 'bob')['status'] === 200, 'Team member reads shared thread');
        check($web->request('/api/threads/' . $threadId . '/settings', ['settings' => ['profile' => $profileName]], 'bob')['status'] === 403, 'Participant cannot change owner chat settings');
        $web->request('/api/threads/' . $threadId . '/messages', array_merge(['content' => 'Alice first'], ['submission_key' => bin2hex(random_bytes(16))]), 'alice');
        $web->request('/api/threads/' . $threadId . '/messages', array_merge(['content' => 'Bob next'], ['submission_key' => bin2hex(random_bytes(16))]), 'bob');
        $messages = $web->request('/api/threads/' . $threadId, null, 'alice')['json']['messages'];
        check($web->request('/api/messages/' . $messages[2]['id'] . '/edit', ['content' => 'Forbidden'], 'alice')['status'] === 403, 'Owner cannot edit a participants message through HTTP');
        check($web->request('/api/messages/' . $messages[1]['id'] . '/edit', ['content' => 'Alice corrected'], 'alice')['status'] === 200, 'Message author can replace their own history');
        check(count($web->request('/api/threads/' . $threadId, null, 'alice')['json']['messages']) === 2, 'Fork replacement removes later participants message');
        check($web->request('/api/threads/' . $threadId . '/share', ['visibility' => 'organization'], 'alice', false, 'invalid')['status'] === 403, 'CSRF protection enforced');
    } finally {
        if ($threadId) (new Agent\Thread($threadId))->delete();
        Agent\Database::connection()->prepare('DELETE FROM profiles WHERE name = ?')->execute([$profileName]);
    }
} finally { $web->close(); }

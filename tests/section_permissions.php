<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';
// Sections are the cards of a page: a page grant opens all of them, a section grant opens one (and the page around it).
$web = new WebFixture();
try {
    $admin = Agent\Installation::install(['auth' => 'standalone'], 'admin', 'phillip-demo', 'Administrator');
    $people = [];
    foreach (['alice', 'bob', 'carol', 'dave'] as $name) $people[$name] = Agent\Identity::createUser($name, 'phillip-demo', ucfirst($name));
    Agent\Mappings::insert('teams', ['id' => 'ops', 'name' => 'Ops']);
    Agent\Mappings::insert('memberships', ['user_id' => $people['dave']['id'], 'team_id' => 'ops']);
    $profile = Agent\Profiles::save('default', ['url' => 'http://127.0.0.1:1/v1/chat/completions', 'model' => 'fixture', 'stream' => true]);
    Agent\Database::execute('UPDATE profiles SET enabled=TRUE, supports_chat=TRUE WHERE id=?', [$profile['id']]);
    $web->server(dirname(__DIR__) . '/public/index.php', true);
    foreach (['admin', 'alice', 'bob', 'carol', 'dave'] as $name) $web->login($name, 'phillip-demo', $name);

    // Nothing is delegated by default, and administrators-only sections cannot be delegated at all.
    check(Agent\Pages::sections($people['alice']['id'], 'models') === [], 'Without grants a person has no section of System Settings');
    check(Agent\Pages::sections($admin['id'], 'access') === ['users', 'groups', 'pages', 'tools'] && Agent\Pages::sections($admin['id'], 'knowledge') === ['memories', 'graph'], 'Administrators have every section');
    check(!isset(Agent\Pages::sectionResources()['section.access.pages']) && !isset(Agent\Pages::sectionResources()['section.access.tools']) && isset(Agent\Pages::sectionResources()['section.access.users']), 'Pages and Tools are not delegable sections');
    check(Agent\Pages::sections($people['alice']['id'], 'personas') === ['personas', 'experts'], 'Everyone has both Personas sections through the default page grant');

    // A section grant opens its page around that section, and only that section.
    Agent\Identity::grant('user', $people['alice']['id'], 'section.models.profiles');
    check(Agent\Pages::sections($people['alice']['id'], 'models') === ['profiles'] && Agent\Pages::allowed($people['alice']['id'], 'models'), 'A section grant opens the page around it');
    check(in_array('models', array_column(Agent\Pages::permitted($people['alice']['id']), 'id'), true), 'The page appears in the menu for a section holder');
    $html = $web->request('/models', null, 'alice')['text'];
    check(strpos($html, 'id="profiles"') !== false && strpos($html, 'id="model-registry"') === false && strpos($html, 'id="attachment-parsing"') === false && strpos($html, 'id="services"') === false, 'The page renders only the granted section');
    check($web->request('/api/admin/profile-enabled', ['name' => 'default', 'enabled' => false], 'alice')['status'] === 200 && !Agent\Database::row("SELECT enabled FROM profiles WHERE name='default'")['enabled'], 'The granted section can switch a profile off through its API');
    check($web->request('/api/admin/profile-enabled', ['name' => 'default', 'enabled' => false], 'bob')['status'] === 403, 'Someone without the grant cannot');
    foreach ([['/api/admin/services', null], ['/api/admin/attachment-parsers', null], ['/api/admin/registry', null], ['/api/admin/scan', ['cidr' => '127.0.0.1/32']]] as [$path, $body]) {
        check($web->request($path, $body, 'alice')['status'] === 403, "A different section of the page stays closed: $path");
    }
    check($web->request('/models?configure=services', null, 'alice')['status'] === 200 && strpos($web->request('/models?configure=services', null, 'alice')['text'], 'id="service-form"') === false, 'Opening another section\'s editor by URL shows nothing');
    Agent\Database::execute("UPDATE profiles SET enabled=TRUE WHERE name='default'");
    check($web->request('/api/admin/profile-enabled', ['name' => 'default', 'enabled' => false], 'admin')['status'] === 200 && !Agent\Database::row("SELECT enabled FROM profiles WHERE name='default'")['enabled'], 'Administrators switch profiles through the same route');

    Agent\Database::execute("UPDATE profiles SET enabled=TRUE WHERE name='default'"); // an install with no enabled model shows its setup wizard

    // A page grant still opens every section.
    Agent\Identity::grant('user', $people['bob']['id'], 'page.models');
    check(Agent\Pages::sections($people['bob']['id'], 'models') === ['models', 'profiles', 'attachment-parsing', 'services'], 'A page grant opens every section');
    check($web->request('/api/admin/services', null, 'bob')['status'] === 200 && $web->request('/api/admin/attachment-parsers', null, 'bob')['status'] === 200, 'Page holders reach every section\'s API');

    // Permissions & Users: users can be delegated apart from groups; pages and tools never.
    Agent\Identity::grant('user', $people['carol']['id'], 'section.access.users');
    check(Agent\Pages::sections($people['carol']['id'], 'access') === ['users'], 'A section grant delegates Users alone');
    $access = $web->request('/access', null, 'carol')['text'];
    check(strpos($access, 'id="access-users"') !== false && strpos($access, 'id="access-groups"') === false && strpos($access, 'id="access-pages"') === false && strpos($access, 'id="tools"') === false, 'Permissions & Users shows only the Users card');
    $manage = $web->request('/api/manage/users', null, 'carol');
    check($manage['status'] === 200 && $manage['json']['permissions'] === [] && $manage['json']['pages'] === [], 'A delegate sees users, never who may open what');
    check($web->request('/api/manage/groups', null, 'carol')['status'] === 403, 'The Groups section is closed to a Users delegate');
    foreach (['tools' => ['resource' => 'Agent\\Tools\\Tasks'], 'navigation' => ['id' => 'access', 'title' => 'x', 'path' => '/access'], 'sections' => ['resource' => 'section.models.services'], 'permissions' => ['scope' => 'user:' . $people['carol']['id'], 'resource' => 'page.models']] as $entity => $body) {
        check($web->request('/api/manage/' . $entity, $body, 'carol')['status'] === 403, "A delegate cannot change $entity");
    }
    $created = $web->request('/api/manage/users', ['username' => 'section-made', 'display_name' => 'Section made', 'password' => 'phillip-demo'], 'carol');
    check($created['status'] === 200, 'A Users delegate can create users');
    check($web->request('/api/manage/memberships', ['user_id' => $people['alice']['id'], 'group_id' => 'ops'], 'carol')['status'] === 200, 'Memberships follow either Users or Groups');

    // Administrators grant sections from the Pages table.
    $grant = $web->request('/api/manage/sections', ['resource' => 'section.knowledge.graph', 'allowed_users' => [$people['dave']['id']], 'allowed_groups' => []], 'admin');
    check($grant['status'] === 200 && Agent\Pages::sections($people['dave']['id'], 'knowledge') === ['graph'], 'Administrators grant a section to a user');
    check($web->request('/api/manage/sections', ['resource' => 'section.access.pages', 'allowed_users' => [$people['dave']['id']]], 'admin')['status'] === 400, 'A section that cannot be delegated is refused');
    check($web->request('/api/manage/sections', ['resource' => 'section.nope.nope', 'allowed_users' => []], 'admin')['status'] === 400, 'An unknown section is refused');
    $pagesCard = $web->request('/access', null, 'admin')['text'];
    check(strpos($pagesCard, 'Edit section access Graph') !== false && strpos($pagesCard, 'Dave') !== false && strpos($pagesCard, 'class="section-row"') !== false, 'The Pages table lists each section with who may open it');
    $form = $web->request('/access?edit=sections&resource=' . rawurlencode('section.models.services'), null, 'admin');
    check($form['status'] === 200 && strpos($form['text'], 'name="allowed_groups[]"') !== false, 'Section access has a PHP-rendered editor');
    check($web->request('/access?edit=sections&resource=' . rawurlencode('section.models.services'), null, 'carol')['status'] === 403, 'Only administrators open it');

    // Memory: the graph and the memories are separate sections.
    $memory = $web->request('/memory', null, 'dave')['text'];
    check(strpos($memory, 'id="memory-graph"') !== false && strpos($memory, 'id="memories"') === false, 'A Graph delegate sees the graph and no memories');
    check($web->request('/api/knowledge/1', ['content' => 'x'], 'dave')['status'] === 403, 'Editing memories needs the Memories section');
    check(strpos($web->request('/memory', null, 'admin')['text'], 'id="memories"') !== false, 'Administrators see both');

    // Persona routes pick their section from the persona: characters have a sheet, experts do not.
    check(Agent\Personas::sectionFor('/api/personas', ['name' => 'N', 'tagline' => 't', 'bio' => 'b']) === 'personas' && Agent\Personas::sectionFor('/api/personas', ['name' => 'N', 'prompt' => 'p']) === 'experts', 'A new persona is a character when it has a bio, an expert when it has only instructions');
    $expert = Agent\Database::row("INSERT INTO personas (name, prompt, visibility, tools) VALUES ('Section expert', 'Do things.', 'organization', '[]') RETURNING id");
    check(Agent\Personas::sectionFor('/api/personas/' . $expert['id'] . '/enabled', []) === 'experts', 'An existing instruction-only persona belongs to Experts');
    echo "ALL PASS\n";
} catch (Throwable $e) { fwrite(STDERR, $web->diagnostics()); throw $e; }
finally { $web->close(); }

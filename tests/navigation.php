<?php
require __DIR__.'/helpers.php';
require __DIR__.'/fixtures/WebFixture.php';
$web = new WebFixture();
try {
    $admin = Agent\Installation::install(['auth'=>'standalone'],'admin','phillip-demo','Administrator');
    $people = ['admin'=>$admin];
    foreach (['alice','bob','carol','visitor'] as $name) $people[$name] = Agent\Identity::createUser($name,'phillip-demo',ucfirst($name));
    foreach (['engineering'=>'Engineering','finance'=>'Finance','sales'=>'Sales'] as $id=>$name) Agent\Mappings::insert('teams',['id'=>$id,'name'=>$name]);
    foreach ([['alice','engineering'],['alice','finance'],['bob','engineering'],['carol','sales']] as $member) Agent\Mappings::insert('memberships',['user_id'=>$people[$member[0]]['id'],'team_id'=>$member[1]]);
    $engine = $web->server(__DIR__.'/fixtures/onboarding_engine.php');
    $profile = Agent\Profiles::save('default',['url'=>$engine.'/v1/chat/completions','model'=>'onboarding-chat','stream'=>true,'max_tokens'=>1024]);
    Agent\Database::execute('UPDATE profiles SET enabled=TRUE,supports_chat=TRUE,supports_streaming=TRUE,supports_tools=TRUE WHERE id=?',[$profile['id']]);
    $web->server(dirname(__DIR__).'/public/index.php',true);
    foreach (['admin','alice','bob','carol'] as $name) $web->login($name,'phillip-demo',$name);
    $alice = $people['alice']['id']; $bob = $people['bob']['id']; $carol = $people['carol']['id'];
    $manage = new Agent\Administration($admin);

    foreach (['0'=>'Everyone','1'=>'Administrators','2'=>'Developers'] as $id=>$name) check(Agent\Mappings::rows('teams',['id'=>(string)$id])[0]['name']===$name,'Built-in group '.$id.' exists');
    check(Agent\Mappings::rows('memberships',['user_id'=>$admin['id'],'team_id'=>'1']) && Agent\Mappings::rows('memberships',['user_id'=>$admin['id'],'team_id'=>'2']),'First administrator belongs to both Administrators and Developers');
    check(count(Agent\Identity::teams($alice))===3,'Membership is additive and Everyone is implicit');
    check(!Agent\Access::inTeam('missing-user','0'),'Everyone does not authenticate an unknown identity');
    check($admin['is_admin'] && $admin['is_developer'],'Initial identity is hydrated with both operator roles');
    check(array_column(Agent\Pages::permitted($alice),'id')===['conversations'],'Everyone grants only chat, not other pages');
    check(count(Agent\Mappings::rows('permissions',['scope_type'=>'team','scope_id'=>'0']))===5,'Everyone starts with exactly chat and four web/memory tool grants');
    foreach (['SearchWeb','Fetch','BrowseWeb','Memory'] as $tool) check(Agent\Access::tool($alice,'Agent\\Tools\\'.$tool),'Default web/memory tool: '.$tool);
    check(Agent\Access::allows($admin['id'],'unknown.resource'),'Administrators automatically have permissions for future resources');
    check(!Agent\Access::allows($bob,'unknown.resource',true),'Legacy defaults cannot grant ordinary users access');
    check(Agent\Access::decisions($admin['id'],['page.access'=>false,'NewTool'=>null])===['page.access'=>true,'NewTool'=>true],'Batch permission checks recognize Administrators');
    Agent\Identity::grant('group','1','NewTool');
    Agent\Identity::grant('team',1,'page.access',false);
    check(!Agent\Mappings::rows('permissions',['scope_type'=>'team','scope_id'=>'1']),'Administrators never need stored grants, even after grant or revoke calls');
    check(Agent\Access::allows($admin['id'],'page.access'),'Revoking a redundant administrator grant cannot remove implicit access');
    $operator = Agent\Identity::createUser('temporary-operator','phillip-demo','Temporary operator',true);
    $web->login('temporary-operator','phillip-demo','operator');
    check($web->request('/access',null,'operator')['status']===200,'Administrator membership permits routes without grants');
    $manage->action('users',['id'=>$operator['id'],'username'=>$operator['username'],'display_name'=>$operator['display_name'],'active'=>false]);
    check(!Agent\Access::allows($operator['id'],'page.access'),'Inactive Administrators cannot use implicit permissions');
    $manage->action('users',['id'=>$operator['id'],'username'=>$operator['username'],'display_name'=>$operator['display_name'],'active'=>true,'groups'=>[]]);
    check(!Agent\Access::allows($operator['id'],'NewTool') && $web->request('/access',null,'operator')['status']===403,'Removing administrator membership immediately revokes implicit access in existing sessions');
    check(!Agent\Identity::user(['id'=>$bob,'is_admin'=>true])['is_admin'],'Supplied privilege flags cannot manufacture administrator membership');
    check($web->request('/chat',null,'alice')['status']===200,'Everyone can open /chat');
    foreach (['/projects','/personas','/models','/access','/api/manage/users','/fragments/dialog?kind=manage&entity=users'] as $path) check($web->request($path,null,'alice')['status']===403,'No grant means no access: '.$path);
    $html = $web->request('/chat',null,'alice')['text'];
    check(strpos($html,'data-page="access"')===false && strpos($html,'Recent work')===false,'Forbidden menu entries and old history heading are absent');

    Agent\Identity::grant('group','engineering','page.benchmarks');
    Agent\Identity::grant('group','finance','page.models');
    check(Agent\Pages::allowed($alice,'benchmarks') && Agent\Pages::allowed($alice,'models'),'Permissions from multiple groups are a union');
    Agent\Identity::grant('user',$alice,'page.benchmarks');
    Agent\Identity::grant('group','engineering','page.benchmarks',false);
    check(Agent\Pages::allowed($alice,'benchmarks'),'Revoking a group grant cannot override an independent user grant');
    Agent\Identity::grant('user',$alice,'page.benchmarks',false);
    foreach (['/benchmarks','/?view=benchmarks','/fragments/benchmarks','/api/admin/benchmarks'] as $path) check($web->request($path,null,'alice')['status']===403,'Revocation applies immediately to existing sessions: '.$path);
    check(!Agent\Database::row('SELECT * FROM permissions WHERE NOT allowed'),'No deny rows are stored');
    check(!Agent\Pages::allowed($bob,['id'=>'models','enabled'=>true,'default_access'=>true,'handler'=>'models']),'Forged page defaults never establish authority');

    $private = Agent\Thread::create($alice,['title'=>'Private conversation','settings'=>['manual'=>true]]);
    $team = Agent\Thread::create($alice,['title'=>'Engineering conversation','settings'=>['manual'=>true],'visibility'=>'team','team_id'=>'engineering']);
    check($web->request('/api/threads/'.$private->id,null,'admin')['status']===403,'Administrators do not gain private conversation access');
    check($web->request('/api/threads/'.$team->id,null,'admin')['status']===403,'Administrators do not gain content access to unjoined groups');
    $manage->action('memberships',['user_id'=>$bob,'group_id'=>'engineering','remove'=>true]);
    check($web->request('/api/threads/'.$team->id,null,'bob')['status']===403,'Removing membership revokes content access in existing sessions');
    $manage->action('memberships',['user_id'=>$bob,'group_id'=>'engineering']);

    Agent\Identity::grant('user',$alice,'page.access');
    check($web->request('/api/manage/users',['username'=>'delegated-member','display_name'=>'Delegated member','password'=>'phillip-demo','groups'=>['engineering','finance']],'alice')['status']===200,'Combined access management supports multi-group user creation');
    check($web->request('/api/manage/users',['username'=>'escalation','display_name'=>'Escalation','password'=>'phillip-demo','groups'=>['1']],'alice')['status']===403,'Group selection cannot bypass administrator-account protections');
    check($web->request('/api/manage/users',['id'=>$admin['id'],'username'=>'admin','display_name'=>'Stolen','password'=>'stolen-password-10'],'alice')['status']===403,'Delegated access managers cannot reset administrator accounts');
    check($web->request('/api/manage/groups',['name'=>'CSRF bypass'],'admin',false,'bad')['status']===403,'Editors retain CSRF protection');
    foreach (['0','1','2'] as $id) {
        expectException(function()use($manage,$id){$manage->action('groups',['id'=>$id,'remove'=>true,'confirm'=>true]);},'Built-in groups');
        expectException(function()use($manage,$id){$manage->action('groups',['id'=>$id,'name'=>'Wrong name']);},'Built-in group names');
    }
    expectException(function()use($manage){$manage->action('groups',['id'=>'0','name'=>'Everyone','members'=>[]]);},'automatically');
    expectException(function()use($manage,$admin){$manage->action('users',['id'=>$admin['id'],'username'=>'admin','display_name'=>'Admin','groups'=>['2']]);},'current account');
    $group = $manage->action('groups',['name'=>'Editable group','members'=>[$alice,$bob]]);
    check(count(Agent\Mappings::rows('memberships',['team_id'=>$group['id']]))===2,'Group editor assigns multiple members');
    $manage->action('groups',['id'=>$group['id'],'name'=>'Editable group','members'=>[$alice]]);
    check(Agent\Access::inTeam($alice,'finance') && !Agent\Access::inTeam($bob,$group['id']),'Editing one group does not replace other memberships');
    $manage->action('groups',['id'=>$group['id'],'name'=>'Editable group','members'=>[]]);
    $manage->action('groups',['id'=>$group['id'],'remove'=>true,'confirm'=>true]);
    Agent\Identity::grant('user','removed-account','RetiredTool');
    $manage->action('permissions',['scope'=>'user:removed-account','resource'=>'RetiredTool','remove'=>true]);
    check(!Agent\Mappings::rows('permissions',['scope_id'=>'removed-account']),'Obsolete grants can be removed');

    $models = Agent\Pages::get('models');
    $manage->action('navigation',array_replace($models,['path'=>'/local-models','title'=>'Local models']));
    check($web->request('/models')['status']===404 && $web->request('/local-models')['status']===200,'Menu and dispatch use the same edited database route');
    expectException(function()use($manage,$models){$manage->action('navigation',array_replace($models,['create'=>true]));},'already in use');
    $manage->action('navigation',['id'=>'models-alias','handler'=>'models','title'=>'Models alias','path'=>'/models-alias']);
    check($web->request('/models-alias')['status']===200,'Administrators can immediately open new routes without explicit grants');
    check($web->request('/models-alias',null,'alice')['status']===403,'An alias needs its own grant as well as the underlying concept grant');
    Agent\Identity::grant('user',$alice,'page.models-alias');
    check($web->request('/models-alias',null,'alice')['status']===200,'An alias routes after both grants exist');
    $paths = array_column(Agent\Pages::navigation($admin['id']),'path'); $sorted=$paths; natcasesort($sorted);
    check($paths===array_values($sorted),'Routes sort alphanumerically, not by sections or manual positions');
    foreach (['../../private.php','/api/escape','/assets/escape','/health'] as $path) expectException(function()use($manage,$models,$path){$manage->action('navigation',array_replace($models,['path'=>$path]));},'simple page path');
    $manage->action('navigation',array_replace(Agent\Pages::get('models'),['allowed_groups'=>[],'allowed_users'=>[]]));
    check($web->request('/local-models')['status']===200 && $web->request('/api/admin/registry')['status']===200,'Administrators retain route and API access without grants');
    check($web->request('/api/setup/status')['status']===200,'Administrators retain access to setup settings without grants');
    foreach (['/local-models','/api/admin/registry','/api/setup/status'] as $path) check($web->request($path,null,'bob')['status']===403,'Ordinary users still need explicit model permissions: '.$path);
    $manage->action('navigation',array_replace(Agent\Pages::get('models'),['allowed_groups'=>['1','finance'],'allowed_users'=>[$bob]]));
    check(!Agent\Mappings::rows('permissions',['scope_type'=>'team','scope_id'=>'1']),'Route grant payloads cannot recreate redundant administrator grants');
    check($web->request('/local-models',null,'bob')['status']===200,'Route editor grants users through the common permission table');
    $manage->action('navigation',array_replace(Agent\Pages::get('models'),['allowed_groups'=>['finance'],'allowed_users'=>[]]));
    check($web->request('/local-models',null,'bob')['status']===403,'Removing a direct route grant takes effect immediately');
    check(!array_intersect(['enabled','in_navigation','default_access','section','position'],array_keys(Agent\Pages::get('models'))),'Page records contain no parallel visibility or authorization flags');
    $form = $web->request('/access',['action'=>'manage-groups','csrf'=>$web->tokens['admin'],'name'=>'No JavaScript group'],'admin',true);
    check($form['status']===303 && Agent\Mappings::rows('teams',['name'=>'No JavaScript group']),'Ordinary PHP editor forms work without JavaScript');

    foreach (['alice','bob','carol'] as $name) Agent\Identity::grant('user',$people[$name]['id'],'page.projects');
    $project = (new Agent\ProjectFrontend($alice))->create(['title'=>'An intertwined project','profile'=>'default']);
    $projectPage = $web->request(Agent\Pages::link('projects',['project'=>$project->id]),null,'alice');
    check(strpos($projectPage['text'],'Add at least one fully specified ToDo')!==false,'Project readiness requirements render');
    $list = (new Agent\Frontend($alice))->threads(); $ids = array_column($list,'id');
    check(in_array($private->id,$ids,true) && in_array($project->get()['thread_id'],$ids,true),'Conversation/project roots share one history');
    $dates = array_column($list,'activity_at'); $sorted=$dates; rsort($sorted);
    check($dates===$sorted,'Mixed history sorts by actual activity');
    $process=$web->launch([PHP_BINARY,__DIR__.'/project_frontend.php'],true);
    check(proc_close($process)===0,'Existing project frontend boundaries still work');

    if (in_array('--browser',$argv,true)) {
        $web->launch([PHP_BINARY,dirname(__DIR__).'/bin/phill-worker']);
        $browser=$web->launch(['python3',__DIR__.'/ui_organization.py',$web->base],true);
        check(proc_close($browser)===0,'Combined access management and sidebar browser workflow');
    }
} catch (Throwable $e) { fwrite(STDERR,$web->diagnostics()); throw $e; }
finally { $web->close(); }

<?php
require __DIR__.'/helpers.php';
$db = Agent\Database::connection();
$db->beginTransaction();
try {
    $schema = 'access_upgrade_'.bin2hex(random_bytes(6));
    $db->exec('CREATE SCHEMA '.$schema);
    $db->exec('SET LOCAL search_path TO '.$schema.',public');
    foreach (glob(dirname(__DIR__).'/database/*.sql') as $file) {
        if (basename($file) === '028_grants_and_access.sql') break;
        $db->exec(file_get_contents($file));
    }
    Agent\Database::execute("INSERT INTO users(id,username,display_name,is_admin) VALUES ('old-admin','old-admin','Old admin',TRUE),('old-user','old-user','Old user',FALSE)");
    Agent\Database::execute("INSERT INTO settings(key,value) VALUES ('installation','{\"auth\":\"standalone\",\"admin_ids\":[\"old-admin\"]}'::jsonb)");
    Agent\Database::execute("INSERT INTO permissions(scope_type,scope_id,resource,allowed) VALUES ('user','old-user','page.models',TRUE),('user','old-user','page.benchmarks',FALSE),('organization','*','page.projects',TRUE)");
    $db->exec(file_get_contents(dirname(__DIR__).'/database/028_grants_and_access.sql'));
    Agent\Database::execute("UPDATE pages SET enabled=FALSE WHERE id='parsers'");
    $db->exec(file_get_contents(dirname(__DIR__).'/database/029_route_grants.sql'));
    $db->exec(file_get_contents(dirname(__DIR__).'/database/030_administrator_permissions.sql'));
    check(Agent\Identity::user('old-admin')['is_admin'] && Agent\Identity::user('old-admin')['is_developer'],'Upgrade assigns existing administrator to groups 1 and 2');
    check(Agent\Access::allows('old-user','page.models'),'Upgrade retains positive user grants');
    check(!Agent\Access::allows('old-user','page.projects') && !Agent\Access::allows('old-user','page.benchmarks'),'Upgrade removes global/deny rules without creating default access');
    check(Agent\Access::allows('old-admin','unknown.resource'),'Upgraded Administrators automatically have all permissions');
    check(!Agent\Database::rows("SELECT * FROM permissions WHERE scope_type='team' AND scope_id='1'"),'Upgrade removes redundant administrator grants');
    check(count(Agent\Database::rows("SELECT * FROM permissions WHERE scope_type='team' AND scope_id='0'"))===5,'Upgrade gives Everyone only five baseline grants');
    check(Agent\Database::row("SELECT path FROM pages WHERE id='conversations'")['path']==='/chat','Upgrade routes chat through the same /chat page record');
    check(Agent\Database::row("SELECT * FROM tool_permissions WHERE scope_type='user' AND scope_id='old-user' AND tool='page.models'")!==null,'Legacy policy view continues to read the generalized grants');
    check(!Agent\Database::row('SELECT * FROM permissions WHERE NOT allowed'),'Upgrade leaves no deny rules');
    check(!Agent\Pages::allowed('old-user','parsers'),'Previously disabled routes migrate to absent grants for ordinary users');
    check(Agent\Pages::allowed('old-admin','parsers'),'Administrators can access routes regardless of missing grants');
    check(!array_intersect(['enabled','in_navigation'],array_keys(Agent\Pages::get('models'))),'Upgrade removes bespoke route flags');
} finally {
    $db->rollBack(); // Disposable schema and every fixture row were created inside this transaction.
}

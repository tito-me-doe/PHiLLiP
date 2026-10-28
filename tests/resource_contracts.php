<?php
require __DIR__ . '/helpers.php';

check(Agent\Network::cidrs(' 127.0.0.1,192.168.1.0/30,127.0.0.1 ') === ['127.0.0.1', '192.168.1.0/30'], 'Network accepts a host, CSV, or list without caller splitting');
check(iterator_to_array(Agent\Network::hosts('192.168.1.0/30'), false) === ['192.168.1.1', '192.168.1.2'], 'Host enumeration shares range normalization');
check(Agent\Network::ports('80, 443,80') === [80,443] && Agent\Network::ports(8080) === [8080], 'Port contracts normalize CSV, integers and duplicate list entries');
foreach (['1.5', '0', '65536', '80,,443', ['80', true]] as $bad) expectException(function () use ($bad) { Agent\Network::ports($bad); }, 'Ports must');
expectException(function () { Agent\Network::cidrs('127.0.0.1/32/extra'); }, 'Invalid IPv4');
expectException(function () { Agent\Network::cidrs('127.0.0.1,'); }, 'Invalid IPv4');
$scan = Agent\Scanner::configuration('{"cidr":["127.0.0.1"],"ports":[80,443],"all":false}');
check($scan === ['cidr'=>'127.0.0.1','ports'=>'80,443','all'=>false], 'Queued scan configuration owns its transport-independent contract');
check(!Agent\Scanner::configuration(['cidr'=>'127.0.0.1','ports'=>80,'all'=>'false'])['all'], 'Form-style false does not expand into an all-port scan');
expectException(function () { Agent\Scanner::configuration(['cidr'=>'127.0.0.1','ports'=>80,'all'=>'maybe']); }, 'boolean');

$alice = Agent\Identity::authenticate(['username'=>'alice','password'=>'phillip-demo']);
$bob = Agent\Identity::user(['username'=>'bob']);
$frontend = new Agent\Frontend((object)$alice); $thread = null; $file = null;
expectException(function () { new Agent\Frontend(null); }, 'Authentication required');
try {
    $thread = $frontend->create((object)['title'=>'Resource contracts', 'settings'=>(object)['profile'=>'default','manual'=>true]]);
    check($thread->get()['owner_id'] === $alice['id'], 'Frontend hydrates its user reference and delegates named creation options');
    check(!Agent\Access::visible(null, $thread->get()) && !Agent\Access::tool(null, 'Agent\\Tools\\Fetch'), 'Anonymous access checks remain false rather than invoking username fallback');
    $file = Agent\Attachments::store($alice['id'], 'contract.txt', 'CANONICAL_ATTACHMENT_PAYLOAD');
    foreach ([$file['id'], $file, (object)$file, json_encode($file), [$file['id']], json_encode([$file])] as $shape) {
        check(Agent\Attachments::normalize($shape) === [$file], 'Attachment contract accepts an ID, row, object, JSON, or list');
        $single = is_array($shape) && !isset($shape['id']) ? $file : $shape;
        if (is_string($single) && substr($single, 0, 1) === '[') $single = $file;
        check(Agent\Attachments::get($single)['id'] === $file['id'], 'Single attachment lookup accepts its row or identifier');
    }
    check(Agent\Attachments::normalize($file['id'] . ', ' . $file['id']) === [$file], 'Attachment CSV references deduplicate before size accounting');
    check(Agent\Attachments::metadata('  ' . $file['id'] . "\n") === $file, 'Attachment identifiers safely trim boundary whitespace');
    check(Agent\Attachments::normalize(null) === [] && Agent\Attachments::normalize('') === [], 'Optional attachment inputs have a canonical empty list');
    check(Agent\Attachments::metadata(['id'=>$file['id'],'name'=>'FORGED'])['name'] === 'contract.txt', 'Supplied attachment metadata never overrides the stored row');
    check(strpos(Agent\Attachments::messageText(['attachments'=>$file['id']]), 'CANONICAL_ATTACHMENT_PAYLOAD') !== false, 'Attachment text retrieval owns reference-shape recognition too');
    foreach ([false, ['name'=>'no id'], '{bad', [$file['id'],false]] as $bad) expectException(function () use ($bad) { Agent\Attachments::normalize($bad); }, 'ttachment');
    expectException(function () use ($file) { Agent\Attachments::normalize(array_fill(0,9,$file['id'])); }, 'eight');
    expectException(function () use ($bob,$file) { (new Agent\Frontend($bob))->attachment($file); }, 'access denied');
    $run = $frontend->send($thread->get(), json_encode(['content'=>'Known message payload', 'attachments'=>$file['id'], 'submission_key'=>bin2hex(random_bytes(16))]));
    check($thread->messages()[1]['raw']['attachments'] == [$file], 'Frontend passes JSON message/attachment inputs through the shared Message contract');
    check($frontend->attachment((object)$file)['id'] === $file['id'], 'Frontend attachment access resolves the same row contract before checking policy');
    $frontend->share($thread, 'organization');
    check($thread->get()['visibility'] === 'organization', 'Thread-reference inputs retain canonical IDs for frontend writes');
    $frontend->configure($thread, ['profile'=>['name'=>'default','enabled'=>true,'config'=>['url'=>'http://forged','model'=>'forged']]]);
    check($thread->get()['settings']['profile'] === 'default', 'Frontend stores the authoritative enabled profile reference, never supplied execution settings');
    $disabledName = 'resource-disabled-' . bin2hex(random_bytes(5));
    $disabled = Agent\Profiles::save($disabledName, Agent\Config::profile('default'), false);
    try {
        expectException(function () use ($frontend, $disabled) { $frontend->create(['settings'=>['profile'=>['id'=>$disabled['id'],'enabled'=>true]]]); }, 'enabled and tested');
    } finally { Agent\Database::connection()->prepare('DELETE FROM profiles WHERE name=?')->execute([$disabledName]); }
} finally {
    if ($thread) $thread->delete();
    if ($file) Agent\Database::connection()->prepare('DELETE FROM attachments WHERE id=?')->execute([$file['id']]);
}

$parsers = Agent\AttachmentParser::configuration();
try {
    Agent\AttachmentParser::configure(json_encode((object)$parsers));
    check(Agent\AttachmentParser::configuration() === $parsers, 'Parser settings accept the JSON already available in the form');
    foreach (['[]'=>'JSON object','null'=>'JSON object','{"image/*":false}'=>'profile'] as $bad => $error) expectException(function () use ($bad) { Agent\AttachmentParser::configure($bad); }, $error);
} finally { Agent\AttachmentParser::configure($parsers); }

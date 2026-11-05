<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';
// The Memory page: paginated memories, a server-drawn graph of what they relate, and search that drives both.
$web = new WebFixture();
try {
    $admin = Agent\Installation::install(['auth' => 'standalone'], 'admin', 'phillip-demo', 'Administrator');
    $alice = Agent\Identity::createUser('alice', 'phillip-demo', 'Alice');
    $profile = Agent\Profiles::save('default', ['url' => 'http://127.0.0.1:1/v1/chat/completions', 'model' => 'fixture', 'stream' => true]);
    Agent\Database::execute('UPDATE profiles SET enabled=TRUE, supports_chat=TRUE WHERE id=?', [$profile['id']]);
    Agent\Identity::grant('user', $alice['id'], 'page.knowledge');
    $web->server(dirname(__DIR__) . '/public/index.php', true);
    foreach (['admin', 'alice'] as $name) $web->login($name, 'phillip-demo', $name);

    $thread = Agent\Thread::create($admin['id'], 'Memory fixture', ['manual' => true], 'organization');
    $thread->enqueue($admin['id'], 'Remember things.');
    $source = $thread->messages()[1]['id'];
    $private = Agent\Thread::create($admin['id'], 'Private fixture', ['manual' => true], 'private');
    $private->enqueue($admin['id'], 'Secret.');
    $privateSource = $private->messages()[1]['id'];

    // 60 memories: a ring of 20 things, relations between neighbours, one hostile label, one private claim.
    $things = [];
    for ($i = 0; $i < 20; $i++) $things[] = 'Thing ' . $i;
    $things[3] = 'Hostile <script>alert(1)</script>';
    for ($i = 0; $i < 60; $i++) {
        $subject = $things[$i % 20];
        $object = $things[($i + 1) % 20];
        Agent\Knowledge::remember($thread->id, $admin['id'], "Fixture memory $i: $subject relates to $object", $subject, [$source], $i % 2 ? 'follows' : 'precedes', $object, 'fact');
        if ($i === 0) Agent\Database::execute('UPDATE claims SET visibility=? WHERE thread_id=?', ['organization', $thread->id]);
    }
    Agent\Database::execute("UPDATE claims SET visibility='organization' WHERE thread_id=?", [$thread->id]);
    Agent\Knowledge::remember($private->id, $admin['id'], 'PRIVATE_FIXTURE_CLAIM Secret thing relates to Thing 1', 'Secret thing', [$privateSource], 'hides', 'Thing 1', 'fact');

    // Pagination: newest first, clamped, searchable.
    $first = Agent\Knowledge::recallPage('', $alice['id'], 1, 25);
    check($first['total'] === 60 && $first['pages'] === 3 && count($first['rows']) === 25 && $first['page'] === 1, 'Memories are paged 25 at a time with a total');
    check(strpos($first['rows'][0]['content'], 'Fixture memory 59') === 0, 'Newest memories come first');
    $last = Agent\Knowledge::recallPage('', $alice['id'], 3, 25);
    check(count($last['rows']) === 10 && strpos($last['rows'][9]['content'], 'Fixture memory 0:') === 0, 'The last page holds the remainder');
    check(Agent\Knowledge::recallPage('', $alice['id'], 99, 25)['page'] === 3 && Agent\Knowledge::recallPage('', $alice['id'], -4, 25)['page'] === 1, 'Out-of-range pages are clamped');
    check(Agent\Knowledge::recallPage('Fixture memory 4', $alice['id'])['total'] === 11, 'Search narrows the pages');
    check(Agent\Knowledge::recallPage('PRIVATE_FIXTURE_CLAIM', $alice['id'])['total'] === 0 && Agent\Knowledge::recallPage('PRIVATE_FIXTURE_CLAIM', $admin['id'])['total'] === 1, 'Another person\'s private memories never appear');

    $page = $web->request('/memory', null, 'alice')['text'];
    check(strpos($page, 'Page 1 of 3 · 60 memories') !== false && strpos($page, 'rel="next"') !== false && strpos($page, 'rel="prev"') === false, 'The page shows a pager on the first page');
    $second = $web->request('/memory?page=2', null, 'alice')['text'];
    check(strpos($second, 'Page 2 of 3') !== false && strpos($second, 'rel="prev"') !== false && strpos($second, 'rel="next"') !== false, 'Both arrows appear in the middle');
    $filtered = $web->request('/memory?q=' . rawurlencode('Thing 7') . '&page=1', null, 'alice')['text'];
    check(strpos($filtered, 'Fixture memory') !== false && strpos($filtered, 'Page 1 of 1') !== false && strpos($filtered, 'Show all') !== false, 'Search keeps the pager and offers to show everything');
    check(strpos($web->request('/memory?q=nothing-matches-this', null, 'alice')['text'], 'No matching memories yet.') !== false, 'An empty search says so');

    // The graph.
    $edges = Agent\Knowledge::graphEdges('', $alice['id'], 120);
    check(count($edges) === 60 && !in_array('Secret thing', array_column($edges, 'subject'), true), 'The graph draws visible relations only');
    $near = Agent\Knowledge::graphEdges('Thing 7', $alice['id'], 120);
    check($near !== [] && count($near) < 60, 'A search draws the neighbourhood of what it matches');
    $layout = Agent\GraphLayout::layout($edges);
    check(count($layout['nodes']) === 20 && count($layout['edges']) === 20, 'The layout keeps every thing, and draws a relation stated by several memories once');
    check($layout === Agent\GraphLayout::layout($edges), 'The layout is deterministic');
    foreach ($layout['nodes'] as $node) check($node['x'] >= 0 && $node['x'] <= 760 && $node['y'] >= 0 && $node['y'] <= 440 && $node['r'] >= 6, 'Node ' . $node['label'] . ' stays on the canvas');
    $busy = [];
    for ($i = 0; $i < 200; $i++) $busy[] = ['subject' => 'Hub', 'relation' => 'links', 'object' => 'Spoke ' . $i];
    $trimmed = Agent\GraphLayout::layout($busy, 760, 440, 40);
    check(count($trimmed['nodes']) === 40 && $trimmed['nodes'][0]['label'] === 'Hub', 'Too many nodes are trimmed to the best connected');

    check(strpos($page, '<svg class="graph') !== false && substr_count($page, 'class="graph-node') === 20 && strpos($page, 'marker-end="url(#graph-arrow)"') !== false, 'The page draws the graph inline');
    check(strpos($page, 'Hostile <script>') === false && strpos($page, 'Hostile &lt;script&gt;') !== false, 'Labels are escaped');
    check(strpos($page, 'href="/memory?q=Thing+7"') !== false || strpos($page, 'q=Thing+7') !== false, 'A node links to the search for it');
    check(strpos($page, 'PRIVATE_FIXTURE_CLAIM') === false && strpos($page, 'Secret thing') === false, 'Private memories stay out of the page and the graph');
    $searched = $web->request('/memory?q=' . rawurlencode('Thing 7'), null, 'alice')['text'];
    check(substr_count($searched, 'class="graph-node') < 20 && strpos($searched, 'class="graph-node selected"') !== false, 'A search narrows the graph and highlights the match');
    check(strpos($web->request('/memory?q=nothing-matches-this', null, 'alice')['text'], 'No connections match.') !== false, 'An empty graph says so');
    echo "ALL PASS\n";
} catch (Throwable $e) { fwrite(STDERR, $web->diagnostics()); throw $e; }
finally { $web->close(); }

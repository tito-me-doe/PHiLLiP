<?php
require __DIR__ . '/helpers.php';
require __DIR__ . '/fixtures/WebFixture.php';

use Agent\VectorStore;
use Agent\GraphStore;

$fixture = new WebFixture();
try {
    Agent\Installation::install(['auth' => 'standalone'], 'admin', 'fixture-secret', 'Admin');
    $owner = Agent\Cli::identity()['owner'];

    // ---------- VectorStore lifecycle ----------
    $collection = VectorStore::createCollection('fixture-collection', 3, 'precomputed', $owner);
    check((int)$collection['dimension'] === 3, 'Vector collection is created with the requested dimension');
    check($collection['model'] === 'precomputed', 'Vector collection records the embedding model');

    $list = VectorStore::listCollections($owner);
    check(count($list) === 1, 'Listing returns the created collection for its owner');

    // Upsert precomputed vectors deterministically (no model endpoint needed).
    $a = VectorStore::upsert($collection['id'], 'item-a', [0.9, 0.1, 0.0], 'alpha', ['tag' => 'a']);
    $b = VectorStore::upsert($collection['id'], 'item-b', [0.1, 0.9, 0.0], 'beta', ['tag' => 'b']);
    $c = VectorStore::upsert($collection['id'], 'item-c', [0.0, 0.1, 0.9], 'gamma', ['tag' => 'c']);
    check($a['item_id'] === 'item-a' && $b['item_id'] === 'item-b', 'Upsert returns stored item ids');

    // Re-upserting the same item updates in place (no duplicate).
    $a2 = VectorStore::upsert($collection['id'], 'item-a', [0.9, 0.1, 0.0], 'alpha-updated', ['tag' => 'a2']);
    check($a2['id'] === $a['id'] && $a2['content'] === 'alpha-updated', 'Re-upsert updates the existing item');

    $got = VectorStore::get($collection['id'], 'item-a');
    check($got['content'] === 'alpha-updated' && $got['metadata']['tag'] === 'a2', 'Get returns the latest upserted content and metadata');

    // ---------- topK similarity ranking ----------
    // Query closest to item-a ([0.9,0.1,0.0]) should rank item-a first.
    $top = VectorStore::topK($collection['id'], [0.9, 0.1, 0.0], 3);
    check(count($top) === 3, 'topK returns up to the requested bound');
    check($top[0]['item_id'] === 'item-a', 'topK ranks the most similar item first');
    check($top[0]['similarity'] > $top[1]['similarity'], 'topK results are ordered by descending similarity');
    check($top[2]['item_id'] === 'item-c', 'topK ranks the least similar item last');

    // topK bound is enforced.
    $topOne = VectorStore::topK($collection['id'], [0.9, 0.1, 0.0], 1);
    check(count($topOne) === 1, 'topK honors a limit of one');

    // ---------- Dimension validation ----------
    expectException(function () use ($collection) {
        VectorStore::upsert($collection['id'], 'bad-dim', [0.1, 0.2], 'short');
    }, 'Vector dimension does not match collection dimension');

    expectException(function () use ($collection) {
        VectorStore::upsert($collection['id'], 'bad-finite', [INF, 0.1, 0.0], 'infinite');
    }, 'Vector elements must be finite');

    expectException(function () use ($collection) {
        VectorStore::upsert($collection['id'], 'bad-nan', [NAN, 0.1, 0.0], 'nan');
    }, 'Vector elements must be finite');

    expectException(function () use ($collection) {
        VectorStore::topK($collection['id'], [0.1, 0.2], 3);
    }, 'Vector dimension does not match collection dimension');

    // ---------- Isolation ----------
    $other = VectorStore::createCollection('other-collection', 3, 'precomputed', $owner);
    VectorStore::upsert($other['id'], 'isolated', [0.9, 0.1, 0.0], 'isolated-content');
    $otherTop = VectorStore::topK($collection['id'], [0.9, 0.1, 0.0], 3);
    $ids = array_column($otherTop, 'item_id');
    check(!in_array('isolated', $ids, true), 'topK is isolated to its own collection');

    $ownList = VectorStore::listCollections($owner);
    check(count($ownList) === 2, 'Listing shows both collections for the owner');

    // ---------- Delete ----------
    VectorStore::delete($collection['id'], 'item-b');
    expectException(function () use ($collection) {
        VectorStore::get($collection['id'], 'item-b');
    }, 'Vector item not found');
    $afterDelete = VectorStore::topK($collection['id'], [0.1, 0.9, 0.0], 3);
    check(!in_array('item-b', array_column($afterDelete, 'item_id'), true), 'Deleted items do not appear in topK');

    // ---------- GraphStore ----------
    $e1 = GraphStore::createEntity('namespace-alpha', 'Alpha', ['type' => 'place']);
    $e2 = GraphStore::createEntity('namespace-alpha', 'Beta', ['type' => 'place']);
    $e3 = GraphStore::createEntity('namespace-beta', 'Alpha', ['type' => 'thing']);
    check($e1['id'] !== $e2['id'] && $e1['id'] !== $e3['id'], 'Entities are distinct across namespace/name');

    // Resolve by namespace + name.
    $resolved = GraphStore::resolve('namespace-alpha', 'Alpha');
    check($resolved['id'] === $e1['id'], 'Resolve returns the matching entity');

    // Same name, different namespace resolves separately.
    $resolvedBeta = GraphStore::resolve('namespace-beta', 'Alpha');
    check($resolvedBeta['id'] === $e3['id'], 'Resolve distinguishes entities by namespace');

    // Not found.
    expectException(function () {
        GraphStore::resolve('namespace-gone', 'Missing');
    }, 'Entity not found');

    // Alias creation and resolution.
    GraphStore::createAlias($e1['id'], 'the-alpha');
    $byAlias = GraphStore::resolveByAlias('the-alpha');
    check($byAlias['id'] === $e1['id'], 'Alias resolves to its entity');

    expectException(function () {
        GraphStore::resolveByAlias('does-not-exist');
    }, 'Alias not found');

    // Edge creation and neighbors.
    GraphStore::createEdge($e1['id'], $e2['id'], 'connected-to', ['since' => 1]);
    GraphStore::createEdge($e2['id'], $e3['id'], 'linked-to');
    $neigh = GraphStore::neighbors($e1['id'], 'out', 10);
    check(count($neigh) === 1 && $neigh[0]['name'] === 'Beta' && $neigh[0]['relation'] === 'connected-to', 'Outgoing neighbors return connected entities with relations');

    $inNeigh = GraphStore::neighbors($e2['id'], 'in', 10);
    check(count($inNeigh) === 1 && (int)$inNeigh[0]['id'] === (int)$e1['id'] && $inNeigh[0]['namespace'] === 'namespace-alpha', 'Incoming neighbors return entities that point to the target, not the target\'s own outgoing edges');
    check(GraphStore::neighbors($e1['id'], 'in', 10) === [], 'An entity nothing points to has no incoming neighbors');

    $both = GraphStore::neighbors($e2['id'], 'both', 10);
    check(count($both) === 2, 'Both-direction neighbors include inbound and outbound');

    // ---------- Traversal ----------
    $traversed = GraphStore::traversal($e1['id'], 2, 50);
    $names = array_column($traversed, 'name');
    check(in_array('Beta', $names, true) && in_array('Alpha', $names, true), 'Traversal reaches neighbors within depth');
    // Depth 1 should not reach e3 (two hops away).
    $shallow = GraphStore::traversal($e1['id'], 1, 50);
    $shallowNames = array_column($shallow, 'name');
    check(!in_array('Alpha', $shallowNames, true), 'Traversal depth 1 does not reach two hops away');

    // ---------- Bounds / not found ----------
    expectException(function () {
        GraphStore::neighbors(999999, 'out', 10);
    }, 'Entity not found');

    // ---------- Non-existent collection ----------
    expectException(function () {
        VectorStore::topK(999999, [0.1, 0.2, 0.3], 3);
    }, 'Collection not found');

    expectException(function () use ($owner) {
        VectorStore::createCollection('', 3, 'precomputed', $owner);
    }, 'Collection needs a name');

    expectException(function () {
        GraphStore::createEntity('', 'Name', []);
    }, 'Entity needs a namespace');

    expectException(function () use ($e1) {
        GraphStore::createEdge($e1['id'], 999999, 'related', []);
    }, 'Entity not found');

    echo "ALL PASS\n";
} finally { $fixture->close(); }

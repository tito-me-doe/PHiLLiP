<?php
require __DIR__ . '/helpers.php';

use Agent\HuggingFaceCatalog;

$calls = [];

function fixtureCatalog($body): HuggingFaceCatalog
{
    global $calls;
    $calls = [];
    $transport = function ($url) use ($body) {
        global $calls;
        $calls['url'] = $url;
        return $body;
    };
    return new HuggingFaceCatalog($transport);
}

function capturedUrl(): ?string
{
    global $calls;
    return $calls['url'] ?? null;
}

// 1. Default fetch uses trendingScore, direction -1, limit 100.
$models = fixtureCatalog('[]')->fetch();
check(is_array($models), 'Default fetch returns a list');
$url = capturedUrl();
check($url !== null && strpos($url, 'sort=trendingScore') !== false, 'Default URL sorts by trendingScore');
check($url !== null && strpos($url, 'direction=-1') !== false, 'Default URL direction is -1');
check($url !== null && strpos($url, 'limit=100') !== false, 'Default URL limit is 100');

// 2. Sort and limit overrides are honored.
$models = fixtureCatalog('[]')->fetch('likes', 20);
$url = capturedUrl();
check(strpos($url, 'sort=likes') !== false, 'Sort override uses likes');
check(strpos($url, 'limit=20') !== false, 'Limit override uses 20');

// 3. Requested fields are requested via expand[].
$url = capturedUrl();
check(strpos($url, 'expand') !== false, 'Request expands fields via expand[]');

// 4. Invalid sort key is rejected with a clear error.
expectException(function () { fixtureCatalog('[]')->fetch('bogus'); }, 'Unsupported Hugging Face Hub sort key');

// 5. Out-of-range limit is rejected with a clear error.
expectException(function () { fixtureCatalog('[]')->fetch('trendingScore', 0); }, 'Limit must be between');

// 6. Normalization maps documented fields.
$fixture = json_encode([[
    'id' => 'meta-llama/Llama-3.1-8B',
    'author' => 'meta-llama',
    'pipeline_tag' => 'text-generation',
    'downloads' => 123456,
    'likes' => 789,
    'trendingScore' => 0.85,
    'lastModified' => '2024-01-01T00:00:00Z',
    'tags' => ['llama', 'text-generation'],
    'gated' => false,
    'private' => false,
    'usedStorage' => 9999,
]]);
$models = fixtureCatalog($fixture)->fetch();
check(count($models) === 1, 'One model returned');
$m = $models[0];
check($m['id'] === 'meta-llama/Llama-3.1-8B', 'id normalized');
check($m['author'] === 'meta-llama', 'author normalized');
check($m['pipeline_tag'] === 'text-generation', 'pipeline_tag normalized');
check($m['downloads'] === 123456, 'downloads normalized');
check($m['likes'] === 789, 'likes normalized');
check($m['trendingScore'] === 0.85, 'trendingScore normalized');
check($m['lastModified'] === '2024-01-01T00:00:00Z', 'lastModified normalized');
check($m['tags'] === ['llama', 'text-generation'], 'tags normalized');
check($m['gated'] === false, 'gated normalized');
check($m['private'] === false, 'private normalized');
check($m['usedStorage'] === 9999, 'usedStorage normalized');

// 7. Tolerant of absent fields.
$fixture = json_encode([[]]);
$models = fixtureCatalog($fixture)->fetch();
check(count($models) === 1, 'Empty model entry tolerated');
$m = $models[0];
check($m['id'] === null, 'Absent id is null');
check($m['author'] === null, 'Absent author is null');
check($m['tags'] === [], 'Absent tags is empty list');

// 8. Invalid JSON is rejected.
expectException(function () { fixtureCatalog('not json')->fetch(); }, 'invalid JSON');

// 9. Empty response is rejected.
expectException(function () { fixtureCatalog('')->fetch(); }, 'empty response');

// 10. Non-array JSON (a scalar) is rejected.
expectException(function () { fixtureCatalog('"not a list"')->fetch(); }, 'not a model list');

// 11. Public HTTP smoke test, only when explicitly enabled (avoids network by default).
if (getenv('PHILL_HF_LIVE') !== false && getenv('PHILL_HF_LIVE') !== '') {
    $live = (new HuggingFaceCatalog())->fetch('trendingScore', 3);
    check(is_array($live) && count($live) === 3, 'Live HF Hub returns requested page');
    foreach ($live as $m) {
        check(is_string($m['id']) && $m['id'] !== '', 'Live entry has an id');
    }
}

echo "PASS: huggingface_catalog all checks\n";

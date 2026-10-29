<?php
use Agent\Views as V;
$bench = new Agent\BenchmarkFrontend($state['user']);
$report = $bench->report(isset($_GET['test']) ? $_GET['test'] : null);
$detail = !empty($_GET['benchmark']) ? $bench->details((int) $_GET['benchmark']) : null;
$cells = [];
foreach ($report['latest'] as $row) {
    $cells[$row['profileid']][$row['testid']] = $row;
}
$nativeSpeed = $report['selected'] && in_array($report['selected']['model_type'], ['embed', 'rerank'], true);
?>
<section class="benchmarks" data-benchmark-root data-test="<?= V::h(
    $report['selected'] ? $report['selected']['id'] : ''
) ?>" data-run="<?= $detail ? (int) $detail['id'] : '' ?>">
<div class="benchmark-content">

<section class="card"><form method="get" class="row"><input type="hidden" name="view" value="benchmarks"><label>Chart test<select name="test">
<?php foreach ($report['tests'] as $test): ?><option value="<?= $test['id'] ?>" <?= $report['selected'] &&
$test['id'] == $report['selected']['id']
    ? 'selected'
    : '' ?>><?= V::h($test['name']) ?> · revision <?= $test[
     'revision'
 ] ?></option><?php endforeach; ?></select></label><button>Show</button></form>
<?php
$maximum = 1;
foreach ($report['points'] as $point) {
    $maximum = max($maximum, (float) $point['speed']);
}
$x = function ($p) use ($maximum) {
    return 55 + (600 * (float) $p['speed']) / $maximum;
};
$y = function ($p) {
    return 190 - 160 * (float) $p['score'];
};
?>
<svg class="benchmark-chart" viewBox="0 0 720 240" role="img" aria-label="Speed versus score, with Pareto frontier"><path d="M55 25V190H670" fill="none" stroke="currentColor"/><text x="10" y="30">100%</text><text x="25" y="190">0%</text><text x="55" y="215">0</text><text x="610" y="215"><?= number_format(
    $maximum,
    1
) ?></text><text x="210" y="235"><?= $nativeSpeed ? 'Requests / second' : 'Output tokens / second' ?> (end-to-end)</text>
<polyline points="<?php foreach ($report['frontier'] as $point) {
    echo $x($point) . ',' . $y($point) . ' ';
} ?>" fill="none" stroke="#5c9d77" stroke-width="3"/>
<?php foreach ($report['points'] as $point): ?><a href="<?= V::url(
    '?view=benchmarks&test=' . $point['testid'] . '&benchmark=' . $point['id']
) ?>"><circle cx="<?= $x($point) ?>" cy="<?= $y($point) ?>" r="6" fill="#ba7352"><title><?= V::h(
    $point['profile_name'] .
        ' · ' .
        round($point['score'] * 100, 1) .
        '% · ' .
        round($point['speed'], 1) .
        ($nativeSpeed ? ' requests/s' : ' tokens/s')
) ?></title></circle></a><?php endforeach; ?></svg>
<p class="hint">Latest completed run per profile, for the selected test's current revision. Unknown token usage is omitted, never estimated. Engine counts may include reasoning tokens. Timing includes request/persistence overhead. These are observed results, not a guarantee.</p>
<?php if (!$report['points']): ?><p>No completed runs with measured speed for this test revision yet.</p><?php endif; ?>
</section>
<section class="card"><div class="row"><strong><?= $report['paused'] ? 'Queue paused' : 'Queue enabled' ?></strong><?php foreach (
    ['play' => '▶ Play / resume', 'pause' => 'Ⅱ Pause', 'stop' => '■ Stop']
    as $action => $label
): ?><form method="post"><?= V::csrf(
    $state,
    'benchmark-' . $action
) ?><button><?= $label ?></button></form><?php endforeach; ?></div><p class="hint">Play queues every profile/test if no unfinished work exists. This includes configured hosted aliases. Pause finishes the active question; Stop cancels it and queued work. A worker must be running.</p>
<div class="table-wrap"><table><thead><tr><th>Profile / model</th><th>Metadata</th><?php foreach (
    $report['tests']
    as $test
): ?><th><?= V::h($test['name']) ?></th><?php endforeach; ?><th></th></tr></thead><tbody>
<?php foreach ($report['profiles'] as $profile):
    $meta = $profile['metadata']; ?><tr><td><strong><?= V::h($profile['name']) ?></strong><small><?= V::h(
    $profile['model']
) ?></small><small><?= V::h($profile['engine_status']) ?></small><?php if (
    preg_match('~^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$~D', $profile['model'])
): ?><a href="<?= V::h(
    'https://huggingface.co/' . $profile['model']
) ?>" rel="noopener noreferrer" target="_blank">Model card ↗</a><?php endif; ?></td>
<td><?php
$parameters = isset($meta['details']['parameter_size'])
    ? $meta['details']['parameter_size']
    : (isset($meta['parameter_count'])
        ? $meta['parameter_count']
        : null);
if (!is_scalar($parameters)) {
    $parameters = null;
}
$size = isset($meta['size']) && is_numeric($meta['size']) && $meta['size'] >= 0 ? (float) $meta['size'] : null;
?>
<small>Parameters: <?= V::h($parameters === null ? 'Unknown' : $parameters) ?></small><small>Disk: <?= $size === null
    ? 'Unknown'
    : V::h(number_format($size / 1073741824, 2) . ' GiB') ?></small><details><summary>Raw metadata</summary><pre><?= V::h(
    json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
) ?></pre></details></td>
<?php foreach ($report['tests'] as $test):
    $cell = isset($cells[$profile['id']][$test['id']]) ? $cells[$profile['id']][$test['id']] : null; ?><td><?php if (
    $cell
): ?><a href="<?= V::url('?view=benchmarks&benchmark=' . $cell['id']) ?>"><?= $cell['score'] === null
    ? '—'
    : number_format($cell['score'] * 100, 1) . '%' ?></a><small><?= V::h($cell['status']) ?> · <?= $cell['recorded'] ?>/<?= $cell[
    'questions'
] ?></small><small>test r<?= V::h($cell['test_revision']) ?></small><?php else: ?>Not run<?php endif; ?></td><?php
endforeach; ?>
<td><?php foreach (['play' => '▶', 'pause' => 'Ⅱ'] as $action => $label): ?><form method="post"><?= V::csrf(
    $state,
    'benchmark-' . $action
) ?><input type="hidden" name="profileid" value="<?= $profile['id'] ?>"><button aria-label="<?= $action ?> <?= V::h(
     $profile['name']
 ) ?>"><?= $label ?></button></form><?php endforeach; ?></td></tr><?php
endforeach; ?></tbody></table></div></section>
<?php if ($detail): ?><section class="card" id="benchmark-details"><h2>Run #<?= $detail['id'] ?> · <?= V::h(
     $detail['snapshot']['test']['name']
 ) ?></h2><p><?= V::h($detail['status']) ?> · <?= V::h($detail['snapshot']['profile']['name']) ?> · test revision <?= $detail['snapshot'][
     'test'
 ]['revision'] ?></p><?php if ($detail['error']): ?><p class="error-panel"><?= V::h($detail['error']) ?></p><?php endif; ?>
<?php foreach ($detail['results'] as $result): ?><details><summary>Question <?= $result['position'] ?>: <?= V::h(
    $result['question']['name']
) ?> · <?= V::h($result['status']) ?> · <?= number_format($result['score'] * 100, 1) ?>%</summary><p>Thread #<?= $result[
    'threadid'
] ?> · execution #<?= $result['runid'] ?> · <?= number_format($result['elapsed_seconds'], 3) ?> s</p><h3>Input</h3><pre><?= V::h(
     json_encode($result['question']['input'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
 ) ?></pre><h3>Expected / rubric</h3><pre><?= V::h(
    json_encode($result['question']['scoring_criteria'], JSON_PRETTY_PRINT)
) ?></pre><h3>Answer</h3><pre><?= V::h($result['answer']) ?></pre><?php if ($result['error']): ?><p class="error-panel"><?= V::h(
    $result['error']
) ?></p><?php endif; ?><details><summary>Raw saved conversation and response</summary><pre><?= V::h(
    json_encode(
        ['messages' => $result['threadid'] ? (new Agent\Thread($result['threadid']))->messages() : [], 'response' => $result['raw']],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    )
) ?></pre></details></details><?php endforeach; ?></section><?php endif; ?>
<section class="card"><h2>Recent runs</h2><div class="table-wrap"><table class="registry"><thead><tr><th>Run</th><th>Profile / test</th><th>Status</th><th>Questions</th><th></th></tr></thead><tbody>
<?php foreach ($report['recent'] as $row): ?><tr><td>#<?= $row['id'] ?></td><td><?= V::h($row['profile_name'].' / '.$row['test_name']) ?></td>
<td><?= V::h($row['status']) ?></td><td><?= $row['recorded'] ?>/<?= $row['questions'] ?></td>
<td><a class="button" href="<?= V::url('?view=benchmarks&benchmark='.$row['id']) ?>">Results</a></td></tr><?php endforeach; ?>
</tbody></table></div></section>
</div></section>

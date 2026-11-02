<?php
use Agent\Views as V;
$bench = new Agent\BenchmarkFrontend($state['user']);
if ($kind === 'benchmark-profile') {
    $report = $bench->report($_GET['test'] ?? null);
    $profile = null;
    foreach ($report['profiles'] as $candidate) if ((string)$candidate['id']===(string)$id) $profile=$candidate;
    if (!$profile) throw new InvalidArgumentException('Profile not found');
    ?>
    <h2><?= V::h($profile['name']) ?></h2>
    <div class="table-wrap"><table class="registry"><thead><tr><th>Test</th><th>Score</th><th>Wall time</th><th></th></tr></thead><tbody>
    <?php foreach ($profile['benchmark']['runs'] as $run): ?><tr><td><?= V::h($run['test_name']) ?><small>Revision <?= V::h($run['test_revision']) ?></small></td><td><?= number_format($run['score']*100,1) ?>%</td><td><?= $run['elapsed_seconds']===null ? '—' : V::h(number_format($run['elapsed_seconds'],3).' s') ?></td><td><a href="<?= Agent\Pages::link('benchmarks',['benchmark'=>$run['id']]) ?>" data-dialog="benchmark-run" data-id="<?= (int)$run['id'] ?>">Results</a></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php return;
}
if ($kind === 'benchmark-metadata') {
    $profile = $bench->metadata($id);
    echo '<h2>'.V::h($profile['name']).'</h2><p class="hint">'.V::h($profile['model']).'</p><pre class="inspection-json">'.V::pretty($profile['metadata']).'</pre>';
    return;
}
$detail = $bench->details($id);
?>
<section class="card" id="benchmark-details"><h2>Run #<?= $detail['id'] ?> · <?= V::h(
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
) ?></pre></details></details><?php endforeach; ?></section>

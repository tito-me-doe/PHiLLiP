<?php
use Agent\Views as V;
use Agent\Pages;
$bench = new Agent\BenchmarkFrontend($state['user']);
$report = $bench->report($_GET['test'] ?? null);
$selected = $report['selected'];
$testId = $selected ? $selected['id'] : '';
$scope = $selected ? $selected['name'] : 'All tests';
$maximum = max(array_merge([0.001],array_column($report['points'],'elapsed_seconds')));
$x = function($point)use($maximum) { return 55+600*$point['elapsed_seconds']/$maximum; };
$y = function($point) { return 190-160*$point['score']; };
?>
<section class="benchmarks" data-benchmark-root data-test="<?= V::h($testId) ?>">
<div class="page-content benchmark-content">
<nav class="tabs benchmark-tabs" aria-label="Benchmark tests">
<a class="<?= !$selected ? 'active' : '' ?>" href="<?= Pages::link('benchmarks') ?>" data-benchmark-tab="all" <?= !$selected ? 'aria-current="page"' : '' ?>>All</a>
<?php foreach ($report['tests'] as $test): $active = $selected && $test['id']===$selected['id']; ?>
<a class="<?= $active ? 'active' : '' ?>" href="<?= Pages::link('benchmarks',['test'=>$test['id']]) ?>" data-benchmark-tab="<?= (int)$test['id'] ?>" <?= $active ? 'aria-current="page"' : '' ?>><?= V::h($test['name']) ?></a>
<?php endforeach; ?>
<a class="new-test-tab" href="<?= Pages::link('benchmarks',['new_test'=>1]) ?>" data-benchmark-tab="new" data-dialog="benchmark-test">+ New Test</a>
</nav>
<?php
// Controls follow the work: idle offers Play, running or queued work offers Pause, paused work offers Play (resume) and Stop.
$controlsFor = function (array $statuses, $paused = false) {
    if (!empty($statuses['running']) || !empty($statuses['queued'])) return ['pause'];
    if (!empty($statuses['paused'])) return ['play', 'stop'];
    return ['play'];
};
$icons = ['play' => 'Play', 'pause' => 'Pause', 'stop' => 'Stop'];
?>
<section class="benchmark-process" aria-label="Benchmark controls">
<div class="row">
<?php foreach ($controlsFor($report['counts'], !empty($report['paused'])) as $action): $label = $icons[$action]; ?>
<form method="post" action="<?= Pages::link('benchmarks') ?>"><?= V::csrf($state,'benchmark-'.$action) ?><input type="hidden" name="testid" value="<?= V::h($testId) ?>"><button class="icon" data-benchmark-control="<?= $action ?>" title="<?= V::h($label.' · '.$scope) ?>" aria-label="<?= V::h($label.' · '.$scope) ?>"><?= V::icon($action) ?></button></form>
<?php endforeach; ?>
<span class="muted" data-benchmark-status><?php
if ($report['counts']['running']) echo 'Running · '.$report['counts']['running'];
elseif ($report['counts']['queued']) echo 'Queued · '.$report['counts']['queued'];
elseif ($report['counts']['paused'] || $report['paused']) echo 'Paused';
else echo 'Idle';
?></span>
</div>
</section>
<section class="card">
<svg class="benchmark-chart" viewBox="0 0 720 240" role="img" aria-label="Wall time versus score, with Pareto frontier">
<path d="M55 25V190H670" fill="none" stroke="currentColor"/><text x="10" y="30">100%</text><text x="25" y="190">0%</text><text x="55" y="215">0 s</text><text x="610" y="215"><?= number_format($maximum,2) ?> s</text><text x="190" y="235">Wall time in seconds · shorter is faster</text>
<polyline points="<?php foreach ($report['frontier'] as $point) echo $x($point).','.$y($point).' '; ?>" fill="none" stroke="#5c9d77" stroke-width="3"/>
<?php foreach ($report['points'] as $point): ?>
<a href="<?= Pages::link('benchmarks',['profile'=>$point['profileid'],'test'=>$testId]) ?>" data-dialog="benchmark-profile" data-id="<?= (int)$point['profileid'] ?>" data-test="<?= V::h($testId) ?>"><circle cx="<?= $x($point) ?>" cy="<?= $y($point) ?>" r="6" fill="#ba7352"><title><?= V::h($point['profile_name'].' · '.round($point['score']*100,1).'% · '.round($point['elapsed_seconds'],3).' s') ?></title></circle></a>
<?php endforeach; ?></svg>
<?php if (!$report['points']): ?><p class="empty">No completed runs yet.</p><?php endif; ?>
</section>
<div class="row benchmark-edit-actions">
<?php if ($selected): ?>
<a class="button" href="<?= Pages::link('benchmarks',['test'=>$testId,'edit_test'=>$testId]) ?>" data-dialog="benchmark-test" data-test="<?= V::h($testId) ?>">Edit Test</a>
<a class="button" href="<?= Pages::link('benchmarks',['test'=>$testId,'questions'=>1]) ?>" data-dialog="benchmark-questions" data-test="<?= V::h($testId) ?>">Edit Questions</a>
<?php else: ?><button disabled title="Select a test first">Edit Test</button><button disabled title="Select a test first">Edit Questions</button><?php endif; ?>
</div>
<?php if (!empty($_GET['new_test']) || isset($_GET['edit_test'])): ?><section class="card" data-benchmark-editor><?= V::render('benchmark-form',['state'=>$state,'kind'=>'benchmark-test']) ?></section><?php endif; ?>
<?php if (!empty($_GET['questions'])): ?><section class="card" data-benchmark-editor><?= V::render(isset($_GET['question']) ? 'benchmark-form' : 'benchmark-questions',['state'=>$state,'kind'=>'benchmark-question']) ?></section><?php endif; ?>
<?php foreach (['benchmark'=>'benchmark-run','metadata'=>'benchmark-metadata','profile'=>'benchmark-profile'] as $key=>$kind): if (!empty($_GET[$key])): ?><section class="card"><?= V::render('benchmark-inspect',['state'=>$state,'kind'=>$kind,'id'=>(int)$_GET[$key]]) ?></section><?php endif; endforeach; ?>
<section class="card"><h2>Profiles</h2>
<div class="table-wrap"><table class="registry" data-benchmark-profiles><thead><tr><th>Profile</th><th>Metadata</th><th>Score</th><th>Wall time</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($report['profiles'] as $profile): $result=$profile['benchmark']; $meta=$profile['metadata']; ?>
<tr data-profile-id="<?= (int)$profile['id'] ?>"><td><strong><?= V::h($profile['name']) ?></strong><small><?= V::h($profile['model']) ?></small><small><?= V::h($profile['engine_status']) ?></small>
<?php if (preg_match('~^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$~D',$profile['model'])): ?><a href="<?= V::h('https://huggingface.co/'.$profile['model']) ?>" rel="noopener noreferrer" target="_blank">Model card ↗</a><?php endif; ?></td>
<td><?php $parameters=$meta['details']['parameter_size'] ?? $meta['parameter_count'] ?? null; $size=isset($meta['size']) && is_numeric($meta['size']) && $meta['size']>=0 ? (float)$meta['size'] : null; ?>
<small>Parameters: <?= V::h(is_scalar($parameters) ? $parameters : 'Unknown') ?></small><small>Disk: <?= $size===null ? 'Unknown' : V::h(number_format($size/1073741824,2).' GiB') ?></small>
<a href="<?= Pages::link('benchmarks',['metadata'=>$profile['id'],'test'=>$testId]) ?>" data-dialog="benchmark-metadata" data-id="<?= (int)$profile['id'] ?>">Raw metadata</a></td>
<td><?php if ($result['score']!==null): $kind=$selected ? 'benchmark-run' : 'benchmark-profile'; $id=$selected ? $result['runs'][0]['id'] : $profile['id']; ?>
<a href="<?= Pages::link('benchmarks',[$selected ? 'benchmark' : 'profile'=>$id,'test'=>$testId]) ?>" data-dialog="<?= $kind ?>" data-id="<?= (int)$id ?>" data-benchmark-result="<?= (int)$id ?>" data-test="<?= V::h($testId) ?>"><?= number_format($result['score']*100,1) ?>%</a><small><?= number_format($result['correct'],2) ?> / <?= $result['seen'] ?> questions</small>
<?php else: ?>Not run<?php endif; ?></td>
<td><?= $result['elapsed_seconds']===null ? '—' : V::h(number_format($result['elapsed_seconds'],3).' s') ?></td>
<td><?php if ($profile['activity']): foreach ($profile['activity'] as $status=>$count): ?><small><?= V::h(ucfirst($status).' · '.$count) ?></small><?php endforeach; elseif ($profile['last_run']): $last=$profile['last_run']; ?><a href="<?= Pages::link('benchmarks',['benchmark'=>$last['id'],'test'=>$testId]) ?>" data-dialog="benchmark-run" data-id="<?= (int)$last['id'] ?>"><?= V::h(ucfirst($last['status'])) ?></a><?php if ($last['error']): ?><small class="error"><?= V::h(mb_substr($last['error'],0,160)) ?></small><?php endif; else: ?>Idle<?php endif; ?></td>
<td class="actions"><?php foreach ($controlsFor($profile['activity'] ?? []) as $action): ?>
<form method="post" action="<?= Pages::link('benchmarks') ?>"><?= V::csrf($state,'benchmark-'.$action) ?><input type="hidden" name="profileid" value="<?= (int)$profile['id'] ?>"><input type="hidden" name="testid" value="<?= V::h($testId) ?>"><button class="icon" data-benchmark-profile-action="<?= $action ?>" data-benchmark-profile-id="<?= (int)$profile['id'] ?>" title="<?= V::h($icons[$action].' '.$profile['name'].' · '.$scope) ?>" aria-label="<?= V::h($icons[$action].' '.$profile['name']) ?>"><?= V::icon($action) ?></button></form>
<?php endforeach; ?></td></tr>
<?php endforeach; ?>
<?php if (!$report['profiles']): ?><tr><td colspan="6" class="empty">No saved profiles. Add a profile in System Settings first.</td></tr><?php endif; ?>
</tbody></table></div></section>
</div></section>

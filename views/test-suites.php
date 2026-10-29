<?php
use Agent\Views as V;
use Agent\Pages;
Pages::authorize($state['user']['id'], 'test-suites');
$test = isset($_GET['edit_test']) && $_GET['edit_test'] !== 'new' ? Agent\Benchmarks::test($_GET['edit_test']) : null;
?>
<?php if (isset($_GET['edit_test']) && $_GET['edit_test'] === 'new'): ?>
<section class="card"><?= V::render('benchmark-form',['state'=>$state,'kind'=>'benchmark-test']) ?></section>
<?php elseif (isset($_GET['question'])): ?>
<section class="card"><?= V::render('benchmark-form',['state'=>$state,'kind'=>'benchmark-question']) ?></section>
<?php elseif ($test): ?>
<section class="card benchmark-editor" data-benchmark-editor>
<div class="row"><h2><?= V::h($test['name']) ?></h2><a class="button" href="<?= Pages::link('test-suites') ?>">All test suites</a>
<?php if (Pages::allowed($state['user']['id'],'benchmarks')): ?><a class="button" href="<?= Pages::link('benchmarks') ?>">Back to report</a><?php endif; ?></div>
<p><?= V::h($test['description']) ?></p><p class="hint"><?= V::h($test['model_type']) ?> · revision <?= $test['revision'] ?>. Edits create a revision; queued/completed runs retain their original definitions. No PHP or shell code is evaluated.</p>
<div class="table-wrap"><table class="registry"><thead><tr><th>Definition</th><th>Model type</th><th></th></tr></thead><tbody>
<tr><td><?= V::h($test['name']) ?></td><td><?= V::h($test['model_type']) ?></td><td><?= V::iconAction('edit','Edit test',['data-dialog'=>'benchmark-test','data-test'=>$test['id'],'href'=>Pages::link('test-suites',['edit_test'=>'new','test'=>$test['id']])]) ?></td></tr>
</tbody></table></div>
<div class="table-wrap"><table class="registry" data-question-list><thead><tr><th>Question</th><th>Input</th><th>Scoring</th><th>Criteria</th><th></th></tr></thead><tbody>
<?php foreach ($test['questions'] as $question): ?>
<tr><td><?= V::h($question['position'].'. '.$question['name']) ?></td><td><pre><?= V::pretty($question['input']) ?></pre></td>
<td><?= V::h($question['scoring_type']) ?></td><td><pre><?= V::h(json_encode($question['scoring_criteria'],JSON_PRETTY_PRINT)) ?></pre></td>
<td><?= V::iconAction('edit','Edit question '.$question['name'],['data-dialog'=>'benchmark-question','data-test'=>$test['id'],'data-question'=>$question['id'],'href'=>Pages::link('test-suites',['edit_test'=>$test['id'],'question'=>$question['id']])]) ?>
<?= V::iconAction('trash','Remove question '.$question['name'],['data-dialog'=>'benchmark-question','data-test'=>$test['id'],'data-question'=>$question['id'],'data-remove'=>'1','href'=>Pages::link('test-suites',['edit_test'=>$test['id'],'question'=>$question['id'],'remove'=>1])]) ?></td></tr>
<?php endforeach; ?>
</tbody><tfoot><tr><td colspan="5"><?= V::iconAction('plus','New question',['data-dialog'=>'benchmark-question','data-test'=>$test['id'],'data-question'=>'new','href'=>Pages::link('test-suites',['edit_test'=>$test['id'],'question'=>'new'])]) ?></td></tr></tfoot></table></div>
</section>
<?php else: $tests = Agent\Benchmarks::tests(); ?>
<section class="card" data-test-library><h2>Test library</h2>
<p class="hint">Reusable, versioned collections of questions. Run them and inspect results on Benchmarks.</p>
<div class="table-wrap"><table class="registry"><thead><tr><th>Test suite</th><th>Model type</th><th>Questions</th><th>Revision</th><th></th></tr></thead><tbody>
<?php foreach ($tests as $item): ?>
<tr><td><strong><?= V::h($item['name']) ?></strong><small><?= V::h($item['description']) ?></small></td><td><?= V::h($item['model_type']) ?></td>
<td><?= $item['questions'] ?></td><td><?= $item['revision'] ?></td><td><a class="button" href="<?= Pages::link('test-suites',['edit_test'=>$item['id']]) ?>">Questions</a>
<?= V::iconAction('edit','Edit test '.$item['name'],['data-dialog'=>'benchmark-test','data-test'=>$item['id'],'href'=>Pages::link('test-suites',['edit_test'=>'new','test'=>$item['id']])]) ?></td></tr>
<?php endforeach; ?>
</tbody><tfoot><tr><td colspan="5"><?= V::iconAction('plus','New test',['data-dialog'=>'benchmark-test','href'=>Pages::link('test-suites',['edit_test'=>'new'])]) ?></td></tr></tfoot></table></div></section>
<?php endif; ?>

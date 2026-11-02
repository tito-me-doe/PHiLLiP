<?php
use Agent\Views as V;
use Agent\Pages;
Pages::authorize($state['user']['id'],'benchmarks');
$test = Agent\Benchmarks::test($_GET['test'] ?? '');
?>
<section data-benchmark-editor data-test="<?= (int)$test['id'] ?>">
<h2><?= V::h($test['name']) ?></h2>
<p class="hint">Revision <?= (int)$test['revision'] ?> · <?= V::h($test['model_type']) ?></p>
<div class="table-wrap"><table class="registry" data-question-list><thead><tr><th>Question</th><th>Input</th><th>Scoring</th><th>Criteria</th><th></th></tr></thead><tbody>
<?php foreach ($test['questions'] as $question): ?>
<tr><td><?= V::h($question['position'].'. '.$question['name']) ?></td><td><pre><?= V::pretty($question['input']) ?></pre></td>
<td><?= V::h($question['scoring_type']) ?></td><td><pre><?= V::h(json_encode($question['scoring_criteria'],JSON_PRETTY_PRINT)) ?></pre></td>
<td><?= V::iconAction('edit','Edit question '.$question['name'],['data-dialog'=>'benchmark-question','data-test'=>$test['id'],'data-question'=>$question['id'],'href'=>Pages::link('benchmarks',['test'=>$test['id'],'questions'=>1,'question'=>$question['id']])]) ?>
<?= V::iconAction('trash','Remove question '.$question['name'],['data-dialog'=>'benchmark-question','data-test'=>$test['id'],'data-question'=>$question['id'],'data-remove'=>'1','href'=>Pages::link('benchmarks',['test'=>$test['id'],'questions'=>1,'question'=>$question['id'],'remove'=>1])]) ?></td></tr>
<?php endforeach; ?>
<?php if (!$test['questions']): ?><tr><td colspan="5" class="empty">Add a question before running this test.</td></tr><?php endif; ?>
</tbody><tfoot><tr><td colspan="5"><?= V::iconAction('plus','New question',['data-dialog'=>'benchmark-question','data-test'=>$test['id'],'data-question'=>'new','href'=>Pages::link('benchmarks',['test'=>$test['id'],'questions'=>1,'question'=>'new'])]) ?></td></tr></tfoot></table></div>
</section>

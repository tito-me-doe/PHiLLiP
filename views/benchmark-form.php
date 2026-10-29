<?php
use Agent\Views as V;
Agent\Pages::authorize($state['user']['id'],'test-suites');
$reference = $_GET['test'] ?? (isset($_GET['edit_test']) && $_GET['edit_test'] !== 'new' ? $_GET['edit_test'] : null);
$test = $reference !== null ? Agent\Benchmarks::test($reference) : null;
$kind = $kind ?? 'benchmark-test';
$remove = Agent\Config::boolean($_GET['remove'] ?? false,'Remove question');
$question = ['id'=>null,'name'=>'','input'=>['prompt'=>''],'scoring_type'=>'matches','scoring_criteria'=>''];
if ($kind === 'benchmark-question') {
    if (!$test) throw new InvalidArgumentException('A test suite is required');
    $questionId = $_GET['question'] ?? 'new';
    if ($questionId !== 'new') {
        $found = false;
        foreach ($test['questions'] as $candidate) if ((string)$candidate['id'] === $questionId) { $question=$candidate; $found=true; }
        if (!$found) throw new InvalidArgumentException('Question not found in this test suite');
    }
}
$action = $kind === 'benchmark-test' ? 'save-test' : ($remove ? 'remove-question' : 'save-question');
?>
<form method="post" class="form-stack" action="<?= Agent\Pages::link('test-suites') ?>">
<?= V::csrf($state,'benchmark-'.$action) ?>
<?php if ($test): ?><input type="hidden" name="testid" value="<?= $test['id'] ?>"><input type="hidden" name="revision" value="<?= $test['revision'] ?>"><?php endif; ?>
<?php if ($kind === 'benchmark-test'): ?>
    <label>Name<input name="name" required maxlength="160" value="<?= V::h($test['name'] ?? '') ?>"></label>
    <?= V::select('model_type','Model type',array_combine(Agent\Benchmarks::MODEL_TYPES,Agent\Benchmarks::MODEL_TYPES),$test['model_type'] ?? 'chat') ?>
    <label>Description<textarea name="description"><?= V::h($test['description'] ?? '') ?></textarea></label>
    <button class="primary">Save test</button>
<?php else: ?>
    <input type="hidden" name="questionid" value="<?= V::h($question['id']) ?>">
    <?php if ($remove): ?>
        <p>Remove <?= V::h($question['name']) ?> from this test suite?</p>
        <label class="check-label"><input name="confirm" type="checkbox" value="true" required>Confirm removal</label><button class="primary">Remove question</button>
    <?php else: ?>
        <label>Question name<input name="name" required maxlength="160" value="<?= V::h($question['name']) ?>"></label>
        <label>Input (JSON object)<textarea name="input" spellcheck="false" required><?= V::h(json_encode($question['input'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) ?></textarea></label>
        <?= V::select('scoring_type','Scoring',array_combine(Agent\Benchmarks::SCORERS,Agent\Benchmarks::SCORERS),$question['scoring_type']) ?>
        <label>Expected answer / rubric (JSON; quote text answers)<textarea name="criteria_json" spellcheck="false" required><?= V::h(json_encode($question['scoring_criteria'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) ?></textarea></label>
        <p class="hint">Chat uses prompt; classify adds labels; extract adds contract; vision adds image. Embed accepts texts or query/documents; rerank uses query/documents.</p>
        <button class="primary">Save question</button>
    <?php endif; ?>
<?php endif; ?>
</form>

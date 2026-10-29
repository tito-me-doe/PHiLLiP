<?php
use Agent\Views as V;
$frontend = new Agent\ProjectFrontend($state['user']);
$projectId = isset($projectId) ? $projectId : null;
$project = $projectId ? $frontend->project($projectId) : null;
$snapshot = $project ? $project->snapshot() : null;
$row = $snapshot ? $snapshot['project'] : null;
$editable = $row && in_array($row['status'], ['planning', 'paused', 'blocked'], true);
$ready = $row && !$snapshot['issues'] && $row['preflight_status'] === 'ready' && $row['preflight_revision'] === $row['revision'];
$hidden = function ($action, $todoId = null) use ($state, $project) {
    echo V::csrf($state, 'project-' . $action);
    if ($project) {
        echo '<input type="hidden" name="project_id" value="' . V::h($project->id) . '">';
    }
    if ($todoId !== null) {
        echo '<input type="hidden" name="todo_id" value="' . V::h($todoId) . '">';
    }
};
$button = function ($action, $label, $disabled = false, $todoId = null) use ($hidden, $project) {
    $apiAction = $action === 'retry' && $todoId !== null ? 'retry/' . $todoId : $action;
    echo '<form method="post" action="' .
        V::h(V::url('?view=projects&project=' . $project->id)) .
        '" data-project-form="' .
        V::h($action . ':' . $todoId) .
        '" data-project-api="/api/projects/' .
        V::h($project->id) .
        '/' .
        V::h($apiAction) .
        '">';
    $hidden($action, $todoId);
    echo '<button type="submit"' .
        ($disabled ? ' disabled' : '') .
        '>' .
        V::h($label) .
        '</button><span class="form-error" role="alert"></span></form>';
};
$editor = function ($todo = null, $parentId = null) use ($state, $hidden, $project, $snapshot) {
    $isNew = $todo === null;
    $todoId = $isNew ? null : $todo['id'];
    $values = $isNew
        ? [
            'title' => '',
            'goal' => '',
            'requirements' => '',
            'context' => '',
            'gates' => [],
            'persona_id' => null,
            'max_attempts' => 3,
            'parent_id' => $parentId
        ]
        : $todo;
    $formKey = $isNew ? 'add:' . ($parentId ?: 'root') : 'todo:' . $todoId;
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['action']) &&
        $_POST['action'] === 'project-' . ($isNew ? 'add' : 'update') &&
        (string) (isset($_POST['todo_id']) ? $_POST['todo_id'] : '') === (string) $todoId &&
        (string) (isset($_POST['parent_id']) ? $_POST['parent_id'] : '') === (string) $values['parent_id']
    ) {
        foreach (array_keys($values) as $field) {
            if (isset($_POST[$field]) && (is_string($_POST[$field]) || is_array($_POST[$field]))) {
                $values[$field] = $_POST[$field];
            }
        }
    }
    ?>
    <form class="form-stack project-editor" method="post" action="<?= V::h(
        V::url('?view=projects&project=' . $project->id)
    ) ?>" data-project-form="<?= V::h($formKey) ?>" data-project-api="/api/projects/<?= V::h($project->id) ?>/todos<?= $isNew
    ? ''
    : '/' . V::h($todoId) ?>">
        <?php $hidden($isNew ? 'add' : 'update', $todoId); ?>
        <input type="hidden" name="parent_id" value="<?= V::h($values['parent_id']) ?>">
        <label>Title<input name="title" value="<?= V::h(
            $values['title']
        ) ?>" maxlength="160" placeholder="A concrete piece of work"></label>
        <label>Goal<textarea name="goal" rows="2" placeholder="What should this accomplish?"><?= V::h($values['goal']) ?></textarea></label>
        <div class="grid">
            <label>Requirements<textarea name="requirements" rows="3" placeholder="Required behavior and constraints, one per line"><?= V::h(
                $values['requirements']
            ) ?></textarea></label>
            <label>Context<textarea name="context" rows="3" placeholder="Background, inputs, and relevant references"><?= V::h(
                $values['context']
            ) ?></textarea></label>
        </div>
        <label>Acceptance gates <small>One explicit check per line. Every gate must pass before this ToDo is done.</small><textarea name="gates" rows="3" placeholder="How should completion be verified?"><?= V::h(
            is_array($values['gates']) ? implode("\n", $values['gates']) : $values['gates']
        ) ?></textarea></label>
        <div class="grid">
            <label>Persona<select name="persona_id"><option value="">Project default</option><?php foreach (
                $state['personas']
                as $persona
            ): ?><option value="<?= V::h($persona['id']) ?>" <?= (string) $values['persona_id'] === (string) $persona['id']
    ? 'selected'
    : '' ?>><?= V::h($persona['name']) ?></option><?php endforeach; ?></select></label>
            <label>Maximum attempts<input type="number" name="max_attempts" min="1" max="10" value="<?= V::h(
                $values['max_attempts']
            ) ?>"></label>
        </div>
        <div class="row"><button type="submit" class="primary"><?= $isNew
            ? 'Add ToDo'
            : 'Save ToDo' ?></button><small class="muted">Drafts may be incomplete while planning.</small></div>
        <div class="form-error" role="alert"></div>
    </form>
    <?php
};
?>
<?php if (!empty($decisionEditor) || isset($_GET['decision'])):
    $decision = null;
    foreach ($snapshot ? $snapshot['decisions'] : [] as $candidate) {
        if ((string)$candidate['id'] === (string)($_GET['decision'] ?? '') && $candidate['target_todo_id'] === null && $candidate['answer'] === null) $decision=$candidate;
    }
    if (!$decision) throw new InvalidArgumentException('Open project decision not found');
    if (empty($decisionEditor)) echo '<section class="page-content"><div class="card"><a href="'.V::h(V::url('?view=projects&project='.$project->id)).'">Back to project</a>';
?>
    <form method="post" class="form-stack" action="<?= V::url('?view=projects&project='.$project->id) ?>" data-project-form="answer:<?= $decision['id'] ?>" data-project-api="/api/projects/<?= $project->id ?>/decisions/<?= $decision['id'] ?>/answer">
        <?php $hidden('answer'); ?><input type="hidden" name="decision_id" value="<?= $decision['id'] ?>">
        <p><?= V::h($decision['question']) ?></p>
        <label>Your decision<textarea name="answer" required rows="4"></textarea></label>
        <button type="submit" class="primary">Answer decision</button><div class="form-error" role="alert"></div>
    </form>
<?php if (empty($decisionEditor)) echo '</div></section>'; return; endif; ?>
<?php if (!empty($todoEditor) || isset($_GET['todo'])):
    if (!$project || !$editable) throw new RuntimeException('Pause the project before editing ToDos');
    $reference = $_GET['todo'] ?? 'new';
    $findTodo = function($id) use ($snapshot) {
        foreach ($snapshot['todos'] as $candidate) if ((string)$candidate['id'] === (string)$id) return $candidate;
        throw new InvalidArgumentException('ToDo not found in this project');
    };
    $todo = $reference === 'new' ? null : $findTodo($reference);
    $parent = $_GET['parent'] ?? null;
    if ($parent !== null) $findTodo($parent);
    if (empty($todoEditor)) echo '<section class="page-content"><div class="card"><a href="'.V::h(V::url('?view=projects&project='.$project->id)).'">Back to project</a>';
    if (!empty($_GET['remove'])):
        if (!$todo) throw new InvalidArgumentException('A ToDo is required');
?>
    <form method="post" class="form-stack" action="<?= V::url('?view=projects&project='.$project->id) ?>" data-project-form="remove:<?= $todo['id'] ?>" data-project-api="/api/projects/<?= $project->id ?>/todos/<?= $todo['id'] ?>">
        <?php $hidden('remove',$todo['id']); ?><input type="hidden" name="delete" value="1">
        <p>Remove <?= V::h($todo['title']) ?>?</p>
        <label class="check-label"><input name="descendants" type="checkbox" value="1">Include child ToDos</label>
        <button type="submit" class="primary">Delete ToDo</button><div class="form-error" role="alert"></div>
    </form>
<?php else: $editor($todo,$parent); endif;
    if (empty($todoEditor)) echo '</div></section>';
    return;
endif; ?>
<section class="projects-page" data-project-root data-project-id="<?= $project ? V::h($project->id) : '' ?>">
<header class="topbar"><div class="row"><button type="button" class="icon mobile-menu" data-action="menu" aria-label="Open navigation"><?= V::icon(
    'menu'
) ?></button><span class="title"><?= V::h(
    $row ? $row['title'] : 'New project'
) ?></span></div><button class="icon" data-action="theme" aria-label="Toggle light or dark mode"><?= V::icon('theme') ?></button></header>
<div class="projects-content">
<?php if (!$project): ?>
    <div class="project-heading"><div class="eyebrow">Plan, check, execute</div><h1>A clear goal. A workable plan.</h1><p class="muted">Start a project, flesh out its ToDos together, then explicitly start the work. Existing projects live alongside conversations in Recent work.</p></div>
    <section class="card" id="create-project"><h2>Create a project</h2>
    <form class="form-stack" method="post" action="<?= V::url(
        '?view=projects'
    ) ?>" data-project-form="create" data-project-api="/api/projects">
        <?php $hidden('create'); ?><input type="hidden" name="creation_key" value="<?= V::h(
    isset($_POST['creation_key']) && is_string($_POST['creation_key']) ? $_POST['creation_key'] : bin2hex(random_bytes(16))
) ?>">
        <label>Title<input name="title" required maxlength="160" placeholder="What are we working toward?" value="<?= V::h(
            isset($_POST['title']) && is_string($_POST['title']) ? $_POST['title'] : ''
        ) ?>"></label>
        <div class="grid"><label>Model profile<select name="profile" required><?php foreach (
            $state['profiles']
            as $profile
        ): ?><option value="<?= V::h($profile['name']) ?>"><?= V::h($profile['name']) ?></option><?php endforeach; ?></select></label>
        <?= V::visibilityField($state) ?></div>
        <?= V::teamField($state) ?>
        <fieldset><legend>Tools</legend><input type="hidden" name="tools[]" value=""><?php foreach (
            $state['tools']
            as $tool
        ): ?><label class="check-label"><input type="checkbox" name="tools[]" value="<?= V::h($tool) ?>"><?= V::h(
    substr($tool, strrpos($tool, '\\') + 1)
) ?></label><?php endforeach; ?></fieldset>
        <button type="submit" class="primary" <?= $state['profiles'] ? '' : 'disabled' ?>>Create project</button><?php if (
    !$state['profiles']
): ?><p class="muted">Ask an administrator to enable a model profile first.</p><?php endif; ?><div class="form-error" role="alert"></div>
    </form></section>
<?php else: ?>
    <section class="card project-summary"><div class="card-head"><h1><?= V::h($row['title']) ?></h1><span class="badge <?= $row[
    'status'
] === 'blocked'
    ? 'error'
    : '' ?>"><?= V::h($row['status']) ?></span></div>
        <p class="muted"><?= V::h($snapshot['progress']['done']) ?> of <?= V::h(
     $snapshot['progress']['total']
 ) ?> ToDos done · waiting <?= V::h(
     isset($snapshot['progress']['waiting']) ? (int) $snapshot['progress']['waiting'] : 0
 ) ?> · Plan revision <?= V::h($row['revision']) ?> · <?= V::h($row['visibility']) ?></p>
        <progress value="<?= V::h($snapshot['progress']['done']) ?>" max="<?= max(
    1,
    $snapshot['progress']['total']
) ?>" aria-label="Completed ToDos"></progress>
        <div class="project-controls"><a class="button" href="<?= V::url('?thread=' . $row['thread_id']) ?>">Open planning chat</a><?php
$button('preflight', 'Check readiness', !$editable || in_array($row['preflight_status'], ['queued', 'checking'], true));
$button('start', $row['status'] === 'paused' ? 'Resume project' : 'Start Project', !$editable || !$ready);
if ($row['status'] === 'running') {
    $button('pause', 'Pause project');
}
?></div>
        <p class="hint">Readiness checks never start work. Start Project requires your explicit action for the current plan.</p>
        <div class="project-readiness" role="status"><strong>Readiness: <?= V::h($row['preflight_status']) ?></strong>
        <?php if ($snapshot['issues']): ?><table class="registry"><thead><tr><th>ToDo</th><th>Required before start</th></tr></thead><tbody>
        <?php foreach ($snapshot['issues'] as $issue): ?><tr><td><?php if (!empty($issue['todo_id'])): ?><a href="#todo-<?= V::h($issue['todo_id']) ?>">ToDo <?= V::h($issue['todo_id']) ?></a><?php else: ?>Project<?php endif; ?></td><td><?= V::h($issue['message']) ?></td></tr><?php endforeach; ?>
        </tbody></table><?php endif; ?>
        <?php if (
            !empty($row['preflight_result'])
        ): ?><details data-project-panel="preflight"><summary>Readiness findings</summary><pre><?= V::h(
    json_encode($row['preflight_result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
) ?></pre></details><?php endif; ?>
        </div>
    </section>
    <?php $rootDecisions = array_values(
        array_filter($snapshot['decisions'], function ($decision) {
            return $decision['target_todo_id'] === null && $decision['answer'] === null;
        })
    ); ?>
    <?php if ($rootDecisions): ?>
    <section class="card project-decisions"><h2>Decisions awaiting your answer</h2>
    <div class="table-wrap"><table class="registry"><thead><tr><th>Question</th><th>From ToDo</th><th>Evidence</th><th></th></tr></thead><tbody>
    <?php foreach ($rootDecisions as $decision): ?>
        <tr><td><?= V::h($decision['question']) ?></td><td><a href="#todo-<?= $decision['todo_id'] ?>">ToDo <?= $decision['todo_id'] ?></a><small>Revision <?= $decision['todo_revision'] ?></small></td>
            <td><pre><?= V::h(json_encode($decision['evidence'],JSON_PRETTY_PRINT)) ?></pre></td>
            <td><a class="button" data-dialog="project-decision" data-project="<?= $project->id ?>" data-decision="<?= $decision['id'] ?>" href="<?= Agent\Pages::link('projects',['project'=>$project->id,'decision'=>$decision['id']]) ?>">Answer</a></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></section>
    <?php endif; ?>
    <section class="project-plan card"><h2>ToDos</h2><p class="hint">Children complete before their parent. Every task must pass its acceptance gates.</p>
    <div class="table-wrap"><table class="registry todo-table"><thead><tr><th>ToDo</th><th>Status</th><th>Specification and result</th><th></th></tr></thead><tbody>
    <?php
    $renderTodos = function($parentId = null,$depth = 0) use (&$renderTodos,$snapshot,$editable,$project,$button) {
        if ($depth > 32) return;
        foreach ($snapshot['todos'] as $todo) {
            if ((string)$todo['parent_id'] !== (string)$parentId) continue;
            $query = ['project'=>$project->id,'todo'=>$todo['id']];
    ?>
        <tr class="project-todo" id="todo-<?= $todo['id'] ?>">
            <td><strong><?= str_repeat('↳ ',min($depth,8)).V::h($todo['title'] ?: 'Untitled draft') ?></strong>
                <small><?= V::h($todo['goal']) ?></small><small>Attempts <?= (int)$todo['attempts'] ?> / <?= (int)$todo['max_attempts'] ?></small>
                <?php if ($todo['thread_id']): ?><a href="<?= V::url('?thread='.$todo['thread_id']) ?>">Work conversation</a><?php endif; ?></td>
            <td><span class="badge <?= $todo['status'] === 'blocked' ? 'error' : 'neutral' ?>"><?= V::h($todo['status']) ?></span></td>
            <td><details data-project-panel="spec:<?= $todo['id'] ?>"><summary>Requirements, context, and acceptance gates</summary>
                <h4>Requirements</h4><p class="project-text"><?= V::h($todo['requirements']) ?></p>
                <h4>Context</h4><p class="project-text"><?= V::h($todo['context']) ?></p>
                <h4>Acceptance gates</h4><table><tbody><?php foreach ($todo['gates'] as $gate): ?><tr><td><?= V::h($gate) ?></td></tr><?php endforeach; ?></tbody></table>
            </details>
            <?php if (!empty($todo['result']) || !empty($todo['evidence']) || !empty($todo['review'])): ?>
            <details data-project-panel="result:<?= $todo['id'] ?>" open><summary>Result and gate evidence</summary>
                <?php if (!empty($todo['result'])): ?><div class="message-body"><?= V::markdown($todo['result']) ?></div><?php endif; ?>
                <?php foreach (['evidence'=>'Gate evidence','review'=>'Review'] as $key=>$label): if (empty($todo[$key])) continue; ?>
                    <h4><?= $label ?></h4><pre><?= V::h(json_encode($todo[$key],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) ?></pre>
                <?php endforeach; ?>
            </details><?php endif; ?></td>
            <td><?php if ($editable): ?>
                <?= V::iconAction('edit','Edit ToDo',['data-dialog'=>'project-todo','data-project'=>$project->id,'data-todo'=>$todo['id'],'href'=>Agent\Pages::link('projects',$query)]) ?>
                <?= V::iconAction('plus','Add child ToDo',['data-dialog'=>'project-todo','data-project'=>$project->id,'data-todo'=>'new','data-parent'=>$todo['id'],'href'=>Agent\Pages::link('projects',['project'=>$project->id,'todo'=>'new','parent'=>$todo['id']])]) ?>
                <?= V::iconAction('trash','Delete ToDo',['data-dialog'=>'project-todo','data-project'=>$project->id,'data-todo'=>$todo['id'],'data-remove'=>'1','href'=>Agent\Pages::link('projects',$query+['remove'=>1])]) ?>
                <?php if ($todo['status'] === 'blocked') $button('retry','Retry ToDo',false,$todo['id']); ?>
            <?php endif; ?></td>
        </tr>
    <?php
            $renderTodos($todo['id'],$depth+1);
        }
    };
    $renderTodos();
    if (!$snapshot['todos']): ?><tr><td colspan="4" class="empty">Add your first ToDo, or use the planning chat to develop the plan.</td></tr><?php endif; ?>
    </tbody><?php if ($editable): ?><tfoot><tr><td colspan="4"><?= V::iconAction('plus','New ToDo',['data-dialog'=>'project-todo','data-project'=>$project->id,'data-todo'=>'new','href'=>Agent\Pages::link('projects',['project'=>$project->id,'todo'=>'new'])]) ?></td></tr></tfoot><?php endif; ?></table></div>
    <?php if ($row['status'] === 'running'): ?><p class="hint">Pause the project to change the plan.</p><?php endif; ?>
    </section>
    <?php if ((string) $row['owner_id'] === (string) $state['user']['id']): ?>
    <details class="card project-owner-settings" data-project-panel="owner-settings"><summary>Project settings and sharing</summary>
        <p class="hint">Settings and sharing changes require a fresh readiness check.</p>
        <form class="form-stack" method="post" action="<?= V::url(
            '?view=projects&project=' . $project->id
        ) ?>" data-project-form="settings" data-project-api="/api/projects/<?= V::h($project->id) ?>/settings">
            <?php $hidden('settings'); ?>
            <label>Model profile<select name="profile" <?= $editable ? '' : 'disabled' ?>><?php foreach (
    $state['profiles']
    as $profile
): ?><option value="<?= V::h($profile['name']) ?>" <?= isset($row['settings']['profile']) &&
$row['settings']['profile'] === $profile['name']
    ? 'selected'
    : '' ?>><?= V::h($profile['name']) ?></option><?php endforeach; ?></select></label>
            <fieldset <?= $editable ? '' : 'disabled' ?>><legend>Tools</legend><input type="hidden" name="tools[]" value=""><?php foreach (
    $state['tools']
    as $tool
): ?><label class="check-label"><input type="checkbox" name="tools[]" value="<?= V::h($tool) ?>" <?= in_array(
    $tool,
    isset($row['settings']['tools']) ? $row['settings']['tools'] : [],
    true
)
    ? 'checked'
    : '' ?>><?= V::h(substr($tool, strrpos($tool, '\\') + 1)) ?></label><?php endforeach; ?></fieldset>
            <button type="submit" <?= $editable && $state['profiles']
                ? ''
                : 'disabled' ?>>Save model and tools</button><div class="form-error" role="alert"></div>
        </form>
        <form class="form-stack" method="post" action="<?= V::url(
            '?view=projects&project=' . $project->id
        ) ?>" data-project-form="share" data-project-api="/api/projects/<?= V::h($project->id) ?>/share">
            <?php $hidden('share'); ?>
            <?= V::visibilityField($state, $row['visibility'], !$editable) ?>
            <?= V::teamField($state, $row['team_id'], !$editable) ?>
            <button type="submit" <?= $editable ? '' : 'disabled' ?>>Save sharing</button><div class="form-error" role="alert"></div>
        </form>
        <form method="post" action="<?= V::url(
            '?view=projects&project=' . $project->id
        ) ?>" data-project-form="delete" data-project-api="/api/projects/<?= V::h($project->id) ?>/delete"><?php $hidden(
    'delete'
); ?><p class="hint">Deleting removes this project, its plan, and its work conversations.</p><button type="submit" class="project-remove">Delete project and history</button><div class="form-error" role="alert"></div></form>
    </details>
    <?php endif; ?>
    <section class="card project-activity"><h2>Recent activity</h2><div class="table-wrap"><table class="registry"><thead><tr><th>Time</th><th>Event</th><th>ToDo</th></tr></thead><tbody>
    <?php foreach (array_reverse(array_slice($snapshot['events'],-20)) as $event): ?>
        <tr><td><time><?= V::h($event['created_at']) ?></time></td><td><?= V::h(str_replace(['.','_'],' ',$event['type'])) ?></td>
        <td><?php if ($event['todo_id']): ?><a href="#todo-<?= $event['todo_id'] ?>">ToDo <?= $event['todo_id'] ?></a><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>
</div></section>

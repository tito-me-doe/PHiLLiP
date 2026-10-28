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
    if ($project) echo '<input type="hidden" name="project_id" value="' . V::h($project->id) . '">';
    if ($todoId !== null) echo '<input type="hidden" name="todo_id" value="' . V::h($todoId) . '">';
};
$button = function ($action, $label, $disabled = false, $todoId = null) use ($hidden, $project) {
    $apiAction = $action === 'retry' && $todoId !== null ? 'retry/' . $todoId : $action;
    echo '<form method="post" action="' . V::h(V::url('?view=projects&project=' . $project->id)) . '" data-project-form="' . V::h($action . ':' . $todoId) . '" data-project-api="/api/projects/' . V::h($project->id) . '/' . V::h($apiAction) . '">';
    $hidden($action, $todoId);
    echo '<button type="submit"' . ($disabled ? ' disabled' : '') . '>' . V::h($label) . '</button><span class="form-error" role="alert"></span></form>';
};
$editor = function ($todo = null, $parentId = null) use ($state, $hidden, $project, $snapshot) {
    $isNew = $todo === null;
    $todoId = $isNew ? null : $todo['id'];
    $values = $isNew ? ['title' => '', 'goal' => '', 'requirements' => '', 'context' => '', 'gates' => [], 'persona_id' => null, 'max_attempts' => 3, 'parent_id' => $parentId] : $todo;
    $formKey = $isNew ? 'add:' . ($parentId ?: 'root') : 'todo:' . $todoId;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'project-' . ($isNew ? 'add' : 'update') && (string)(isset($_POST['todo_id']) ? $_POST['todo_id'] : '') === (string)$todoId && (string)(isset($_POST['parent_id']) ? $_POST['parent_id'] : '') === (string)$values['parent_id']) {
        foreach (array_keys($values) as $field) if (isset($_POST[$field]) && (is_string($_POST[$field]) || is_array($_POST[$field]))) $values[$field] = $_POST[$field];
    }
    ?>
    <form class="form-stack project-editor" method="post" action="<?=V::h(V::url('?view=projects&project=' . $project->id))?>" data-project-form="<?=V::h($formKey)?>" data-project-api="/api/projects/<?=V::h($project->id)?>/todos<?=$isNew ? '' : '/' . V::h($todoId)?>">
        <?php $hidden($isNew ? 'add' : 'update', $todoId); ?>
        <input type="hidden" name="parent_id" value="<?=V::h($values['parent_id'])?>">
        <label>Title<input name="title" value="<?=V::h($values['title'])?>" maxlength="160" placeholder="A concrete piece of work"></label>
        <label>Goal<textarea name="goal" rows="2" placeholder="What should this accomplish?"><?=V::h($values['goal'])?></textarea></label>
        <div class="grid">
            <label>Requirements<textarea name="requirements" rows="3" placeholder="Required behavior and constraints, one per line"><?=V::h($values['requirements'])?></textarea></label>
            <label>Context<textarea name="context" rows="3" placeholder="Background, inputs, and relevant references"><?=V::h($values['context'])?></textarea></label>
        </div>
        <label>Acceptance gates <small>One explicit check per line. Every gate must pass before this ToDo is done.</small><textarea name="gates" rows="3" placeholder="How should completion be verified?"><?=V::h(is_array($values['gates']) ? implode("\n", $values['gates']) : $values['gates'])?></textarea></label>
        <div class="grid">
            <label>Persona<select name="persona_id"><option value="">Project default</option><?php foreach ($state['personas'] as $persona): ?><option value="<?=V::h($persona['id'])?>" <?=(string)$values['persona_id'] === (string)$persona['id'] ? 'selected' : ''?>><?=V::h($persona['name'])?></option><?php endforeach; ?></select></label>
            <label>Maximum attempts<input type="number" name="max_attempts" min="1" max="10" value="<?=V::h($values['max_attempts'])?>"></label>
        </div>
        <div class="row"><button type="submit" class="primary"><?=$isNew ? 'Add ToDo' : 'Save ToDo'?></button><small class="muted">Drafts may be incomplete while planning.</small></div>
        <div class="form-error" role="alert"></div>
    </form>
    <?php
};
?>
<section class="projects-page" data-project-root data-project-id="<?=$project ? V::h($project->id) : ''?>">
<header class="topbar"><div class="row"><button type="button" class="icon mobile-menu" data-action="menu" aria-label="Open navigation"><?=V::icon('menu')?></button><a href="<?=V::url('?view=projects')?>">Projects</a><?php if ($row): ?><span aria-hidden="true">/</span><span class="title"><?=V::h($row['title'])?></span><?php endif; ?></div><a href="<?=V::url('?view=projects')?>#create-project">New project</a></header>
<div class="projects-content">
<?php if (!$project): ?>
    <div class="project-heading"><div class="eyebrow">Plan, check, execute</div><h1>Projects</h1><p class="muted">Build a clear plan together, check readiness, then explicitly start the work.</p></div>
    <div class="project-list">
    <?php $projects = $frontend->projects(); foreach ($projects as $item): ?>
        <a class="card project-list-item" href="<?=V::url('?view=projects&project=' . $item['id'])?>"><div><h2><?=V::h($item['title'])?></h2><small class="muted"><?=V::h($item['visibility'])?> · Readiness <?=V::h($item['preflight_status'])?></small></div><span class="badge"><?=V::h($item['status'])?></span></a>
    <?php endforeach; if (!$projects): ?><p class="empty">No projects yet. Start with a title and flesh out the plan in the planning chat.</p><?php endif; ?>
    </div>
    <section class="card" id="create-project"><h2>Create a project</h2>
    <form class="form-stack" method="post" action="<?=V::url('?view=projects')?>" data-project-form="create" data-project-api="/api/projects">
        <?php $hidden('create'); ?><input type="hidden" name="creation_key" value="<?=V::h(isset($_POST['creation_key']) && is_string($_POST['creation_key']) ? $_POST['creation_key'] : bin2hex(random_bytes(16)))?>">
        <label>Title<input name="title" required maxlength="160" placeholder="What are we working toward?" value="<?=V::h(isset($_POST['title']) && is_string($_POST['title']) ? $_POST['title'] : '')?>"></label>
        <div class="grid"><label>Model profile<select name="profile" required><?php foreach ($state['profiles'] as $profile): ?><option value="<?=V::h($profile['name'])?>"><?=V::h($profile['name'])?></option><?php endforeach; ?></select></label>
        <?=V::visibilityField($state)?></div>
        <?=V::teamField($state)?>
        <fieldset><legend>Tools</legend><input type="hidden" name="tools[]" value=""><?php foreach ($state['tools'] as $tool): ?><label class="check-label"><input type="checkbox" name="tools[]" value="<?=V::h($tool)?>"><?=V::h(substr($tool, strrpos($tool, '\\') + 1))?></label><?php endforeach; ?></fieldset>
        <button type="submit" class="primary" <?=$state['profiles'] ? '' : 'disabled'?>>Create project</button><?php if (!$state['profiles']): ?><p class="muted">Ask an administrator to enable a model profile first.</p><?php endif; ?><div class="form-error" role="alert"></div>
    </form></section>
<?php else: ?>
    <section class="card project-summary"><div class="card-head"><h1><?=V::h($row['title'])?></h1><span class="badge <?=$row['status'] === 'blocked' ? 'error' : ''?>"><?=V::h($row['status'])?></span></div>
        <p class="muted"><?=V::h($snapshot['progress']['done'])?> of <?=V::h($snapshot['progress']['total'])?> ToDos done · waiting <?=V::h(isset($snapshot['progress']['waiting']) ? (int)$snapshot['progress']['waiting'] : 0)?> · Plan revision <?=V::h($row['revision'])?> · <?=V::h($row['visibility'])?></p>
        <progress value="<?=V::h($snapshot['progress']['done'])?>" max="<?=max(1, $snapshot['progress']['total'])?>" aria-label="Completed ToDos"></progress>
        <div class="project-controls"><a class="button" href="<?=V::url('?thread=' . $row['thread_id'])?>">Open planning chat</a><?php $button('preflight', 'Check readiness', !$editable || in_array($row['preflight_status'], ['queued', 'checking'], true)); $button('start', $row['status'] === 'paused' ? 'Resume project' : 'Start Project', !$editable || !$ready); if ($row['status'] === 'running') $button('pause', 'Pause project'); ?></div>
        <p class="hint">Readiness checks never start work. Start Project requires your explicit action for the current plan.</p>
        <div class="project-readiness" role="status"><strong>Readiness: <?=V::h($row['preflight_status'])?></strong>
        <?php if ($snapshot['issues']): ?><ul><?php foreach ($snapshot['issues'] as $issue): ?><li><?php if (!empty($issue['todo_id'])): ?><a href="#todo-<?=V::h($issue['todo_id'])?>">ToDo <?=V::h($issue['todo_id'])?></a>: <?php endif; ?><?=V::h($issue['message'])?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if (!empty($row['preflight_result'])): ?><details data-project-panel="preflight"><summary>Readiness findings</summary><pre><?=V::h(json_encode($row['preflight_result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))?></pre></details><?php endif; ?>
        </div>
    </section>
    <?php $rootDecisions = array_values(array_filter($snapshot['decisions'], function ($decision) { return $decision['target_todo_id'] === null && $decision['answer'] === null; })); ?>
    <?php if ($rootDecisions): ?>
    <section class="card project-decisions"><div class="card-head"><h2>Root decisions awaiting your answer</h2></div>
    <?php foreach ($rootDecisions as $d): ?>
    <form method="post" action="<?=V::url('?view=projects&project=' . $project->id)?>" data-project-form="answer:<?=V::h($d['id'])?>" data-project-api="/api/projects/<?=V::h($project->id)?>/decisions/<?=V::h($d['id'])?>/answer">
        <?php echo V::csrf($state, 'project-answer'); ?><input type="hidden" name="project_id" value="<?=V::h($project->id)?>"><input type="hidden" name="decision_id" value="<?=V::h($d['id'])?>">
        <p><a href="#todo-<?=V::h($d['todo_id'])?>">ToDo <?=V::h($d['todo_id'])?></a> · Revision <?=V::h($d['todo_revision'])?></p>
        <p><?=V::h($d['question'])?></p>
        <?php if ($d['evidence']): ?><details data-project-panel="decision-evidence:<?=V::h($d['id'])?>"><summary>Supporting evidence</summary><pre><?=V::h(json_encode($d['evidence'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))?></pre></details><?php endif; ?>
        <label>Answer<textarea name="answer" rows="2" required><?=V::h(isset($_POST['action'], $_POST['decision_id'], $_POST['answer']) && $_POST['action'] === 'project-answer' && (string)$_POST['decision_id'] === (string)$d['id'] && is_string($_POST['answer']) ? $_POST['answer'] : '')?></textarea></label>
        <button type="submit" class="primary">Answer</button><span class="form-error" role="alert"></span>
    </form>
    <?php endforeach; ?>
    </section>
    <?php endif; ?>
    <section class="project-plan"><div class="card-head"><h2>ToDos</h2><span class="muted">Children complete before their parent</span></div>
    <?php
    $renderTodos = function ($parentId = null, $depth = 0) use (&$renderTodos, $snapshot, $editable, $editor, $button, $hidden, $project) {
        if ($depth > 32) return;
        foreach ($snapshot['todos'] as $todo) {
            if ((string)$todo['parent_id'] !== (string)$parentId) continue;
            ?>
            <article class="project-todo card" id="todo-<?=V::h($todo['id'])?>"><div class="card-head"><h3><?=V::h($todo['title'] ?: 'Untitled draft')?></h3><span class="badge <?=$todo['status'] === 'blocked' ? 'error' : 'neutral'?>"><?=V::h($todo['status'])?></span></div>
            <p class="project-text"><?=V::h($todo['goal'])?></p><small class="muted">Attempts <?=V::h($todo['attempts'])?> / <?=V::h($todo['max_attempts'])?><?php if (!empty($todo['thread_id'])): ?> · <a href="<?=V::url('?thread=' . $todo['thread_id'])?>">Work conversation</a><?php endif; ?></small>
            <details data-project-panel="spec:<?=V::h($todo['id'])?>"><summary>Requirements, context, and acceptance gates</summary><h4>Requirements</h4><p class="project-text"><?=V::h($todo['requirements'])?></p><h4>Context</h4><p class="project-text"><?=V::h($todo['context'])?></p><h4>Acceptance gates</h4><ol><?php foreach ($todo['gates'] as $gate): ?><li><?=V::h($gate)?></li><?php endforeach; ?></ol></details>
            <?php if (!empty($todo['result']) || !empty($todo['evidence']) || !empty($todo['review'])): ?><details data-project-panel="result:<?=V::h($todo['id'])?>" open><summary>Result and gate evidence</summary><?php if (!empty($todo['result'])): ?><div class="message-body"><?=V::markdown($todo['result'])?></div><?php endif; ?><?php foreach (['evidence' => 'Gate evidence', 'review' => 'Review'] as $key => $label): if (empty($todo[$key])) continue; ?><h4><?=$label?></h4><pre><?=V::h(json_encode($todo[$key], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))?></pre><?php endforeach; ?></details><?php endif; ?>
            <?php if ($editable): ?>
            <details data-project-panel="edit:<?=V::h($todo['id'])?>"><summary>Edit ToDo</summary><?php $editor($todo); ?></details>
            <details data-project-panel="add:<?=V::h($todo['id'])?>"><summary>Add child ToDo</summary><?php $editor(null, $todo['id']); ?></details>
            <div class="project-controls"><?php if ($todo['status'] === 'blocked') $button('retry', 'Retry ToDo', false, $todo['id']); ?>
            <form method="post" action="<?=V::url('?view=projects&project=' . $project->id)?>" data-project-form="remove:<?=V::h($todo['id'])?>" data-project-api="/api/projects/<?=V::h($project->id)?>/todos/<?=V::h($todo['id'])?>"><?php $hidden('remove', $todo['id']); ?><input type="hidden" name="delete" value="1"><label class="check-label"><input type="checkbox" name="descendants" value="1">Include child ToDos</label><button type="submit" class="project-remove">Delete ToDo</button><div class="form-error" role="alert"></div></form></div>
            <?php endif; ?>
            <div class="project-children"><?php $renderTodos($todo['id'], $depth + 1); ?></div></article>
            <?php
        }
    };
    $renderTodos();
    if (!$snapshot['todos']): ?><p class="empty">Add your first ToDo, or use the planning chat to develop the plan.</p><?php endif; ?>
    <?php if ($editable): ?><details class="card" data-project-panel="add:root" <?=!$snapshot['todos'] ? 'open' : ''?>><summary>Add ToDo</summary><?php $editor(); ?></details><?php elseif ($row['status'] === 'running'): ?><p class="hint">Pause the project to change the plan.</p><?php endif; ?>
    </section>
    <?php if ((string)$row['owner_id'] === (string)$state['user']['id']): ?>
    <details class="card project-owner-settings" data-project-panel="owner-settings"><summary>Project settings and sharing</summary>
        <p class="hint">Settings and sharing changes require a fresh readiness check.</p>
        <form class="form-stack" method="post" action="<?=V::url('?view=projects&project=' . $project->id)?>" data-project-form="settings" data-project-api="/api/projects/<?=V::h($project->id)?>/settings">
            <?php $hidden('settings'); ?>
            <label>Model profile<select name="profile" <?=$editable ? '' : 'disabled'?>><?php foreach ($state['profiles'] as $profile): ?><option value="<?=V::h($profile['name'])?>" <?=isset($row['settings']['profile']) && $row['settings']['profile'] === $profile['name'] ? 'selected' : ''?>><?=V::h($profile['name'])?></option><?php endforeach; ?></select></label>
            <fieldset <?=$editable ? '' : 'disabled'?>><legend>Tools</legend><input type="hidden" name="tools[]" value=""><?php foreach ($state['tools'] as $tool): ?><label class="check-label"><input type="checkbox" name="tools[]" value="<?=V::h($tool)?>" <?=in_array($tool, isset($row['settings']['tools']) ? $row['settings']['tools'] : [], true) ? 'checked' : ''?>><?=V::h(substr($tool, strrpos($tool, '\\') + 1))?></label><?php endforeach; ?></fieldset>
            <button type="submit" <?=$editable && $state['profiles'] ? '' : 'disabled'?>>Save model and tools</button><div class="form-error" role="alert"></div>
        </form>
        <form class="form-stack" method="post" action="<?=V::url('?view=projects&project=' . $project->id)?>" data-project-form="share" data-project-api="/api/projects/<?=V::h($project->id)?>/share">
            <?php $hidden('share'); ?>
            <?=V::visibilityField($state, $row['visibility'], !$editable)?>
            <?=V::teamField($state, $row['team_id'], !$editable)?>
            <button type="submit" <?=$editable ? '' : 'disabled'?>>Save sharing</button><div class="form-error" role="alert"></div>
        </form>
        <form method="post" action="<?=V::url('?view=projects&project=' . $project->id)?>" data-project-form="delete" data-project-api="/api/projects/<?=V::h($project->id)?>/delete"><?php $hidden('delete'); ?><p class="hint">Deleting removes this project, its plan, and its work conversations.</p><button type="submit" class="project-remove">Delete project and history</button><div class="form-error" role="alert"></div></form>
    </details>
    <?php endif; ?>
    <section class="card project-activity"><h2>Recent activity</h2><ol><?php foreach (array_reverse(array_slice($snapshot['events'], -20)) as $event): ?><li><time><?=V::h($event['created_at'])?></time> <span><?=V::h(str_replace(['.', '_'], ' ', $event['type']))?></span><?php if (!empty($event['todo_id'])): ?> · <a href="#todo-<?=V::h($event['todo_id'])?>">ToDo <?=V::h($event['todo_id'])?></a><?php endif; ?></li><?php endforeach; ?></ol></section>
<?php endif; ?>
</div></section>

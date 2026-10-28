<?php
use Agent\Views as V;
$user = $state['user'];
$current = !empty($_GET['thread']) ? V::thread($state, (int)$_GET['thread']) : null;
$threads = (new Agent\Frontend($user))->threads();
$tab = isset($_GET['tab']) && in_array($_GET['tab'], ['private', 'team', 'organization'], true) ? $_GET['tab'] : ($current ? $current['thread']['visibility'] : 'private');
$settingsView = isset($_GET['view']) && $_GET['view'] === 'settings';
$projectsView = (isset($_GET['view']) && $_GET['view'] === 'projects') || !empty($_GET['project']);
?>
<div class="shell"><aside class="sidebar"><div class="brand-row"><div class="brand">PHiLL<i>i</i>P<span style="color:#96917e;font-weight:400;font-size:1rem">_</span></div><span class="badge neutral">local</span></div>
<a class="new-chat button" href="<?=V::url('?new=1')?>"><?=V::icon('plus')?> New conversation</a>
<a class="project-nav button <?=$projectsView ? 'active' : ''?>" href="<?=V::url('?view=projects')?>">Projects <span aria-hidden="true">↗</span></a>
<nav class="tabs" aria-label="Conversation visibility">
<?php foreach (['private' => 'Mine', 'team' => 'Teams', 'organization' => 'Organization'] as $key => $label): ?>
<?php if ($key === 'team' && !$state['teams']) continue; ?>
<a class="<?=$tab === $key ? 'active' : ''?>" href="<?=V::url('?tab=' . $key)?>"><?=$label?></a>
<?php endforeach; ?></nav>
<div class="thread-list" id="thread-list"><?=V::render('threads', compact('state', 'threads', 'current', 'tab'))?></div>
<div class="sidebar-bottom"><div class="user-circle"><?=V::h(mb_substr($user['display_name'], 0, 1))?></div><div class="name"><?=V::h($user['display_name'])?><small class="muted" style="display:block"><?=$user['is_admin'] ? 'Administrator' : 'Your workspace'?></small></div><a class="icon button" href="<?=V::url('?view=settings')?>" aria-label="Settings"><?=V::icon('gear')?></a></div></aside>
<main class="workspace" id="workspace"><?=V::render($projectsView ? 'projects' : ($settingsView ? 'settings' : 'chat'), ['state' => $state, 'current' => $current, 'projectId' => !empty($_GET['project']) ? $_GET['project'] : null, 'tab' => $settingsView ? (isset($_GET['tab']) ? $_GET['tab'] : 'appearance') : $tab])?></main></div>
<aside class="chat-companion" aria-label="PHiLLiP companion"><span class="companion-bubble" aria-hidden="true">Right here with you.</span><button class="companion-character" data-action="avatar-react" aria-label="Say hello to PHiLLiP"><?=V::avatar($state)?></button><span class="companion-status" aria-hidden="true">PHiLLiP</span></aside>

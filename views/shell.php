<?php
use Agent\Views as V;
use Agent\Pages;
$user = $state['user'];
$pageId = Pages::current($_GET);
if (!$_GET && !Pages::allowed($user['id'], $pageId)) {
    $permitted = Pages::permitted($user['id']);
    if ($permitted) $pageId = $permitted[0]['id'];
}
Pages::authorize($user['id'], $pageId);
$page = Pages::get($pageId);
$concept = $page['handler'];
$current = !empty($_GET['thread']) ? V::thread($state, (int)$_GET['thread']) : null;
$threads = (new Agent\Frontend($user))->threads();
$tab = isset($_GET['tab']) && in_array($_GET['tab'], ['private','team','organization'], true)
    ? $_GET['tab'] : ($current ? $current['thread']['visibility'] : 'private');
// Chat and Project are one click away in the sidebar at all times, so the pages menu lists only the other pages.
$navigation = array_values(array_filter($state['navigation'], function ($item) { return !in_array($item['id'], ['conversations', 'projects'], true); }));
// The menu stays open while moving between settings pages; conversations and projects start with it closed.
$settingsPage = !in_array($concept, ['conversations', 'projects'], true);
$historyPage = Pages::allowed($user['id'], 'conversations') ? 'conversations' : 'projects';
?>
<div class="shell">
    <aside class="sidebar" id="primary-navigation" aria-label="Primary navigation">
        <header class="sidebar-header">
            <button class="icon mobile-nav-close" aria-label="Close navigation"><?= V::icon('close') ?></button>
            <a class="brand" href="<?= V::url() ?>">PHiLL<i>i</i>P<span class="brand-cursor">_</span></a>
            <details class="route-menu"<?= $settingsPage && $navigation ? ' open' : '' ?>>
                <summary class="icon menu-toggle" aria-label="Toggle pages menu" title="Pages"><?= V::icon('menu') ?></summary>
                <nav class="page-navigation" aria-label="Pages">
                    <table class="menu-table"><tbody>
                    <?php foreach ($navigation as $item): ?>
                        <tr><td><a class="page-link <?= $pageId === $item['id'] ? 'active' : '' ?>" href="<?= Pages::link($item['id']) ?>"
                            <?= $pageId === $item['id'] ? 'aria-current="page"' : '' ?> data-page="<?= V::h($item['id']) ?>"><?= V::h($item['title']) ?></a></td></tr>
                    <?php endforeach; ?>
                    <?php if ($state['auth_mode'] === 'standalone'): ?>
                        <tr><td><button class="page-link" data-action="logout">Sign out</button></td></tr>
                    <?php endif; ?>
                    </tbody></table>
                </nav>
            </details>
        </header>
        <nav class="tabs" aria-label="Conversation visibility">
            <?php foreach (['private'=>'Mine','team'=>'Groups','organization'=>'Organization'] as $key=>$label):
                if ($key === 'team' && !$state['teams']) continue; ?>
                <a class="<?= $tab === $key ? 'active' : '' ?>" href="<?= Pages::link($historyPage, ['tab'=>$key]) ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="create-actions">
            <?php if (Pages::allowed($user['id'], 'conversations')): ?>
                <a class="new-chat button" href="<?= Pages::link('conversations', ['new'=>1]) ?>"><?= V::icon('plus') ?> Chat</a>
            <?php endif; ?>
            <?php if (Pages::allowed($user['id'], 'projects')): ?>
                <a class="new-project button" href="<?= Pages::link('projects') ?>"><?= V::icon('plus') ?> Project</a>
            <?php endif; ?>
        </div>
        <section class="history" aria-label="Conversation and project history">
            <div class="thread-list" id="thread-list"><?= V::render('threads', compact('state','threads','current','tab')) ?></div>
        </section>
        <div class="sidebar-bottom">
            <div class="user-circle"><?= V::h(mb_substr($user['display_name'], 0, 1)) ?></div>
            <div class="name"><?= V::h($user['display_name']) ?><?php if ($user['is_admin']): ?><small class="muted">Administrator</small><?php endif; ?></div>
        </div>
    </aside>
    <div class="sidebar-backdrop" aria-hidden="true"></div>
    <main class="workspace" id="workspace" data-page="<?= V::h($pageId) ?>">
        <?php if (!in_array($concept, ['conversations','projects'], true)) echo V::render('page-header', compact('state','page')); ?>
        <?php if (!in_array($concept, ['conversations','projects','benchmarks'], true)): ?>
            <div class="page-content"><div class="page-inner" id="settings-page" data-settings-page="<?= V::h($concept) ?>">
        <?php endif; ?>
        <?= V::render($current && $concept === 'projects' ? 'chat' : Pages::VIEWS[$concept], [
            'state'=>$state, 'current'=>$current, 'page'=>$concept,
            'projectId'=>!empty($_GET['project']) ? $_GET['project'] : null
        ]) ?>
        <?php if (!in_array($concept, ['conversations','projects','benchmarks'], true)): ?></div></div><?php endif; ?>
    </main>
</div>

<?php use Agent\Views as V; ?>
<header class="topbar page-header">
    <div class="row">
        <button type="button" class="icon mobile-menu" data-action="menu" aria-label="Open navigation"><?= V::icon('menu') ?></button>
        <h1><?= V::h($page['title']) ?></h1>
    </div>
    <div class="top-actions">
        <?php if ($page['handler'] === 'benchmarks' && Agent\Pages::allowed($state['user']['id'], 'test-suites')): ?>
            <a class="button" href="<?= Agent\Pages::link('test-suites') ?>">Edit tests</a>
        <?php endif; ?>
        <?php if (
            $page['handler'] === 'appearance' &&
            $state['auth_mode'] === 'standalone'
        ): ?><button data-action="logout">Sign out</button><?php endif; ?>
        <button class="icon" data-action="theme" aria-label="Toggle light or dark mode"><?= V::icon('theme') ?></button>
    </div>
</header>

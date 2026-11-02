<?php use Agent\Views as V; ?>
<header class="topbar page-header">
    <div class="row">
        <button type="button" class="icon mobile-menu" data-action="menu" aria-label="Open navigation" aria-controls="primary-navigation" aria-expanded="false"><?= V::icon('menu') ?></button>
        <h1><?= V::h($page['title']) ?></h1>
    </div>
    <div class="top-actions">
        <button class="icon" data-action="theme" aria-label="Toggle light or dark mode"><?= V::icon('theme') ?></button>
    </div>
</header>

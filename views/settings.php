<?php
// Compatibility fragment for older clients; each concept has its own view and route.
$page = Agent\Pages::current([
    'view' => 'settings',
    'tab' => isset($tab) ? $tab : 'appearance'
]);
Agent\Pages::authorize($state['user']['id'], $page);
echo Agent\Views::render('page-header', [
    'state' => $state,
    'page' => Agent\Pages::get($page)
]);
echo '<div class="page-content"><div class="page-inner" id="settings-page" data-settings-page="' . Agent\Views::h($page) . '">';
echo Agent\Views::render(Agent\Pages::VIEWS[$page], [
    'state' => $state,
    'page' => $page
]);
echo '</div></div>';

<?php
$assetBase = htmlspecialchars($base, ENT_QUOTES, 'UTF-8');
$state = \Agent\Views::state();
try {
    $pageHtml = \Agent\Views::render('page', ['state' => $state]);
} catch (\Throwable $e) {
    http_response_code(\Agent\Runtime::isExpected($e) ? 403 : 500);
    \Agent\Log::trace('Page failed', $e);
    $message = \Agent\Runtime::publicMessage($e);
    \Agent\Log::writeError($message);
    $pageHtml =
        '<main class="login"><div class="login-card"><h1>Unable to open this page</h1><div class="error-panel">' .
        \Agent\Views::h($message) .
        '</div><a href="' .
        \Agent\Views::h(\Agent\Views::url()) .
        '">Return to your workspace</a></div></main>';
}
header('Cache-Control: no-store');
header(
    "Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'"
);
?><!doctype html>
<html lang="en" data-theme="<?= \Agent\Views::h(
    isset($state['preferences']['theme']) ? $state['preferences']['theme'] : 'light'
) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>PHiLLiP · A little intelligence, a lot of character</title>
<link rel="icon" href="<?= $assetBase ?>/assets/avatars/terminal.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= $assetBase ?>/assets/vendor.css">
<link rel="stylesheet" href="<?= $assetBase ?>/assets/app.css">
<link rel="stylesheet" href="<?= $assetBase ?>/assets/messages.css">
<script defer src="<?= $assetBase ?>/assets/messages.js"></script>
<script defer src="<?= $assetBase ?>/assets/vendor.js"></script><script defer src="<?= $assetBase ?>/assets/app.js"></script>
<script defer src="<?= $assetBase ?>/assets/settings.js"></script><script defer src="<?= $assetBase ?>/assets/setup.js"></script>
<script defer src="<?= $assetBase ?>/assets/administration.js"></script>
<script defer src="<?= $assetBase ?>/assets/attachments.js"></script>
<script defer src="<?= $assetBase ?>/assets/projects.js"></script>
<script defer src="<?= $assetBase ?>/assets/benchmarks.js"></script>
<script defer src="<?= $assetBase ?>/assets/avatar.js"></script>
</head><body data-base="<?= $assetBase ?>">
<?= \Agent\Log::headerHtml() ?>
<?php if (!empty($pageError)): ?><div role="alert" class="error-panel"><?= \Agent\Views::h($pageError) ?></div><?php endif; ?>
<div id="app"><?= $pageHtml ?></div>
<script type="application/json" id="initial-state"><?= \Agent\Views::json($state) ?></script>
<div id="toast" role="status" aria-live="polite"></div>
<dialog id="dialog" aria-labelledby="dialog-title"><div id="dialog-content"></div><h2 id="dialog-title" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);border:0;">Dialog</h2></dialog>
<?= \Agent\Log::footerHtml() ?>
</body></html>

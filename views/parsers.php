<?php
use Agent\Views as V;
Agent\Pages::authorize($state['user']['id'], 'parsers');
try { $parsers = Agent\AttachmentParser::configuration(); }
catch (Exception $e) { $parsers = []; Agent\Log::writeWarning($e->getMessage()); }
?>
<?php if (!empty($editing) || isset($_GET['edit'])): ?>
<form method="post" id="attachment-parsers-form" class="form-stack">
<label>File types → profile names<textarea class="json-editor" name="parsers" rows="6"><?= V::pretty((object)$parsers) ?></textarea></label>
<div class="table-wrap"><table class="registry"><thead><tr><th>Available profile</th></tr></thead><tbody>
<?php foreach ((new Agent\Registry())->profiles() as $profile): ?><tr><td><?= V::h($profile['name']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="form-error"></div><button class="primary">Save parsing profiles</button></form>
<?php else: ?>
<section class="card"><h2>Attachment parsing</h2>
<p class="hint">Native model input comes first, then configured profiles, then local converters. File types can be MIME types, extensions, or wildcards.</p>
<div class="table-wrap"><table class="registry"><thead><tr><th>File type</th><th>Profile</th><th></th></tr></thead><tbody>
<?php foreach ($parsers as $type=>$profile): ?><tr><td><?= V::h($type) ?></td><td><?= V::h($profile) ?></td><td><?= V::iconAction('edit','Edit parsing '.$type,['data-dialog'=>'parsers','href'=>'?edit=1']) ?></td></tr><?php endforeach; ?>
<?php if (!$parsers): ?><tr><td colspan="3">Local conversion defaults</td></tr><?php endif; ?>
</tbody><tfoot><tr><td colspan="3"><?= V::iconAction('plus','New mapping',['data-dialog'=>'parsers','href'=>'?edit=1']) ?></td></tr></tfoot></table></div>
<p class="hint">Local fallbacks: PDF text/page images, English OCR, DOCX/ODT text. PDF image/OCR conversion is limited to the first eight pages.</p>
</section>
<?php endif; ?>

<?php use Agent\Views as V; ?>
<?php foreach ($files as $file): ?>
<span class="chip" data-attachment="<?=V::h($file['id'])?>"><a href="<?=V::h(rtrim(getenv('APP_BASE_PATH') ?: '', '/') . '/api/attachments/' . $file['id'])?>" title="Download attachment"><?=V::h($file['name'])?></a><small> <?=number_format($file['size'] / 1024, 1)?> KB</small><?php if (!empty($pending)): ?><button type="button" class="icon" data-remove-attachment="<?=V::h($file['id'])?>" aria-label="Remove <?=V::h($file['name'])?>"><?=V::icon('close')?></button><?php endif; ?></span>
<?php endforeach; ?>

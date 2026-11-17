<?php

/**
 * Campo de envio de arquivo: rótulo acima, formatos aceitos na ajuda, o arquivo atual (imagem em
 * miniatura ou link, no caso de PDF) e a caixa para removê-lo. O formulário precisa de
 * enctype="multipart/form-data".
 *
 * @var string      $name
 * @var string      $label
 * @var string      $accept
 * @var string|null $current  URL do arquivo atual
 * @var bool        $currentIsImage
 * @var string      $currentAlt
 * @var string|null $error
 * @var string|null $help
 */
$id        = 'campo-' . $name;
$describes = trim(($help !== null ? $id . '-ajuda ' : '') . ($error !== null ? $id . '-erro' : ''));
?>
<div class="mb-space-4">
    <label for="<?= esc($id, 'attr') ?>" class="mb-space-1 block text-body-strong"><?= esc($label) ?></label>
    <?php if ($current !== null) : ?>
        <div class="mb-space-2 flex flex-wrap items-center gap-space-3">
            <span class="text-caption text-ink-muted"><?= esc(lang('Institucional.currentFile')) ?>:</span>
            <?php if ($currentIsImage) : ?>
                <img src="<?= esc($current, 'attr') ?>" alt="<?= esc($currentAlt, 'attr') ?>" class="max-h-16 max-w-40 rounded-sm border border-border object-contain">
            <?php else : ?>
                <a href="<?= esc($current, 'attr') ?>" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center text-body underline"><?= esc($currentAlt) ?></a>
            <?php endif ?>
        </div>
    <?php endif ?>
    <input type="file" id="<?= esc($id, 'attr') ?>" name="<?= esc($name, 'attr') ?>" accept="<?= esc($accept, 'attr') ?>"
           <?= $describes !== '' ? 'aria-describedby="' . esc($describes, 'attr') . '"' : '' ?>
           <?= $error !== null ? 'aria-invalid="true"' : '' ?>
           class="min-h-11 w-full rounded-sm border border-border-strong bg-surface px-space-3 py-space-2 text-body shadow-xs file:mr-space-3 file:rounded-sm file:border-0 file:bg-paper file:px-space-3 file:py-space-1 file:text-body file:text-ink focus:border-brand aria-[invalid=true]:border-signal-critical">
    <?php if ($help !== null) : ?>
        <p id="<?= esc($id, 'attr') ?>-ajuda" class="mt-space-1 text-caption text-ink-muted"><?= esc($help) ?></p>
    <?php endif ?>
    <?php if ($error !== null) : ?>
        <p id="<?= esc($id, 'attr') ?>-erro" class="mt-space-1 text-data text-signal-critical"><?= esc($error) ?></p>
    <?php endif ?>
    <?php if ($current !== null) : ?>
        <div class="mt-space-1 flex min-h-11 items-center gap-space-3">
            <input type="checkbox" id="<?= esc($id, 'attr') ?>-remover" name="remover_<?= esc($name, 'attr') ?>" value="1"
                   role="switch" class="toggle">
            <label for="<?= esc($id, 'attr') ?>-remover" class="text-body"><?= esc(lang('Institucional.removeFile')) ?></label>
        </div>
    <?php endif ?>
</div>

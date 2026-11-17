<?php

/**
 * Campo de formulário com rótulo acima, ajuda e erro.
 *
 * @var string                         $name
 * @var string                         $label
 * @var string                         $type
 * @var string|null                    $value
 * @var string|null                    $error
 * @var string|null                    $help
 * @var array<string, string|bool|null> $attrs
 */
$id        = 'campo-' . $name;
$describes = trim(($help !== null ? $id . '-ajuda ' : '') . ($error !== null ? $id . '-erro' : ''));

$extra = '';
foreach ($attrs as $attr => $attrValue) {
    if ($attrValue === false || $attrValue === null) {
        continue;
    }
    $extra .= $attrValue === true ? ' ' . $attr : ' ' . $attr . '="' . esc($attrValue, 'attr') . '"';
}
?>
<div class="mb-space-4">
    <label for="<?= esc($id, 'attr') ?>" class="mb-space-1 block text-body-strong"><?= esc($label) ?></label>
    <input type="<?= esc($type, 'attr') ?>" id="<?= esc($id, 'attr') ?>" name="<?= esc($name, 'attr') ?>"
           <?= $type !== 'password' ? 'value="' . esc((string) $value, 'attr') . '"' : '' ?>
           <?= $describes !== '' ? 'aria-describedby="' . esc($describes, 'attr') . '"' : '' ?>
           <?= $error !== null ? 'aria-invalid="true"' : '' ?>
           class="min-h-11 w-full rounded-sm border border-border-strong bg-surface px-space-3 text-body shadow-xs placeholder:text-ink-muted focus:border-brand aria-[invalid=true]:border-signal-critical"<?= $extra ?>>
    <?php if ($help !== null) : ?>
        <p id="<?= esc($id, 'attr') ?>-ajuda" class="mt-space-1 text-caption text-ink-muted"><?= esc($help) ?></p>
    <?php endif ?>
    <?php if ($error !== null) : ?>
        <p id="<?= esc($id, 'attr') ?>-erro" class="mt-space-1 text-data text-signal-critical"><?= esc($error) ?></p>
    <?php endif ?>
</div>

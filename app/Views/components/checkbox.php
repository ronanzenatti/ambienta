<?php

/**
 * Interruptor (toggle switch, estilo iOS) com o rótulo ao lado; alvo de toque de 44px.
 *
 * @var string $name
 * @var string $label
 * @var bool   $checked
 * @var string $value
 */
$id = 'campo-' . $name . '-' . $value;
?>
<div class="mb-space-4 flex min-h-11 items-center gap-space-3">
    <input type="checkbox" id="<?= esc($id, 'attr') ?>" name="<?= esc($name, 'attr') ?>"
           value="<?= esc($value, 'attr') ?>" <?= $checked ? 'checked' : '' ?>
           role="switch" class="toggle">
    <label for="<?= esc($id, 'attr') ?>" class="text-body"><?= esc($label) ?></label>
</div>

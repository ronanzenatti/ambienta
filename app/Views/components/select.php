<?php

/**
 * Lista de seleção com rótulo acima, ajuda e erro. Sempre com caixa de busca (diretiva x-busca, Tom Select);
 * com data-fonte nos atributos (ui_select_fonte), as opções vêm do banco sob demanda.
 *
 * @var string                         $name
 * @var string                         $label
 * @var array<string|int, string>      $options
 * @var string|int|null                $selected
 * @var string|null                    $error
 * @var string|null                    $help
 * @var array<string, string|bool|null> $attrs
 */
$id        = 'campo-' . preg_replace('/\[\]$/', '', $name);
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
    <select id="<?= esc($id, 'attr') ?>" name="<?= esc($name, 'attr') ?>" x-busca
            <?= $describes !== '' ? 'aria-describedby="' . esc($describes, 'attr') . '"' : '' ?>
            <?= $error !== null ? 'aria-invalid="true"' : '' ?>
            class="min-h-11 w-full rounded-sm border border-border-strong bg-surface px-space-3 text-body shadow-xs placeholder:text-ink-muted focus:border-brand aria-[invalid=true]:border-signal-critical"<?= $extra ?>>
        <?php foreach ($options as $optionValue => $optionLabel) : ?>
            <option value="<?= esc((string) $optionValue, 'attr') ?>"<?= (string) $optionValue === (string) $selected ? ' selected' : '' ?>>
                <?= esc($optionLabel) ?>
            </option>
        <?php endforeach ?>
    </select>
    <?php if ($help !== null) : ?>
        <p id="<?= esc($id, 'attr') ?>-ajuda" class="mt-space-1 text-caption text-ink-muted"><?= esc($help) ?></p>
    <?php endif ?>
    <?php if ($error !== null) : ?>
        <p id="<?= esc($id, 'attr') ?>-erro" class="mt-space-1 text-data text-signal-critical"><?= esc($error) ?></p>
    <?php endif ?>
</div>

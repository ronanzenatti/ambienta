<?php

/**
 * Botão (ou link com aparência de botão, quando há 'href' em $attrs).
 *
 * edit:   fundo action-edit com texto ink (branco falha o contraste).
 * delete: fundo signal-critical com texto brand-ink.
 *
 * @var string                         $label
 * @var string                         $variant 'primary' | 'secondary' | 'edit' | 'delete'
 * @var array<string, string|bool|null> $attrs
 */
$base = 'inline-flex min-h-11 min-w-11 items-center justify-center gap-space-2 rounded-sm px-space-4 py-space-2 '
    . 'text-body-strong no-underline shadow-xs transition-[filter,background-color] hover:brightness-90 '
    . 'disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:brightness-100';

// 'class' em $attrs soma classes de layout (ex.: w-full) às do componente, sem substituí-las.
$extraClass = isset($attrs['class']) && is_string($attrs['class']) ? ' ' . $attrs['class'] : '';
unset($attrs['class']);

$isLink = isset($attrs['href']);
if (! $isLink) {
    $attrs += ['type' => 'button'];
}

$html = '';
foreach ($attrs as $name => $value) {
    if ($value === false || $value === null) {
        continue;
    }
    $html .= $value === true ? ' ' . $name : ' ' . $name . '="' . esc($value, 'attr') . '"';
}
$tag = $isLink ? 'a' : 'button';
?>
<<?= $tag ?> class="<?= $base ?> <?= ui_classes_botao($variant) ?><?= esc($extraClass, 'attr') ?>"<?= $html ?>><?= esc($label) ?></<?= $tag ?>>

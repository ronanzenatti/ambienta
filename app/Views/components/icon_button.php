<?php

/**
 * Botão só com ícone, com dica visível no hover/foco (fecha com Esc) e nome acessível completo.
 * Alvo de toque de 44px (min-h-11/min-w-11), como os demais botões.
 *
 * @var string                          $icone
 * @var string                          $dica          Texto curto da dica (ex.: "Alterar")
 * @var string                          $nomeAcessivel Nome lido pelo leitor de tela (ex.: "Alterar: Lab 3")
 * @var string                          $variant
 * @var array<string, string|bool|null> $attrs
 */
$isLink = isset($attrs['href']);
if (! $isLink) {
    $attrs += ['type' => 'button'];
}
$attrs['aria-label'] = $nomeAcessivel;

$html = '';
foreach ($attrs as $name => $value) {
    if ($value === false || $value === null) {
        continue;
    }
    $html .= $value === true ? ' ' . $name : ' ' . $name . '="' . esc($value, 'attr') . '"';
}
$tag = $isLink ? 'a' : 'button';
?>
<span class="relative inline-flex" x-data="{ dica: false }"
      @mouseenter="dica = true" @mouseleave="dica = false" @focusin="dica = true" @focusout="dica = false"
      @keydown.escape.window="dica = false">
    <<?= $tag ?> class="inline-flex size-11 items-center justify-center rounded-sm shadow-xs transition-[filter,background-color] hover:brightness-90 <?= ui_classes_botao($variant) ?>"<?= $html ?>><?= ui_icon($icone) ?></<?= $tag ?>>
    <span x-show="dica" x-cloak aria-hidden="true"
          class="absolute bottom-full left-1/2 z-30 mb-space-1 -translate-x-1/2 whitespace-nowrap rounded-sm bg-ink px-space-2 py-space-1 text-caption text-paper shadow-sm"><?= esc($dica) ?></span>
</span>

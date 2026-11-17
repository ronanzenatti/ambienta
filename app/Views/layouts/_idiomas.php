<?php

/**
 * Seletor de idioma em menu suspenso (botão com a sigla atual). Fecha por Esc, pelo próprio botão ou
 * ao clicar fora; é um menu, não um modal.
 */

$idiomaAtual = service('request')->getLocale();
$idiomas     = ['pt-BR' => ['PT', 'Português'], 'en' => ['EN', 'English'], 'es' => ['ES', 'Español']];
$siglaAtual  = $idiomas[$idiomaAtual][0] ?? 'PT';
?>
<div class="relative" x-data="{ aberto: false }"
     @keydown.escape.window="aberto = false"
     @click.window="if (! $el.contains($event.target)) aberto = false">
    <button type="button" @click="aberto = ! aberto" :aria-expanded="aberto.toString()" aria-expanded="false"
            aria-controls="menu-idioma" aria-label="<?= esc(lang('Ui.language') . ': ' . ($idiomas[$idiomaAtual][1] ?? ''), 'attr') ?>"
            class="inline-flex min-h-11 min-w-11 items-center justify-center gap-space-1 rounded-sm border border-border bg-surface px-space-3 text-data text-ink shadow-xs hover:bg-paper">
        <?= ui_icon('idioma', 'size-4') ?>
        <span><?= esc($siglaAtual) ?></span>
    </button>
    <form id="menu-idioma" method="post" action="<?= site_url('idioma') ?>" x-show="aberto" x-cloak
          class="absolute right-0 z-50 mt-space-2 flex w-44 flex-col rounded-sm border border-border bg-surface p-space-1 shadow-md">
        <?= csrf_field() ?>
        <?php foreach ($idiomas as $codigo => [$sigla, $nome]) : ?>
            <button type="submit" name="idioma" value="<?= esc($codigo, 'attr') ?>" lang="<?= esc($codigo, 'attr') ?>"
                    <?= $codigo === $idiomaAtual ? 'aria-current="true"' : '' ?>
                    class="flex min-h-11 items-center gap-space-3 rounded-sm px-space-3 text-left text-body hover:bg-paper <?= $codigo === $idiomaAtual ? 'font-semibold text-ink' : 'text-ink-muted' ?>">
                <span class="w-6 text-data"><?= esc($sigla) ?></span>
                <span><?= esc($nome) ?></span>
            </button>
        <?php endforeach ?>
    </form>
</div>

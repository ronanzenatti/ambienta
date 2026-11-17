<?php

/**
 * Menu Exportar de uma listagem (fica fora da tabela, no cabeçalho da tela). <details> nativo: abre e
 * fecha sem JavaScript; com Alpine, fecha com Esc e clique fora, e cada link leva a busca, ordem e
 * página atuais da URL (a tabela a mantém sincronizada).
 *
 * @var App\Libraries\Tabela\Tabela $tabela
 */
$parametros = $tabela->parametros();
$formatos   = [
    'xlsx' => ['planilha', lang('Tabela.exportExcel')],
    'pdf'  => ['documento', lang('Tabela.exportPdf')],
];
$abrangencias = [
    'pagina'   => lang('Tabela.scopePage'),
    'pesquisa' => lang('Tabela.scopeSearch'),
    'tudo'     => lang('Tabela.scopeAll'),
];
?>
<details class="relative" x-data="ambientaExportar('<?= esc($tabela->urlExportar(), 'js') ?>')"
         @click.outside="$el.open = false"
         @keydown.escape="if ($el.open) { $el.open = false; $el.querySelector('summary').focus() }">
    <summary class="inline-flex min-h-11 cursor-pointer list-none items-center gap-space-2 rounded-sm border border-border-strong bg-surface px-space-4 py-space-2 text-body-strong text-ink shadow-xs hover:bg-paper [&::-webkit-details-marker]:hidden">
        <?= ui_icon('download') ?>
        <?= esc(lang('Tabela.export')) ?>
        <?= ui_icon('seta-baixo', 'size-4') ?>
    </summary>
    <div class="absolute right-0 z-40 mt-space-2 w-72 rounded-md border border-border bg-surface p-space-2 shadow-lg">
        <?php foreach ($formatos as $formato => [$icone, $rotulo]) : ?>
            <p id="exportar-<?= esc($tabela->id(), 'attr') ?>-<?= $formato ?>" class="flex items-center gap-space-2 px-space-3 pb-space-1 pt-space-2 text-caption text-ink-muted">
                <?= ui_icon($icone, 'size-4') ?><?= esc($rotulo) ?>
            </p>
            <ul aria-labelledby="exportar-<?= esc($tabela->id(), 'attr') ?>-<?= $formato ?>">
                <?php foreach ($abrangencias as $abrangencia => $texto) : ?>
                    <li>
                        <a href="<?= esc($tabela->urlExportar() . $parametros->query($tabela, ['formato' => $formato, 'abrangencia' => $abrangencia]), 'attr') ?>"
                           @click="$el.href = link('<?= $formato ?>', '<?= $abrangencia ?>'); $el.closest('details').open = false"
                           class="flex min-h-11 items-center rounded-sm px-space-3 text-body text-ink no-underline hover:bg-paper">
                            <?= esc($texto) ?><span class="sr-only"> (<?= esc($rotulo) ?>)</span>
                        </a>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endforeach ?>
    </div>
</details>

<?php

/**
 * Tela "Números de patrimônio": aviso de que patrimonial só pode ser usado depois de ter número, as duas formas
 * de preencher (itens novos de um modelo; itens já lançados, com filtro por modelo) e o resultado da última
 * importação: quantos foram gravados e cada linha rejeitada, com o motivo.
 *
 * @var array{gravados: int, rejeitados: list<array{linha: int, numero: string, motivo: string}>}|null $resultado
 * @var array<string, mixed>|null $modelo  modelo do filtro da listagem
 * @var int                       $limite  máximo de linhas da planilha de itens novos
 * @var array<string, string>     $erros   erros do pedido da planilha em branco
 * @var array<string, string>     $valores valores digitados no pedido da planilha em branco
 */
$arquivoAceito = '.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
?>
<div class="mb-space-6 max-w-3xl"><?= item_aviso_pendente() ?></div>

<?php if ($resultado !== null) : ?>
    <section aria-labelledby="importacao-resultado" class="mb-space-6 max-w-3xl">
        <h2 id="importacao-resultado" class="sr-only"><?= esc(lang('Patrimonio.importResult')) ?></h2>
        <?= ui_alert($resultado['rejeitados'] === [] ? 'confirmed' : 'pending', lang('Patrimonio.importSummary', [$resultado['gravados'], count($resultado['rejeitados'])])) ?>
        <?php if ($resultado['rejeitados'] !== []) : ?>
            <p class="mb-space-2 text-body-strong"><?= esc(lang('Patrimonio.importRejectedTitle')) ?></p>
            <ul class="rounded-md border border-border bg-surface shadow-xs">
                <?php foreach ($resultado['rejeitados'] as $rejeitada) : ?>
                    <li class="border-b border-border px-space-4 py-space-2 text-body last:border-b-0">
                        <?= esc(lang('Patrimonio.importRejectedLine', [$rejeitada['linha'], $rejeitada['numero'], lang($rejeitada['motivo'])])) ?>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </section>
<?php endif ?>

<div class="mb-space-8 grid items-start gap-space-6 lg:grid-cols-2">
    <section aria-labelledby="novos-titulo" class="min-w-0 rounded-md border border-border bg-surface shadow-xs p-space-6">
        <h2 id="novos-titulo" class="mb-space-2 text-heading"><?= esc(lang('Patrimonio.newItemsTitle')) ?></h2>
        <p class="mb-space-4 text-body text-ink-muted"><?= esc(lang('Patrimonio.newItemsIntro')) ?></p>

        <form method="get" action="<?= site_url('patrimonio/numeros/modelo') ?>" class="mb-space-6">
            <?= ui_select_fonte('modelo_novo', lang('Patrimonio.fieldModel'), 'modelos', [], $valores['modelo'] ?? null, lang('Ui.select'), $erros['modelo'] ?? null, null, null, ['required' => true]) ?>
            <div class="grid gap-x-space-4 sm:grid-cols-[8rem_1fr]">
                <?= ui_field('quantidade', lang('Patrimonio.fieldQuantity'), 'number', (string) ($valores['quantidade'] ?? '1'), $erros['quantidade'] ?? null, null, ['required' => true, 'min' => '1', 'max' => (string) $limite, 'step' => '1', 'inputmode' => 'numeric']) ?>
                <?= ui_select_fonte('local', lang('Patrimonio.fieldLocation'), 'locais', ['chave' => 'patrimonio.acesso', 'nivel' => 'editor'], $valores['local'] ?? null, lang('Ui.select'), $erros['local'] ?? null, null, null, ['required' => true]) ?>
            </div>
            <?= ui_button(lang('Patrimonio.downloadBlankSheet'), 'primary', ['type' => 'submit']) ?>
        </form>

        <form method="post" action="<?= site_url('patrimonio/numeros/importar-novos') ?>" enctype="multipart/form-data" class="flex flex-wrap items-end gap-space-3">
            <?= csrf_field() ?>
            <div class="min-w-48 flex-1">
                <?= ui_field('planilha_novos', lang('Patrimonio.fieldSpreadsheetNew'), 'file', null, null, lang('Patrimonio.spreadsheetHelp'), ['required' => true, 'accept' => $arquivoAceito]) ?>
            </div>
            <div class="mb-space-4">
                <?= ui_button(lang('Patrimonio.importNewItems'), 'primary', ['type' => 'submit']) ?>
            </div>
        </form>
    </section>

    <section aria-labelledby="importacao-titulo" class="min-w-0 rounded-md border border-border bg-surface shadow-xs p-space-6">
        <h2 id="importacao-titulo" class="mb-space-2 text-heading"><?= esc(lang('Patrimonio.importTitle')) ?></h2>
        <ol class="mb-space-4 list-decimal pl-space-6 text-body text-ink-muted">
            <li><?= esc(lang('Patrimonio.importStep1')) ?></li>
            <li><?= esc(lang('Patrimonio.importStep2')) ?></li>
            <li><?= esc(lang('Patrimonio.importStep3')) ?></li>
        </ol>

        <form method="get" action="<?= site_url('patrimonio/numeros') ?>" role="search" class="mb-space-6 flex flex-wrap items-end gap-space-3">
            <div class="min-w-48 flex-1">
                <?= ui_select_fonte('modelo', lang('Patrimonio.filterByModel'), 'modelos', [], $modelo['id_modelo_item'] ?? null, lang('Patrimonio.allModels')) ?>
            </div>
            <div class="mb-space-4">
                <?= ui_button(lang('Patrimonio.filter'), 'secondary', ['type' => 'submit']) ?>
            </div>
        </form>

        <form method="post" action="<?= site_url('patrimonio/numeros/importar') ?>" enctype="multipart/form-data" class="flex flex-wrap items-end gap-space-3">
            <?= csrf_field() ?>
            <div class="min-w-48 flex-1">
                <?= ui_field('planilha', lang('Patrimonio.fieldSpreadsheet'), 'file', null, null, lang('Patrimonio.spreadsheetHelp'), ['required' => true, 'accept' => $arquivoAceito]) ?>
            </div>
            <div class="mb-space-4">
                <?= ui_button(lang('Patrimonio.import'), 'primary', ['type' => 'submit']) ?>
            </div>
        </form>
    </section>
</div>

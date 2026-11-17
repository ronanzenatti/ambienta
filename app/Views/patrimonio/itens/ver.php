<?php

/**
 * Página do item: dados próprios, ações (editar, movimentar, pedir descarte) e o histórico de movimentações.
 * Item descartado continua consultável, sem ações. Patrimoniável sem número é avisado em amarelo (ponto + texto)
 * e não pode ser movido.
 *
 * @var array<string, mixed>        $item
 * @var array<string, mixed>|null   $modelo     modelo de onde o item veio (molde), se veio de um
 * @var array<string, mixed>|null   $categoria
 * @var string                      $local
 * @var App\Libraries\Tabela\Tabela $tabela     histórico de movimentações
 * @var bool                        $podeEditar editor de patrimônio no local do item (e item ativo)
 * @var int|null                    $descarte   solicitação de descarte pendente
 */
$id       = (int) $item['id_item'];
$numero   = item_numero($item);
$nome     = (string) $item['nome'];
$status   = (string) $item['status'];
$livre    = in_array($status, ['em_uso', 'almoxarifado'], true);
$pendente = \App\Models\Patrimonio\ItemModel::pendente($item) && $item['deletado_em'] === null;
$patrimonial = (bool) $item['patrimoniavel'];
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($numero . ' · ' . $nome) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [[lang('Patrimonio.itemsTitle'), site_url('patrimonio/itens')], [$nome, null]]], ['saveData' => false]) ?>

    <p class="text-data text-ink-muted tabular-nums"><?= esc($numero) ?></p>
    <h1 class="mb-space-6 text-display"><?= esc($nome) ?></h1>

    <?php if ($pendente) : ?>
        <div class="mb-space-6 max-w-3xl"><?= item_aviso_pendente() ?></div>
    <?php endif ?>

    <?php if ($descarte !== null) : ?>
        <div class="mb-space-6 max-w-3xl">
            <?= ui_alert('pending', lang('Patrimonio.itemDisposalPending')) ?>
            <a href="<?= site_url("patrimonio/descartes/{$descarte}") ?>" class="inline-flex min-h-11 items-center text-body underline"><?= esc(lang('Patrimonio.viewDisposal')) ?></a>
        </div>
    <?php endif ?>

    <?php if ($podeEditar) : ?>
        <div class="mb-space-6 flex flex-wrap gap-space-3">
            <?php if ($livre && $descarte === null && ! $pendente) : ?>
                <?= ui_button(lang('Patrimonio.move'), 'primary', ['href' => site_url("patrimonio/itens/{$id}/movimentar")]) ?>
            <?php endif ?>
            <?= ui_button(lang('Patrimonio.itemEdit'), 'edit', ['href' => site_url("patrimonio/itens/{$id}/editar")]) ?>
            <?php if ($status !== 'em_transito' && $descarte === null) : ?>
                <?= ui_button(lang('Patrimonio.requestDisposal'), 'secondary', ['href' => site_url("patrimonio/itens/{$id}/descartar")]) ?>
            <?php endif ?>
        </div>
    <?php endif ?>

    <?= ui_recolhivel_inicio(lang('Ui.recordDetails')) ?>
    <section aria-labelledby="item-dados" class="max-w-3xl rounded-md border border-border bg-surface shadow-xs p-space-6">
        <h2 id="item-dados" class="sr-only"><?= esc(lang('Institucional.sectionData')) ?></h2>
        <dl class="grid grid-cols-[auto_1fr] gap-x-space-4 gap-y-space-2 text-body">
            <dt class="text-ink-muted"><?= esc(lang('Patrimonio.fieldNumber')) ?></dt>
            <dd class="tabular-nums"><?= $pendente ? ui_status_dot('pending', $numero) : esc($numero) ?></dd>
            <?php if ($modelo !== null) : ?>
                <dt class="text-ink-muted"><?= esc(lang('Patrimonio.fieldModelOrigin')) ?></dt>
                <dd><a href="<?= site_url('patrimonio/modelos/' . $modelo['id_modelo_item']) ?>" class="underline"><?= esc($modelo['nome']) ?></a></dd>
            <?php endif ?>
            <dt class="text-ink-muted"><?= esc(lang('Patrimonio.fieldCategory')) ?></dt>
            <dd><?= esc($categoria['nome'] ?? '') ?></dd>
            <?php if (! empty($item['marca'])) : ?>
                <dt class="text-ink-muted"><?= esc(lang('Patrimonio.fieldBrand')) ?></dt>
                <dd><?= esc($item['marca']) ?></dd>
            <?php endif ?>
            <dt class="text-ink-muted"><?= esc(lang('Patrimonio.fieldControl')) ?></dt>
            <dd><?= esc(lang($patrimonial ? 'Patrimonio.controlAsset' : 'Patrimonio.controlQuantity')) ?></dd>
            <?php if (! $patrimonial) : ?>
                <dt class="text-ink-muted"><?= esc(lang('Patrimonio.fieldQuantity')) ?></dt>
                <dd class="tabular-nums"><?= esc($item['quantidade']) ?></dd>
            <?php endif ?>
            <dt class="text-ink-muted"><?= esc(lang('Patrimonio.fieldLocation')) ?></dt>
            <dd><?= esc($local) ?></dd>
            <dt class="text-ink-muted"><?= esc(lang('Ui.status')) ?></dt>
            <dd><?= ui_status_dot(\App\Tabelas\Patrimonio\ItensTabela::tom($status), lang('Patrimonio.status_' . $status)) ?></dd>
        </dl>
        <?php if (! empty($item['especificacoes'])) : ?>
            <h3 class="mt-space-4 text-body-strong"><?= esc(lang('Patrimonio.fieldSpecs')) ?></h3>
            <p class="whitespace-pre-line text-body"><?= esc($item['especificacoes']) ?></p>
        <?php endif ?>
    </section>
    <?= ui_recolhivel_fim() ?>

    <section aria-labelledby="item-historico">
        <div class="mb-space-4 flex flex-wrap items-center justify-between gap-space-4">
            <h2 id="item-historico" class="text-title"><?= esc($tabela->titulo()) ?></h2>
            <?= $tabela->botaoExportar() ?>
        </div>

        <?= $tabela->renderizar() ?>
    </section>
<?= $this->endSection() ?>

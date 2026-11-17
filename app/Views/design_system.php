<?php
/**
 * Vitrine dos componentes base (somente em desenvolvimento).
 *
 * @var bool $deleted
 */
$swatches = [
    'ink', 'ink-muted', 'paper', 'surface', 'table-stripe', 'border', 'border-strong', 'brand', 'brand-ink',
    'signal-confirmed', 'signal-pending', 'signal-critical', 'action-edit',
];
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>Design System<?= $this->endSection() ?>

<?= $this->section('content') ?>
    <h1 class="mb-space-2 text-display">Design System</h1>
    <p class="mb-space-8 text-body text-ink-muted">Vitrine dos componentes base. Disponível só em desenvolvimento.</p>

    <?php if ($deleted) : ?>
        <p class="mb-space-6 rounded-sm border border-border-strong bg-surface p-space-4" role="status">
            <?= ui_status_dot('confirmed', 'Formulário de exclusão enviado (demonstração): CONFIRMO foi digitado.') ?>
        </p>
    <?php endif ?>

    <section class="mb-space-8" aria-labelledby="ds-status">
        <h2 id="ds-status" class="mb-space-4 border-b border-border pb-space-2 text-title">Indicador de status</h2>
        <ul class="flex flex-wrap gap-space-6">
            <li><?= ui_status_dot('confirmed', 'Confirmada') ?></li>
            <li><?= ui_status_dot('pending', 'Aguardando confirmação') ?></li>
            <li><?= ui_status_dot('critical', 'Cancelada') ?></li>
        </ul>
    </section>

    <section class="mb-space-8" aria-labelledby="ds-placa">
        <h2 id="ds-placa" class="mb-space-4 border-b border-border pb-space-2 text-title">Placa de ambiente</h2>
        <div class="flex flex-wrap gap-space-4">
            <?= ui_placa_ambiente('A-101', 'Laboratório de Informática 1') ?>
            <?= ui_placa_ambiente('B-203', 'Sala de aula 203') ?>
        </div>
    </section>

    <section class="mb-space-8" aria-labelledby="ds-botoes">
        <h2 id="ds-botoes" class="mb-space-4 border-b border-border pb-space-2 text-title">Botões</h2>
        <div class="flex flex-wrap gap-space-4">
            <?= ui_button(lang('Ui.save'), 'primary', ['type' => 'submit']) ?>
            <?= ui_button(lang('Ui.cancel'), 'secondary') ?>
            <?= ui_button(lang('Ui.edit'), 'edit') ?>
            <?= ui_button(lang('Ui.delete'), 'delete') ?>
            <?= ui_button(lang('Ui.edit'), 'edit', ['disabled' => true]) ?>
            <?= ui_button('Link com aparência de botão', 'primary', ['href' => '#']) ?>
        </div>
    </section>

    <section class="mb-space-8" aria-labelledby="ds-modal">
        <h2 id="ds-modal" class="mb-space-4 border-b border-border pb-space-2 text-title">Modais</h2>
        <p class="mb-space-4 text-body">Nenhum fecha ao clicar fora: só por Cancelar/Fechar ou Esc.</p>
        <div class="flex flex-wrap gap-space-4">
            <?= ui_button('Abrir modal', 'secondary', ['@click' => "\$dispatch('open-modal', 'demo-modal')"]) ?>
            <?= ui_button(lang('Ui.delete'), 'delete', ['@click' => "\$dispatch('open-modal', 'demo-excluir')"]) ?>
        </div>
        <?= ui_modal('demo-modal', 'Título do modal', '<p>Conteúdo do modal. Clique fora da caixa: nada acontece.</p>') ?>
        <?= ui_confirm_delete('demo-excluir', 'O registro de demonstração será excluído.', site_url('_design-system')) ?>
    </section>

    <section class="mb-space-8" aria-labelledby="ds-tipo">
        <h2 id="ds-tipo" class="mb-space-4 border-b border-border pb-space-2 text-title">Tipografia</h2>
        <p class="text-display">display · Título de página</p>
        <p class="text-title">title · Título de seção</p>
        <p class="text-heading">heading · Cabeçalho de painel</p>
        <p class="text-body">body · Texto corrido de formulário e listas</p>
        <p class="text-body-strong">body-strong · Ênfase no corpo</p>
        <p class="text-data tabular-nums">data · 0123456789 células de tabela</p>
        <p class="text-caption text-ink-muted">caption · Metadados e timestamps</p>
    </section>

    <section aria-labelledby="ds-cores">
        <h2 id="ds-cores" class="mb-space-4 border-b border-border pb-space-2 text-title">Cores</h2>
        <ul class="grid grid-cols-2 gap-space-4 sm:grid-cols-4">
            <?php foreach ($swatches as $name) : ?>
                <li class="text-caption">
                    <span class="mb-space-1 block h-space-8 rounded-sm border border-border" style="background-color: var(--color-<?= $name ?>)"></span>
                    <?= $name ?>
                </li>
            <?php endforeach ?>
        </ul>
    </section>
<?= $this->endSection() ?>

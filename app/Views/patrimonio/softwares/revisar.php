<?php

/**
 * Revisão de um software proposto por professor: aprovar, reprovar (motivo obrigatório) ou mesclar num software
 * já aprovado (os requisitos passam a exigir o escolhido e o proposto é excluído).
 *
 * @var array<string, mixed>  $software
 * @var array<string, string> $erros
 */
$id     = (int) $software['id_software'];
$rotulo = trim($software['nome'] . ' ' . ($software['versao'] ?? ''));
$fila   = site_url('patrimonio/softwares/pendentes');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($rotulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [[lang('Patrimonio.softwaresQueueTitle'), $fila], [$rotulo, null]]], ['saveData' => false]) ?>

    <h1 class="mb-space-2 text-display"><?= esc($rotulo) ?></h1>
    <p class="mb-space-6"><?= ui_status_dot('pending', lang('Patrimonio.softwareStatus_pendente')) ?></p>

    <dl class="mb-space-8 max-w-3xl rounded-md border border-border bg-surface p-space-4 shadow-xs">
        <dt class="text-data text-ink-muted"><?= esc(lang('Patrimonio.softwareRequestedBy')) ?></dt>
        <dd class="mb-space-3 text-body"><?= esc((string) ($software['solicitante'] ?? '—')) ?></dd>
        <dt class="text-data text-ink-muted"><?= esc(lang('Patrimonio.fieldRequirements')) ?></dt>
        <dd class="text-body"><?= nl2br(esc((string) ($software['requisitos_tecnicos'] ?? '—'))) ?></dd>
    </dl>

    <div class="grid max-w-5xl gap-space-6 lg:grid-cols-3">
        <section aria-labelledby="sw-aprovar" class="rounded-md border border-border bg-surface p-space-4 shadow-xs">
            <h2 id="sw-aprovar" class="mb-space-2 text-title"><?= esc(lang('Patrimonio.softwareApprove')) ?></h2>
            <p class="mb-space-4 text-body text-ink-muted"><?= esc(lang('Patrimonio.softwareApproveHelp')) ?></p>
            <form method="post" action="<?= site_url("patrimonio/softwares/{$id}/aprovar") ?>">
                <?= csrf_field() ?>
                <?= ui_button(lang('Patrimonio.softwareApprove'), 'primary', ['type' => 'submit']) ?>
            </form>
        </section>

        <section aria-labelledby="sw-reprovar" class="rounded-md border border-border bg-surface p-space-4 shadow-xs">
            <h2 id="sw-reprovar" class="mb-space-2 text-title"><?= esc(lang('Patrimonio.softwareReject')) ?></h2>
            <form method="post" action="<?= site_url("patrimonio/softwares/{$id}/reprovar") ?>">
                <?= csrf_field() ?>
                <?= ui_textarea('motivo', lang('Patrimonio.softwareReason'), '', $erros['motivo'] ?? null, lang('Patrimonio.softwareReasonHelp'), 3, ['required' => true, 'maxlength' => '5000']) ?>
                <?= ui_button(lang('Patrimonio.softwareReject'), 'delete', ['type' => 'submit']) ?>
            </form>
        </section>

        <section aria-labelledby="sw-mesclar" class="rounded-md border border-border bg-surface p-space-4 shadow-xs">
            <h2 id="sw-mesclar" class="mb-space-2 text-title"><?= esc(lang('Patrimonio.softwareMerge')) ?></h2>
            <form method="post" action="<?= site_url("patrimonio/softwares/{$id}/mesclar") ?>">
                <?= csrf_field() ?>
                <?= ui_select_fonte('destino_id', lang('Patrimonio.softwareMergeInto'), 'softwares', [], null, lang('Ui.select'), $erros['destino_id'] ?? null, lang('Patrimonio.softwareMergeHelp'), null, ['required' => true]) ?>
                <?= ui_button(lang('Patrimonio.softwareMerge'), 'secondary', ['type' => 'submit']) ?>
            </form>
        </section>
    </div>
<?= $this->endSection() ?>

<?php

/**
 * Solicitação de descarte: dados e a decisão. Aprovar dá baixa definitiva no item (soft delete; o número
 * de patrimônio nunca volta a ser usado), por isso passa pelo modal CONFIRMO. Rejeitar é um POST simples.
 *
 * @var array<string, mixed> $descarte
 * @var string               $local
 * @var bool                 $podeDecidir
 */
$id      = (int) $descarte['id_descarte'];
$rotulo  = item_rotulo($descarte);
$status  = (string) $descarte['status'];
$formato = lang('Institucional.dateFormat') . ' H:i';
$data    = static fn (mixed $valor): string => $valor === null ? '' : date($formato, (int) strtotime((string) $valor));
$titulo  = lang('Patrimonio.disposalTitle');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [
        [lang('Patrimonio.disposalsTitle'), site_url('patrimonio/descartes')],
        [$rotulo, null],
    ]], ['saveData' => false]) ?>

    <h1 class="mb-space-6 text-display"><?= esc($titulo) ?></h1>

    <section aria-labelledby="descarte-item" class="mb-space-6 max-w-3xl rounded-md border border-border bg-surface shadow-xs p-space-6">
        <div class="mb-space-4 flex flex-wrap items-start justify-between gap-space-4">
            <h2 id="descarte-item" class="text-heading">
                <a href="<?= site_url('patrimonio/itens/' . $descarte['item_id']) ?>" class="underline"><?= esc($rotulo) ?></a>
            </h2>
            <?= ui_status_dot(\App\Tabelas\Patrimonio\DescartesTabela::tom($status), lang('Patrimonio.disposal_' . $status)) ?>
        </div>
        <dl class="grid grid-cols-[auto_1fr] gap-x-space-4 gap-y-space-2 text-body">
            <dt class="text-ink-muted"><?= esc(lang('Patrimonio.fieldLocation')) ?></dt>
            <dd><?= esc($local) ?></dd>
            <dt class="text-ink-muted"><?= esc(lang('Patrimonio.fieldReason')) ?></dt>
            <dd><?= esc($descarte['motivo']) ?></dd>
            <dt class="text-ink-muted"><?= esc(lang('Patrimonio.fieldRequester')) ?></dt>
            <dd><?= esc($descarte['solicitante']) ?></dd>
            <dt class="text-ink-muted"><?= esc(lang('Patrimonio.fieldRequestedAt')) ?></dt>
            <dd class="tabular-nums"><?= esc($data($descarte['data_solicitacao'])) ?></dd>
            <?php if ($status !== 'pendente') : ?>
                <dt class="text-ink-muted"><?= esc(lang('Patrimonio.fieldApprover')) ?></dt>
                <dd><?= esc($descarte['aprovador']) ?></dd>
                <dt class="text-ink-muted"><?= esc(lang('Patrimonio.fieldDecidedAt')) ?></dt>
                <dd class="tabular-nums"><?= esc($data($descarte['data_decisao'])) ?></dd>
            <?php endif ?>
        </dl>
        <?php if (! empty($descarte['justificativa'])) : ?>
            <h3 class="mt-space-4 text-body-strong"><?= esc(lang('Patrimonio.fieldJustification')) ?></h3>
            <p class="whitespace-pre-line text-body"><?= esc($descarte['justificativa']) ?></p>
        <?php endif ?>
    </section>

    <?php if ($podeDecidir) : ?>
        <div class="flex max-w-3xl flex-wrap gap-space-3">
            <?= ui_button(lang('Patrimonio.approveDisposal'), 'delete', ['@click' => "\$dispatch('open-modal', 'aprovar-descarte')"]) ?>
            <form method="post" action="<?= site_url("patrimonio/descartes/{$id}/rejeitar") ?>">
                <?= csrf_field() ?>
                <?= ui_button(lang('Patrimonio.rejectDisposal'), 'secondary', ['type' => 'submit']) ?>
            </form>
        </div>
        <?= ui_confirm_delete(
            'aprovar-descarte',
            lang('Patrimonio.approveDisposalText', [$rotulo]),
            site_url("patrimonio/descartes/{$id}/aprovar"),
            lang('Patrimonio.approveDisposalTitle'),
            lang('Patrimonio.approveDisposal')
        ) ?>
    <?php endif ?>
<?= $this->endSection() ?>

<?php

/**
 * Movimentação de item: a tela "Confirmar recebimento" (protocolo) para o destinatário, e o registro da
 * movimentação para os demais. Atraso aparece como alerta (ponto + texto), nunca só pela cor.
 *
 * @var array<string, mixed>      $mov
 * @var array<string, mixed>|null $item
 * @var string                    $origem
 * @var string                    $destino
 * @var string                    $remetente
 * @var string                    $destinatario
 * @var bool                      $atrasada
 * @var bool                      $podeConfirmar
 * @var bool                      $podeCancelar
 */
$id        = (int) $mov['id_movimentacao_item'];
$pendente  = $mov['status'] === 'pendente';
$numero    = item_numero($item ?? ['numero_patrimonio' => null, 'patrimoniavel' => 1]);
$modelo    = (string) ($item['nome'] ?? '') . ((int) ($item['quantidade'] ?? 1) > 1 ? ' (×' . (int) $item['quantidade'] . ')' : '');
$formato   = lang('Institucional.dateFormat') . ' H:i';
$data      = static fn (?string $valor): string => $valor === null ? '' : date($formato, (int) strtotime($valor));
$situacao  = $pendente ? ($atrasada ? 'atrasada' : 'pendente') : 'confirmada';
$titulo    = $podeConfirmar ? lang('Patrimonio.confirmReceiptTitle') : lang('Patrimonio.movementTitle');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [
        [lang('Patrimonio.movementsTitle'), site_url('patrimonio/movimentacoes')],
        [$numero . ' · ' . $modelo, null],
    ]], ['saveData' => false]) ?>

    <h1 class="mb-space-2 text-display"><?= esc($titulo) ?></h1>
    <p class="mb-space-6 text-body text-ink-muted"><?= esc(lang($podeConfirmar ? 'Patrimonio.confirmReceiptIntro' : 'Patrimonio.movementIntro')) ?></p>

    <?php if ($atrasada) : ?>
        <div class="mb-space-6 max-w-3xl"><?= ui_alert('critical', lang('Patrimonio.movementOverdue', [$data((string) $mov['data_limite_confirmacao'])])) ?></div>
    <?php endif ?>

    <section aria-labelledby="mov-item" class="mb-space-6 max-w-3xl rounded-md border border-border bg-surface shadow-xs p-space-6">
        <div class="mb-space-6 flex flex-wrap items-start justify-between gap-space-4">
            <div class="flex items-center gap-space-4">
                <?php if (! empty($item['foto'])) : ?>
                    <img src="<?= esc(arquivo_url((string) $item['foto']), 'attr') ?>" alt="<?= esc(lang('Patrimonio.modelPhotoAlt', [$modelo]), 'attr') ?>" class="size-16 rounded-sm border border-border object-contain">
                <?php endif ?>
                <div>
                    <h2 id="mov-item" class="text-heading tabular-nums">
                        <a href="<?= site_url('patrimonio/itens/' . $mov['item_id']) ?>" class="underline"><?= esc($numero) ?></a>
                    </h2>
                    <p class="text-body"><?= esc($modelo) ?></p>
                </div>
            </div>
            <?= ui_status_dot(\App\Tabelas\Patrimonio\MovimentacoesTabela::tom($situacao), lang('Patrimonio.movement_' . $situacao)) ?>
        </div>

        <div class="mb-space-6 grid gap-space-4 sm:grid-cols-[1fr_auto_1fr] sm:items-center">
            <div class="rounded-sm border border-border p-space-4">
                <p class="text-caption text-ink-muted"><?= esc(lang('Patrimonio.fieldOrigin')) ?></p>
                <p class="text-body-strong"><?= esc($origem) ?></p>
            </div>
            <span class="justify-self-center text-ink-muted" aria-hidden="true"><?= ui_icon('seta-direita', 'size-6') ?></span>
            <div class="rounded-sm border border-border p-space-4">
                <p class="text-caption text-ink-muted"><?= esc(lang('Patrimonio.fieldDestination')) ?></p>
                <p class="text-body-strong"><?= esc($destino) ?></p>
            </div>
        </div>

        <dl class="grid grid-cols-[auto_1fr] gap-x-space-4 gap-y-space-2 text-body">
            <dt class="text-ink-muted"><?= esc(lang('Patrimonio.fieldSender')) ?></dt>
            <dd><?= esc($remetente) ?></dd>
            <dt class="text-ink-muted"><?= esc(lang('Patrimonio.fieldRecipient')) ?></dt>
            <dd><?= esc($destinatario) ?></dd>
            <dt class="text-ink-muted"><?= esc(lang('Patrimonio.fieldSentAt')) ?></dt>
            <dd class="tabular-nums"><?= esc($data((string) $mov['data_envio'])) ?></dd>
            <dt class="text-ink-muted"><?= esc(lang('Patrimonio.fieldDeadline')) ?></dt>
            <dd class="tabular-nums"><?= esc($data($mov['data_limite_confirmacao'] === null ? null : (string) $mov['data_limite_confirmacao'])) ?></dd>
            <?php if (! $pendente) : ?>
                <dt class="text-ink-muted"><?= esc(lang('Patrimonio.fieldConfirmedAt')) ?></dt>
                <dd class="tabular-nums"><?= esc($data((string) $mov['data_confirmacao'])) ?></dd>
            <?php endif ?>
        </dl>
    </section>

    <?php if ($podeConfirmar || $podeCancelar) : ?>
        <div class="flex max-w-3xl flex-wrap gap-space-3">
            <?php if ($podeConfirmar) : ?>
                <form method="post" action="<?= site_url("patrimonio/movimentacoes/{$id}/confirmar") ?>">
                    <?= csrf_field() ?>
                    <?= ui_button(lang('Patrimonio.confirmReceipt'), 'primary', ['type' => 'submit']) ?>
                </form>
            <?php endif ?>
            <?php if ($podeCancelar) : ?>
                <?= ui_button(lang('Patrimonio.cancelMovement'), 'delete', ['@click' => "\$dispatch('open-modal', 'cancelar-movimentacao')"]) ?>
            <?php endif ?>
        </div>
        <?php if ($podeCancelar) : ?>
            <?= ui_confirm_delete(
                'cancelar-movimentacao',
                lang('Patrimonio.cancelMovementText', [$numero . ' · ' . $modelo]),
                site_url("patrimonio/movimentacoes/{$id}"),
                lang('Patrimonio.cancelMovementTitle'),
                lang('Patrimonio.cancelMovement')
            ) ?>
        <?php endif ?>
    <?php endif ?>
<?= $this->endSection() ?>

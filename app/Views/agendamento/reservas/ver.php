<?php

/**
 * Página da reserva: dados e situação, conflito com alocação semestral (Autorizar/Negar para quem decide),
 * ocorrências da série, equipamentos/softwares extras (deferir/indeferir e confirmar devolução) e as ações
 * da reserva (Confirmar; Alterar e Cancelar em modal, que recarregam a página ao salvar).
 *
 * @var array<string, mixed>                                                       $reserva
 * @var string                                                                     $ambiente
 * @var array<string, mixed>|null                                                  $conflito
 * @var list<array<string, mixed>>                                                 $serie
 * @var array{itens: list<array<string, mixed>>, softwares: list<array<string, mixed>>} $extras
 * @var bool                                                                       $podeConfirmar
 * @var bool                                                                       $podeDecidir
 * @var bool                                                                       $podeMexer
 * @var bool                                                                       $podeExtras
 */

use App\Tabelas\Agendamento\ReservasTabela;

$id      = (int) $reserva['id_reserva_pontual'];
$status  = (string) $reserva['status'];
$formato = lang('Institucional.dateFormat') . ' H:i';
$data    = static fn (mixed $valor): string => $valor === null ? '' : date($formato, (int) strtotime((string) $valor));
$titulo  = (string) $reserva['titulo'];
$aqui    = '/agendamento/reservas/' . $id;
$ponto   = static fn (string $s): string => ui_status_dot(ReservasTabela::tom($s), lang('Agendamento.reservationStatus_' . $s));
$tomExtra = static fn (string $s): string => match ($s) {
    'deferido'   => 'confirmed',
    'indeferido' => 'critical',
    default      => 'pending',
};
$temModal = $podeMexer || $podeDecidir;
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [[lang('Agendamento.reservationsTitle'), site_url('agendamento/reservas')], [$titulo, null]]], ['saveData' => false]) ?>

    <div class="mb-space-6 flex max-w-3xl flex-wrap items-start justify-between gap-space-4">
        <h1 class="text-display"><?= esc($titulo) ?></h1>
        <div class="flex flex-wrap gap-space-2">
            <?php if ($podeConfirmar) : ?>
                <form method="post" action="<?= site_url("agendamento/reservas/{$id}/confirmar") ?>">
                    <?= csrf_field() ?>
                    <?= ui_button(lang('Agendamento.confirm'), 'primary', ['type' => 'submit']) ?>
                </form>
            <?php endif ?>
            <?php if ($podeMexer) : ?>
                <?= ui_button(lang('Ui.edit'), 'edit', ['href' => site_url("agendamento/reservas/{$id}/alterar"), '@click' => 'ambientaAbrirFormulario($event)']) ?>
                <?= ui_button(lang('Agendamento.cancelReservation'), 'secondary', ['href' => site_url("agendamento/reservas/{$id}/cancelar"), '@click' => 'ambientaAbrirFormulario($event)']) ?>
            <?php endif ?>
        </div>
    </div>

    <?php if ($conflito !== null && $conflito['decisao'] === 'pendente') : ?>
        <section aria-labelledby="reserva-conflito" class="mb-space-6 max-w-3xl rounded-md border border-signal-pending/40 bg-signal-pending/5 p-space-6">
            <h2 id="reserva-conflito" class="mb-space-2 text-heading"><?= ui_status_dot('pending', lang('Agendamento.conflictPending')) ?></h2>
            <p class="mb-space-4 text-body"><?= esc(lang('Agendamento.conflictWith', [
                $conflito['sigla'] . ' — ' . $conflito['disciplina'],
                (string) $conflito['professor'],
                substr((string) $conflito['hora_inicio'], 0, 5) . '–' . substr((string) $conflito['hora_fim'], 0, 5),
            ])) ?></p>
            <?php if ($podeDecidir) : ?>
                <p class="mb-space-4 text-caption text-ink-muted"><?= esc(lang('Agendamento.conflictDecisionHelp')) ?></p>
                <div class="flex flex-wrap gap-space-2">
                    <form method="post" action="<?= site_url('agendamento/conflitos/' . $conflito['id_conflito_agendamento'] . '/autorizar') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="voltar" value="<?= esc($aqui, 'attr') ?>">
                        <?= ui_button(lang('Agendamento.authorize'), 'primary', ['type' => 'submit']) ?>
                    </form>
                    <?= ui_button(lang('Agendamento.deny'), 'secondary', [
                        'href'   => site_url('agendamento/conflitos/' . $conflito['id_conflito_agendamento'] . '/negar') . '?voltar=' . rawurlencode($aqui),
                        '@click' => 'ambientaAbrirFormulario($event)',
                    ]) ?>
                </div>
            <?php else : ?>
                <p class="text-body text-ink-muted"><?= esc(lang('Agendamento.conflictWaiting')) ?></p>
            <?php endif ?>
        </section>
    <?php endif ?>

    <section aria-labelledby="reserva-dados" class="mb-space-8 max-w-3xl rounded-md border border-border bg-surface p-space-6 shadow-xs">
        <div class="mb-space-4 flex flex-wrap items-start justify-between gap-space-4">
            <h2 id="reserva-dados" class="text-heading"><?= esc($ambiente) ?></h2>
            <?= $ponto($status) ?>
        </div>
        <dl class="grid grid-cols-[auto_1fr] gap-x-space-4 gap-y-space-2 text-body">
            <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldStart')) ?></dt>
            <dd class="tabular-nums"><?= esc($data($reserva['data_hora_inicio'])) ?></dd>
            <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldEnd')) ?></dt>
            <dd class="tabular-nums"><?= esc($data($reserva['data_hora_fim'])) ?></dd>
            <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldRequester')) ?></dt>
            <dd><?= esc($reserva['solicitante']) ?></dd>
            <?php if ($reserva['beneficiario'] !== null) : ?>
                <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldFor')) ?></dt>
                <dd><?= esc($reserva['beneficiario']) ?></dd>
            <?php endif ?>
            <?php if ($reserva['confirmado_por'] !== null) : ?>
                <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldConfirmedBy')) ?></dt>
                <dd><?= esc($reserva['confirmado_por']) ?> · <span class="tabular-nums"><?= esc($data($reserva['data_confirmacao'])) ?></span></dd>
            <?php endif ?>
            <?php if ($status === 'cancelada') : ?>
                <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldCancelledBy')) ?></dt>
                <dd><?= esc($reserva['cancelado_por']) ?> · <span class="tabular-nums"><?= esc($data($reserva['data_cancelamento'])) ?></span></dd>
                <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldJustification')) ?></dt>
                <dd class="whitespace-pre-line"><?= esc($reserva['justificativa_cancelamento']) ?></dd>
            <?php endif ?>
        </dl>
    </section>

    <?php if ($serie !== []) : ?>
        <section aria-labelledby="reserva-serie" class="mb-space-8 max-w-3xl">
            <h2 id="reserva-serie" class="mb-space-4 text-title"><?= esc(lang('Agendamento.seriesOccurrences', [count($serie)])) ?></h2>
            <ol class="rounded-md border border-border bg-surface shadow-xs">
                <?php foreach ($serie as $ocorrencia) : ?>
                    <?php $atual = (int) $ocorrencia['id_reserva_pontual'] === $id; ?>
                    <li class="flex flex-wrap items-center justify-between gap-space-3 border-b border-border px-space-4 py-space-2 last:border-b-0<?= $atual ? ' bg-paper' : '' ?>">
                        <a href="<?= site_url('agendamento/reservas/' . $ocorrencia['id_reserva_pontual']) ?>"<?= $atual ? ' aria-current="page"' : '' ?>
                           class="inline-flex min-h-11 items-center tabular-nums underline"><?= esc($data($ocorrencia['data_hora_inicio'])) ?></a>
                        <?= $ponto((string) $ocorrencia['status']) ?>
                    </li>
                <?php endforeach ?>
            </ol>
        </section>
    <?php endif ?>

    <?php if ($extras['itens'] !== [] || $extras['softwares'] !== []) : ?>
        <section aria-labelledby="reserva-extras" class="max-w-3xl">
            <h2 id="reserva-extras" class="mb-space-4 text-title"><?= esc(lang('Agendamento.extras')) ?></h2>
            <ul class="rounded-md border border-border bg-surface shadow-xs">
                <?php foreach ($extras['itens'] as $item) : ?>
                    <li class="border-b border-border px-space-4 py-space-4 last:border-b-0">
                        <div class="mb-space-2 flex flex-wrap items-center justify-between gap-space-3">
                            <span class="text-body-strong"><?= esc($item['quantidade'] . ' × ' . $item['categoria']) ?></span>
                            <?= ui_status_dot($tomExtra((string) $item['status']), lang('Agendamento.extraStatus_' . $item['status'])) ?>
                        </div>
                        <?php if ($item['decidido_por'] !== null) : ?>
                            <p class="text-data text-ink-muted"><?= esc($item['decidido_por']) ?><?= $item['justificativa_decisao'] !== null ? ': ' . esc($item['justificativa_decisao']) : '' ?></p>
                        <?php endif ?>
                        <?php if ($item['status'] === 'deferido') : ?>
                            <p class="text-data text-ink-muted tabular-nums">
                                <?= $item['devolucao_confirmada_em'] !== null
                                    ? esc(lang('Agendamento.returnedAt', [$data($item['devolucao_confirmada_em']), (string) $item['devolucao_por']]))
                                    : esc(lang('Agendamento.returnUntil', [$data($item['data_limite_devolucao'])])) ?>
                            </p>
                        <?php endif ?>
                        <?php if ($podeExtras && $item['status'] === 'pendente') : ?>
                            <?= view('agendamento/reservas/_decidir_extra', ['acao' => site_url('agendamento/extras/itens/' . $item['id_reserva_item_extra']), 'chave' => 'item-' . $item['id_reserva_item_extra']], ['saveData' => false]) ?>
                        <?php elseif ($podeExtras && $item['status'] === 'deferido' && $item['devolucao_confirmada_em'] === null) : ?>
                            <form method="post" action="<?= site_url('agendamento/extras/itens/' . $item['id_reserva_item_extra'] . '/devolucao') ?>" class="mt-space-2">
                                <?= csrf_field() ?>
                                <?= ui_button(lang('Agendamento.confirmReturn'), 'secondary', ['type' => 'submit']) ?>
                            </form>
                        <?php endif ?>
                    </li>
                <?php endforeach ?>
                <?php foreach ($extras['softwares'] as $software) : ?>
                    <li class="border-b border-border px-space-4 py-space-4 last:border-b-0">
                        <div class="mb-space-2 flex flex-wrap items-center justify-between gap-space-3">
                            <span class="text-body-strong"><?= esc(trim($software['software'] . ' ' . ($software['versao'] ?? ''))) ?></span>
                            <?= ui_status_dot($tomExtra((string) $software['status']), lang('Agendamento.extraStatus_' . $software['status'])) ?>
                        </div>
                        <?php if ($software['decidido_por'] !== null) : ?>
                            <p class="text-data text-ink-muted"><?= esc($software['decidido_por']) ?><?= $software['justificativa_decisao'] !== null ? ': ' . esc($software['justificativa_decisao']) : '' ?></p>
                        <?php endif ?>
                        <?php if ($podeExtras && $software['status'] === 'pendente') : ?>
                            <?= view('agendamento/reservas/_decidir_extra', ['acao' => site_url('agendamento/extras/softwares/' . $software['id_reserva_software_extra']), 'chave' => 'software-' . $software['id_reserva_software_extra']], ['saveData' => false]) ?>
                        <?php endif ?>
                    </li>
                <?php endforeach ?>
            </ul>
        </section>
    <?php endif ?>

    <?php if ($temModal) : ?>
        <?php /* Salvou no modal: a reserva mudou, a página recarrega (a mensagem vem da sessão). */ ?>
        <div x-data @tabela-recarregar.window="window.location.reload()">
            <?= view('components/tabela/formulario_modal', [], ['saveData' => false]) ?>
        </div>
    <?php endif ?>
<?= $this->endSection() ?>

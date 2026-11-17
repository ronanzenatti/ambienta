<?php

/**
 * Página da alocação semestral: dados, pendências de requisito do professor (só sinalizam), dias em que a
 * alocação foi excepcionada (conflito autorizado, bloqueio) e as ações Cancelar e Excluir (CONFIRMO).
 *
 * @var array<string, mixed>       $alocacao
 * @var string                     $ambiente
 * @var list<array<string, mixed>> $excecoes
 * @var array{nivel: string, pendencias: list<array<string, mixed>>} $atendimento
 * @var int|null                   $alerta     duração longa (6, 12 ou 24 horas)
 * @var bool                       $podeGerir
 */

use App\Libraries\Agendamento\Alocacoes;
use App\Libraries\Chamados\Atendimento;
use App\Tabelas\Agendamento\AlocacoesTabela;

$id      = (int) $alocacao['id_alocacao_semestral'];
$status  = (string) $alocacao['status'];
$titulo  = $alocacao['sigla'] . ' — ' . $alocacao['disciplina'];
$formato = lang('Institucional.dateFormat');
$horario = substr((string) $alocacao['hora_inicio'], 0, 5) . '–' . substr((string) $alocacao['hora_fim'], 0, 5);
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [[lang('Agendamento.allocationsTitle'), site_url('agendamento/alocacoes')], [$titulo, null]]], ['saveData' => false]) ?>

    <div class="mb-space-6 flex max-w-3xl flex-wrap items-start justify-between gap-space-4">
        <h1 class="text-display"><?= esc($titulo) ?></h1>
        <?php if ($podeGerir) : ?>
            <div class="flex flex-wrap gap-space-2">
                <?php if ($status !== 'cancelada') : ?>
                    <?= ui_button(lang('Agendamento.cancelAllocation'), 'secondary', ['@click' => "\$dispatch('open-modal', 'cancelar-alocacao')"]) ?>
                <?php endif ?>
                <?= ui_button(lang('Ui.delete'), 'delete', ['@click' => "\$dispatch('open-modal', 'excluir-alocacao')"]) ?>
            </div>
        <?php endif ?>
    </div>
    <?php if ($podeGerir) : ?>
        <?php if ($status !== 'cancelada') : ?>
            <?= ui_confirm_delete('cancelar-alocacao', lang('Agendamento.allocationCancelText', [$titulo]), site_url("agendamento/alocacoes/{$id}/cancelar"), lang('Agendamento.cancelAllocation'), lang('Agendamento.cancelAllocation')) ?>
        <?php endif ?>
        <?= ui_confirm_delete('excluir-alocacao', lang('Agendamento.allocationDeleteText', [$titulo]), site_url("agendamento/alocacoes/{$id}"), lang('Agendamento.allocationDeleteTitle')) ?>
    <?php endif ?>

    <?php if ($alerta !== null) : ?>
        <?= ui_alert('pending', lang('Agendamento.longDuration', [$alerta])) ?>
    <?php endif ?>

    <?php if ($alocacao['observacao_critica'] !== null) : ?>
        <?= ui_alert('critical', lang('Agendamento.criticalNote', [(string) $alocacao['observacao_critica']])) ?>
    <?php endif ?>

    <section aria-labelledby="alocacao-dados" class="mb-space-8 max-w-3xl rounded-md border border-border bg-surface p-space-6 shadow-xs">
        <div class="mb-space-4 flex flex-wrap items-start justify-between gap-space-4">
            <h2 id="alocacao-dados" class="text-heading"><?= esc($ambiente) ?></h2>
            <?= ui_status_dot(AlocacoesTabela::tom($status), lang('Agendamento.allocationStatus_' . $status)) ?>
        </div>
        <dl class="grid grid-cols-[auto_1fr] gap-x-space-4 gap-y-space-2 text-body">
            <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldSemester')) ?></dt>
            <dd><?= esc($alocacao['semestre']) ?> <span class="tabular-nums text-ink-muted">(<?= esc(date($formato, (int) strtotime((string) $alocacao['data_inicio'])) . ' – ' . date($formato, (int) strtotime((string) $alocacao['data_fim']))) ?>)</span></dd>
            <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldCourse')) ?></dt>
            <dd><?= esc($alocacao['curso']) ?></dd>
            <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldTeacher')) ?></dt>
            <dd><?= esc($alocacao['professor']) ?></dd>
            <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldWeekday')) ?></dt>
            <dd><?= esc(lang('Agendamento.weekday_' . $alocacao['dia_semana'])) ?> · <span class="tabular-nums"><?= esc($horario) ?></span></dd>
            <?php if ($alocacao['ciencia_conflito_horario_em'] !== null) : ?>
                <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldAwareness')) ?></dt>
                <dd class="tabular-nums"><?= esc(date($formato . ' H:i', (int) strtotime((string) $alocacao['ciencia_conflito_horario_em']))) ?></dd>
            <?php endif ?>
        </dl>
    </section>

    <section aria-labelledby="alocacao-requisitos" class="mb-space-8 max-w-3xl">
        <h2 id="alocacao-requisitos" class="mb-space-4 text-title"><?= esc(lang('Agendamento.requirementsTitle')) ?></h2>
        <p class="mb-space-4 text-body"><?= ui_status_dot(Atendimento::tom($atendimento['nivel']), Atendimento::rotulo($atendimento['nivel'])) ?></p>
        <?php $pendencias = $atendimento['pendencias']; ?>
        <?php if ($pendencias === []) : ?>
            <?= ui_alert('confirmed', lang('Agendamento.requirementsOk')) ?>
        <?php else : ?>
            <?= ui_alert('pending', lang('Agendamento.requirementsPending')) ?>
            <ul class="rounded-md border border-border bg-surface shadow-xs">
                <?php foreach ($pendencias as $pendencia) : ?>
                    <li class="border-b border-border px-space-4 py-space-3 last:border-b-0"><?= ui_status_dot('pending', Alocacoes::textoPendencia($pendencia)) ?></li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </section>

    <section aria-labelledby="alocacao-excecoes" class="max-w-3xl">
        <h2 id="alocacao-excecoes" class="mb-space-4 text-title"><?= esc(lang('Agendamento.exceptionsTitle')) ?></h2>
        <?php if ($excecoes === []) : ?>
            <p class="text-body text-ink-muted"><?= esc(lang('Agendamento.exceptionsEmpty')) ?></p>
        <?php else : ?>
            <ul class="rounded-md border border-border bg-surface shadow-xs">
                <?php foreach ($excecoes as $excecao) : ?>
                    <li class="border-b border-border px-space-4 py-space-3 last:border-b-0">
                        <span class="text-body-strong tabular-nums"><?= esc(date($formato, (int) strtotime((string) $excecao['data_excecao']))) ?></span>
                        · <?= esc(lang('Agendamento.exception_' . $excecao['motivo'])) ?>
                        <?php if ($excecao['reserva_pontual_id'] !== null) : ?>
                            · <a class="underline" href="<?= site_url('agendamento/reservas/' . $excecao['reserva_pontual_id']) ?>"><?= esc(lang('Agendamento.seeReservation')) ?></a>
                        <?php endif ?>
                        <?php if ($excecao['observacao'] !== null) : ?>
                            <p class="text-data text-ink-muted"><?= esc($excecao['observacao']) ?></p>
                        <?php endif ?>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </section>
<?= $this->endSection() ?>

<?php

/**
 * Agenda da semana de um ambiente ("Agenda com conflito de horário"): um cartão por dia com alocações,
 * reservas e bloqueios em ordem de horário. Conflito reserva × alocação ainda não decidido aparece nos dois
 * lados com signal-pending (decisão pendente, não erro) e, para quem pode decidir, Autorizar/Negar.
 *
 * @var int                               $ambienteId  0 = nenhum escolhido
 * @var DateTimeImmutable                 $segunda
 * @var list<array<string, mixed>>        $dias        ver App\Libraries\Agendamento\Agenda::semana()
 * @var bool                              $podeDecidir
 * @var bool                              $podeReservar
 */

use App\Libraries\Agendamento\Agenda;

$titulo  = lang('Agendamento.agendaTitle');
$formato = lang('Institucional.dateFormat');
$semana  = static fn (string $passo): string => site_url('agendamento/agenda') . '?' . http_build_query([
    'ambiente' => $ambienteId,
    'semana'   => $segunda->modify($passo)->format('Y-m-d'),
]);
$voltar  = '/agendamento/agenda?' . http_build_query(['ambiente' => $ambienteId, 'semana' => $segunda->format('Y-m-d')]);
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <div class="mb-space-6">
        <h1 class="mb-space-2 text-display"><?= esc($titulo) ?></h1>
        <p class="max-w-2xl text-body text-ink-muted"><?= esc(lang('Agendamento.agendaIntro')) ?></p>
    </div>

    <form method="get" action="<?= site_url('agendamento/agenda') ?>" class="mb-space-6 flex flex-wrap items-end gap-x-space-4 rounded-md border border-border bg-surface p-space-4 shadow-xs">
        <div class="min-w-64 flex-1">
            <?= ui_select_fonte('ambiente', lang('Agendamento.fieldEnvironment'), 'ambientes', ['chave' => 'agendamento.acesso'], $ambienteId === 0 ? null : $ambienteId, lang('Ui.select'), null, null, null, ['required' => true]) ?>
        </div>
        <div>
            <?= ui_field('semana', lang('Agendamento.fieldWeekOf'), 'date', $segunda->format('Y-m-d')) ?>
        </div>
        <div class="mb-space-4"><?= ui_button(lang('Agendamento.showAgenda'), 'secondary', ['type' => 'submit']) ?></div>
    </form>

    <?php if ($ambienteId !== 0) : ?>
        <div class="mb-space-4 flex flex-wrap items-center justify-between gap-space-4">
            <h2 class="text-title"><?= esc($ambientes[$ambienteId]) ?></h2>
            <nav aria-label="<?= esc(lang('Agendamento.weekNav'), 'attr') ?>" class="flex flex-wrap items-center gap-space-2">
                <?= ui_button(lang('Agendamento.previousWeek'), 'secondary', ['href' => $semana('-7 days')]) ?>
                <span class="text-body tabular-nums"><?= esc($segunda->format($formato) . ' – ' . $segunda->modify('+6 days')->format($formato)) ?></span>
                <?= ui_button(lang('Agendamento.nextWeek'), 'secondary', ['href' => $semana('+7 days')]) ?>
                <?php if ($podeReservar) : ?>
                    <?= ui_button(lang('Agendamento.reservationNew'), 'primary', ['href' => site_url('agendamento/reservas/novo') . '?ambiente=' . $ambienteId]) ?>
                <?php endif ?>
            </nav>
        </div>

        <ol class="grid gap-space-4 md:grid-cols-2 xl:grid-cols-4">
            <?php foreach ($dias as $dia) : ?>
                <?php $dataDia = $dia['data']; ?>
                <li class="rounded-md border border-border bg-surface shadow-xs">
                    <h3 class="border-b border-border px-space-4 py-space-3 text-heading">
                        <?= esc(lang('Agendamento.weekday_' . \App\Libraries\Agendamento\Alocacoes::diaDaSemana($dataDia))) ?>
                        <span class="text-body tabular-nums text-ink-muted"><?= esc($dataDia->format($formato)) ?></span>
                    </h3>
                    <?php if ($dia['feriado'] !== null) : ?>
                        <p class="border-b border-border px-space-4 py-space-2 text-data"><?= ui_status_dot('neutral', lang('Agendamento.holidayLabel', [(string) $dia['feriado']])) ?></p>
                    <?php endif ?>
                    <?php if ($dia['eventos'] === []) : ?>
                        <p class="px-space-4 py-space-3 text-data text-ink-muted"><?= esc(lang('Agendamento.dayFree')) ?></p>
                    <?php else : ?>
                        <ul>
                            <?php foreach ($dia['eventos'] as $evento) : ?>
                                <?php $emConflito = $evento['conflito_id'] !== null; ?>
                                <li class="border-b border-border px-space-4 py-space-3 last:border-b-0<?= $emConflito ? ' bg-signal-pending/5' : '' ?>">
                                    <p class="text-data tabular-nums text-ink-muted"><?= esc($evento['inicio'] . '–' . $evento['fim']) ?> · <?= esc(lang('Agendamento.eventType_' . $evento['tipo'])) ?></p>
                                    <p class="text-body-strong">
                                        <?php if ($evento['tipo'] === 'reserva') : ?>
                                            <a class="underline" href="<?= site_url('agendamento/reservas/' . $evento['id']) ?>"><?= esc($evento['titulo']) ?></a>
                                        <?php elseif ($evento['tipo'] === 'alocacao') : ?>
                                            <a class="underline" href="<?= site_url('agendamento/alocacoes/' . $evento['id']) ?>"><?= esc($evento['titulo']) ?></a>
                                        <?php else : ?>
                                            <?= esc($evento['titulo']) ?>
                                        <?php endif ?>
                                    </p>
                                    <p class="mb-space-1 text-data text-ink-muted"><?= esc($evento['detalhe']) ?></p>
                                    <?php if ($evento['excecao'] !== null) : ?>
                                        <?= ui_status_dot('neutral', lang('Agendamento.exception_' . $evento['excecao'])) ?>
                                    <?php else : ?>
                                        <?= ui_status_dot(Agenda::tom($evento['status']), lang('Agendamento.eventStatus_' . $evento['status'])) ?>
                                    <?php endif ?>
                                    <?php if ($emConflito && $podeDecidir && $evento['tipo'] === 'reserva') : ?>
                                        <div class="mt-space-2 flex flex-wrap gap-space-2">
                                            <form method="post" action="<?= site_url('agendamento/conflitos/' . $evento['conflito_id'] . '/autorizar') ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="voltar" value="<?= esc($voltar, 'attr') ?>">
                                                <?= ui_button(lang('Agendamento.authorize'), 'primary', ['type' => 'submit', 'aria-label' => lang('Agendamento.authorize') . ': ' . $evento['titulo']]) ?>
                                            </form>
                                            <?= ui_button(lang('Agendamento.deny'), 'secondary', [
                                                'href'       => site_url('agendamento/conflitos/' . $evento['conflito_id'] . '/negar') . '?voltar=' . rawurlencode($voltar),
                                                '@click'     => 'ambientaAbrirFormulario($event)',
                                                'aria-label' => lang('Agendamento.deny') . ': ' . $evento['titulo'],
                                            ]) ?>
                                        </div>
                                    <?php endif ?>
                                </li>
                            <?php endforeach ?>
                        </ul>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ol>

        <?php if ($podeDecidir) : ?>
            <div x-data @tabela-recarregar.window="window.location.reload()">
                <?= view('components/tabela/formulario_modal', [], ['saveData' => false]) ?>
            </div>
        <?php endif ?>
    <?php endif ?>
<?= $this->endSection() ?>

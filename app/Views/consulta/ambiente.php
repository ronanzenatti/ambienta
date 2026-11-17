<?php

/**
 * Agenda pública de um ambiente (destino do QR Code da porta), sem login e pensada primeiro para celular:
 * um bloco por dia, eventos em ordem de horário. Só o recorte público (ver Agenda::semana() modo público).
 *
 * @var array<string, mixed>       $ambiente numero, nome, ativo, predio, campus
 * @var DateTimeImmutable          $segunda
 * @var list<array<string, mixed>> $dias
 */

use App\Libraries\Agendamento\Agenda;
use App\Libraries\Agendamento\Alocacoes;

$id      = (int) $ambiente['id_ambiente'];
$titulo  = $ambiente['numero'] . ' — ' . $ambiente['nome'];
$formato = lang('Institucional.dateFormat');
$semana  = static fn (string $passo): string => site_url('agenda/ambiente/' . $id) . '?semana=' . $segunda->modify($passo)->format('Y-m-d');
$hoje    = (new DateTimeImmutable('today'))->format('Y-m-d');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc(lang('Consulta.agendaTitle', [$titulo])) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <div class="mb-space-6">
        <div class="mb-space-2"><?= ui_placa_ambiente((string) $ambiente['numero'], (string) $ambiente['nome']) ?></div>
        <h1 class="mb-space-1 text-display"><?= esc(lang('Consulta.agendaHeading')) ?></h1>
        <p class="text-body text-ink-muted"><?= esc($ambiente['predio'] . ' · ' . $ambiente['campus']) ?></p>
    </div>

    <?php if (! (bool) $ambiente['ativo']) : ?>
        <?= ui_alert('pending', lang('Consulta.agendaInactive')) ?>
    <?php endif ?>

    <nav aria-label="<?= esc(lang('Agendamento.weekNav'), 'attr') ?>" class="mb-space-4 flex flex-wrap items-center gap-space-2">
        <?= ui_button(lang('Agendamento.previousWeek'), 'secondary', ['href' => $semana('-7 days')]) ?>
        <span class="text-body tabular-nums"><?= esc($segunda->format($formato) . ' – ' . $segunda->modify('+6 days')->format($formato)) ?></span>
        <?= ui_button(lang('Agendamento.nextWeek'), 'secondary', ['href' => $semana('+7 days')]) ?>
    </nav>

    <ol class="mb-space-8 grid gap-space-4 md:grid-cols-2 xl:grid-cols-4">
        <?php foreach ($dias as $dia) : ?>
            <?php $dataDia = $dia['data']; ?>
            <li class="rounded-md border border-border bg-surface shadow-xs"<?= $dataDia->format('Y-m-d') === $hoje ? ' aria-current="date"' : '' ?>>
                <h2 class="border-b border-border px-space-4 py-space-3 text-heading">
                    <?= esc(lang('Agendamento.weekday_' . Alocacoes::diaDaSemana($dataDia))) ?>
                    <span class="text-body tabular-nums text-ink-muted"><?= esc($dataDia->format($formato)) ?></span>
                    <?php if ($dataDia->format('Y-m-d') === $hoje) : ?>
                        <span class="text-body text-ink-muted">· <?= esc(lang('Consulta.today')) ?></span>
                    <?php endif ?>
                </h2>
                <?php if ($dia['feriado'] !== null) : ?>
                    <p class="border-b border-border px-space-4 py-space-2 text-data"><?= ui_status_dot('neutral', lang('Agendamento.holidayLabel', [(string) $dia['feriado']])) ?></p>
                <?php endif ?>
                <?php if ($dia['eventos'] === []) : ?>
                    <p class="px-space-4 py-space-3 text-data text-ink-muted"><?= esc(lang('Agendamento.dayFree')) ?></p>
                <?php else : ?>
                    <ul>
                        <?php foreach ($dia['eventos'] as $evento) : ?>
                            <li class="border-b border-border px-space-4 py-space-3 last:border-b-0">
                                <p class="text-data tabular-nums text-ink-muted"><?= esc($evento['inicio'] . '–' . $evento['fim']) ?></p>
                                <p class="text-body-strong"><?= esc($evento['titulo']) ?></p>
                                <?php if ($evento['detalhe'] !== '') : ?>
                                    <p class="mb-space-1 text-data text-ink-muted"><?= esc($evento['detalhe']) ?></p>
                                <?php endif ?>
                                <?php if ($evento['excecao'] !== null) : ?>
                                    <?= ui_status_dot('neutral', lang('Consulta.agendaNotHere')) ?>
                                <?php else : ?>
                                    <?= ui_status_dot(Agenda::tom($evento['status']), lang('Agendamento.eventType_' . $evento['tipo'])) ?>
                                <?php endif ?>
                            </li>
                        <?php endforeach ?>
                    </ul>
                <?php endif ?>
            </li>
        <?php endforeach ?>
    </ol>

    <section aria-labelledby="agenda-assinar" class="max-w-2xl rounded-md border border-border bg-surface p-space-4 shadow-xs sm:p-space-6">
        <h2 id="agenda-assinar" class="mb-space-2 text-title"><?= esc(lang('Agendamento.icalSubscribe')) ?></h2>
        <p class="mb-space-4 text-body text-ink-muted"><?= esc(lang('Agendamento.icalAmbientHelp')) ?></p>
        <?= ui_button(lang('Agendamento.icalDownload'), 'secondary', ['href' => site_url('agenda/ambiente/' . $id . '/agenda.ics')]) ?>
    </section>
<?= $this->endSection() ?>

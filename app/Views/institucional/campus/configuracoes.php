<?php

/**
 * Configurações do campus (página própria: muitos campos). Campos numéricos vazios = sem limite; horários de
 * corte vazios = o turno não é usado nos filtros.
 *
 * @var array<string, mixed>  $campus
 * @var array<string, mixed>  $config
 * @var array<string, string> $erros
 */
$id     = (int) $campus['id_campus'];
$nome   = (string) $campus['nome'];
$titulo = lang('Agendamento.settingsTitle');
$valor  = static fn (string $campo): string => $config[$campo] === null ? '' : (string) $config[$campo];
$hora   = static fn (string $campo): string => $config[$campo] === null ? '' : substr((string) $config[$campo], 0, 5);
$marcado = static fn (string $campo): bool => (bool) ($config[$campo] ?? false);
$numero  = static fn (string $campo, string $ajuda) => ui_field($campo, lang('Agendamento.setting_' . $campo), 'number', $valor($campo), $erros[$campo] ?? null, lang($ajuda), ['min' => '0', 'inputmode' => 'numeric']);
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo . ' · ' . $nome) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [
        [lang('Campus.title'), site_url('campus')],
        [campus_rotulo($campus), site_url("campus/{$id}")],
        [$titulo, null],
    ]], ['saveData' => false]) ?>

    <h1 class="mb-space-2 text-display"><?= esc($titulo) ?></h1>
    <p class="mb-space-6 text-body text-ink-muted"><?= esc($nome) ?></p>

    <form method="post" action="<?= site_url("campus/{$id}/configuracoes") ?>" class="w-full">
        <?= csrf_field() ?>

        <fieldset class="mb-space-6 rounded-md border border-border bg-surface p-space-6 shadow-xs">
            <legend class="px-space-1 text-heading"><?= esc(lang('Agendamento.settingsReservations')) ?></legend>
            <div class="grid gap-x-space-8 lg:grid-cols-2">
                <div>
                    <?= ui_checkbox('exige_confirmacao_reserva', lang('Agendamento.setting_exige_confirmacao_reserva'), $marcado('exige_confirmacao_reserva')) ?>
                    <?= ui_checkbox('fila_confirmacao_ativa', lang('Agendamento.setting_fila_confirmacao_ativa'), $marcado('fila_confirmacao_ativa')) ?>
                    <?= ui_checkbox('confirmador_pode_confirmar_propria', lang('Agendamento.setting_confirmador_pode_confirmar_propria'), $marcado('confirmador_pode_confirmar_propria')) ?>
                    <?= ui_checkbox('permite_agendamento_feriado', lang('Agendamento.setting_permite_agendamento_feriado'), $marcado('permite_agendamento_feriado')) ?>
                </div>
                <div>
                    <p class="mb-space-4 text-caption text-ink-muted"><?= esc(lang('Agendamento.settingsOverrideHelp')) ?></p>
                    <?= $numero('tempo_minimo_antecedencia_reserva_minutos', 'Agendamento.minutesHelp') ?>
                    <?= $numero('tempo_maximo_antecedencia_reserva_dias', 'Agendamento.daysHelp') ?>
                    <?= $numero('tempo_minimo_cancelamento_minutos', 'Agendamento.minutesHelp') ?>
                </div>
            </div>
        </fieldset>

        <div class="mb-space-6 grid gap-space-6 lg:grid-cols-2">
            <fieldset class="rounded-md border border-border bg-surface p-space-6 shadow-xs">
                <legend class="px-space-1 text-heading"><?= esc(lang('Agendamento.settingsShifts')) ?></legend>
                <p class="mb-space-4 text-caption text-ink-muted"><?= esc(lang('Agendamento.settingsShiftsHelp')) ?></p>
                <div class="grid gap-x-space-4 sm:grid-cols-3">
                    <?php foreach (['horario_fim_manha', 'horario_fim_tarde', 'horario_fim_noite'] as $campo) : ?>
                        <?= ui_field($campo, lang('Agendamento.setting_' . $campo), 'time', $hora($campo), $erros[$campo] ?? null) ?>
                    <?php endforeach ?>
                </div>
            </fieldset>

            <fieldset class="rounded-md border border-border bg-surface p-space-6 shadow-xs">
                <legend class="px-space-1 text-heading"><?= esc(lang('Agendamento.settingsAssets')) ?></legend>
                <?= $numero('prazo_resposta_movimentacao_horas', 'Agendamento.hoursHelp') ?>
                <?= $numero('prazo_resposta_devolucao_horas', 'Agendamento.hoursHelp') ?>
            </fieldset>
        </div>

        <div class="flex flex-wrap gap-space-3">
            <?= ui_button(lang('Ui.save'), 'edit', ['type' => 'submit']) ?>
            <?= ui_button(lang('Ui.cancel'), 'secondary', ['href' => site_url("campus/{$id}")]) ?>
        </div>
    </form>
<?= $this->endSection() ?>

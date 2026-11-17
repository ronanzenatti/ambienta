<?php

/**
 * Alteração da reserva: título e horário (no mesmo dia de cada ocorrência); numa série, só esta, esta e as
 * seguintes ou a série toda. Horário novo passa de novo pela checagem de disponibilidade.
 *
 * @var array<string, mixed>  $reserva
 * @var array<string, mixed>  $dados
 * @var array<string, string> $erros
 * @var bool                  $modal
 */
$id     = (int) $reserva['id_reserva_pontual'];
$titulo = lang('Agendamento.editTitle');
?>
<form <?= ui_form_attrs(site_url("agendamento/reservas/{$id}/alterar"), $titulo, $modal) ?>>
    <?= csrf_field() ?>
    <?php if ($reserva['recorrencia_id'] !== null) : ?>
        <?= ui_select('escopo', lang('Agendamento.fieldSeriesScope'), opcoes_traduzidas('Agendamento.seriesScope_', \App\Libraries\Agendamento\Reservas::ESCOPOS), (string) ($dados['escopo'] ?? 'esta'), $erros['escopo'] ?? null, lang('Agendamento.seriesScopeHelp')) ?>
    <?php endif ?>
    <?= ui_field('titulo', lang('Agendamento.fieldTitle'), 'text', (string) ($dados['titulo'] ?? ''), $erros['titulo'] ?? null, null, ['required' => true, 'maxlength' => '255']) ?>
    <div class="grid gap-x-space-4 sm:grid-cols-2">
        <?= ui_field('hora_inicio', lang('Agendamento.fieldStartTime'), 'time', (string) ($dados['hora_inicio'] ?? ''), $erros['hora_inicio'] ?? null, null, ['required' => true]) ?>
        <?= ui_field('hora_fim', lang('Agendamento.fieldEndTime'), 'time', (string) ($dados['hora_fim'] ?? ''), $erros['hora_fim'] ?? null, null, ['required' => true]) ?>
    </div>
    <?= ui_textarea('justificativa', lang('Agendamento.fieldEditReason'), (string) ($dados['justificativa'] ?? ''), $erros['justificativa'] ?? null, lang('Agendamento.editReasonHelp'), 2, ['maxlength' => '5000']) ?>
    <?= ui_form_buttons(false, site_url("agendamento/reservas/{$id}"), $modal) ?>
</form>

<?php

/**
 * Cancelamento da reserva, com justificativa obrigatória; numa série, escolhe só esta, esta e as seguintes ou
 * a série toda. Na página (form.php) ou no modal da página da reserva ($modal).
 *
 * @var array<string, mixed>  $reserva
 * @var array<string, mixed>  $dados
 * @var array<string, string> $erros
 * @var bool                  $modal
 */
$id     = (int) $reserva['id_reserva_pontual'];
$titulo = lang('Agendamento.cancelTitle');
?>
<form <?= ui_form_attrs(site_url("agendamento/reservas/{$id}/cancelar"), $titulo, $modal) ?>>
    <?= csrf_field() ?>
    <p class="mb-space-4 text-body"><span class="text-body-strong"><?= esc($reserva['titulo']) ?></span>
        · <span class="tabular-nums"><?= esc(date(lang('Institucional.dateFormat') . ' H:i', (int) strtotime((string) $reserva['data_hora_inicio']))) ?></span></p>
    <?php if ($reserva['recorrencia_id'] !== null) : ?>
        <?= ui_select('escopo', lang('Agendamento.fieldSeriesScope'), opcoes_traduzidas('Agendamento.seriesScope_', \App\Libraries\Agendamento\Reservas::ESCOPOS), (string) ($dados['escopo'] ?? 'esta'), $erros['escopo'] ?? null, lang('Agendamento.seriesScopeHelp')) ?>
    <?php endif ?>
    <?= ui_textarea('justificativa', lang('Agendamento.fieldJustification'), (string) ($dados['justificativa'] ?? ''), $erros['justificativa'] ?? null, lang('Agendamento.justificationHelp'), 4, ['required' => true, 'maxlength' => '5000']) ?>
    <?php
    $voltar   = ui_button(lang('Ui.close'), 'secondary', $modal ? ['@click' => 'fechar()'] : ['href' => site_url("agendamento/reservas/{$id}")]);
    $cancelar = ui_button(lang('Agendamento.cancelReservation'), 'primary', ['type' => 'submit', ':disabled' => $modal ? 'enviando' : null]);
    ?>
    <div class="flex flex-wrap justify-end gap-space-2"><?= $voltar . $cancelar ?></div>
</form>

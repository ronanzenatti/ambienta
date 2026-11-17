<?php

/**
 * Cadastro de bloqueio de ambiente: na página (form.php) ou no modal da listagem ($modal). O bloqueio tem
 * prioridade: cancela as reservas do período e libera as alocações semestrais nos dias afetados.
 *
 * @var array<string, mixed>  $dados
 * @var array<string, string> $erros
 * @var bool                  $modal
 */
$titulo = lang('Agendamento.blockNew');
?>
<form <?= ui_form_attrs(site_url('agendamento/bloqueios'), $titulo, $modal) ?>>
    <?= csrf_field() ?>
    <?= ui_alert('pending', lang('Agendamento.blockWarning')) ?>
    <?= ui_select_fonte('ambiente_id', lang('Agendamento.fieldEnvironment'), 'ambientes', ['chave' => 'institucional.acesso', 'nivel' => 'editor'], $dados['ambiente_id'] ?? null, lang('Ui.select'), $erros['ambiente_id'] ?? null, null, null, ['required' => true]) ?>
    <?= ui_select('tipo', lang('Agendamento.fieldBlockType'), ['' => lang('Ui.select')] + opcoes_traduzidas('Agendamento.blockType_', \App\Libraries\Agendamento\Bloqueios::TIPOS), (string) ($dados['tipo'] ?? ''), $erros['tipo'] ?? null, null, ['required' => true]) ?>
    <?= ui_textarea('motivo', lang('Agendamento.fieldReason'), (string) ($dados['motivo'] ?? ''), $erros['motivo'] ?? null, lang('Agendamento.reasonHelp'), 3, ['required' => true, 'maxlength' => '5000']) ?>
    <div class="grid gap-x-space-4 sm:grid-cols-2">
        <?= ui_field('inicio', lang('Agendamento.fieldStart'), 'datetime-local', (string) ($dados['inicio'] ?? ''), $erros['inicio'] ?? null, null, ['required' => true]) ?>
        <?= ui_field('fim', lang('Agendamento.fieldEnd'), 'datetime-local', (string) ($dados['fim'] ?? ''), $erros['fim'] ?? null, null, ['required' => true]) ?>
    </div>
    <?= ui_form_buttons(true, site_url('agendamento/bloqueios'), $modal) ?>
</form>

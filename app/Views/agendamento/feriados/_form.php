<?php

/**
 * Cadastro/alteração manual de feriado: na página (form.php) ou no modal da listagem ($modal).
 *
 * @var array<string, mixed>  $dados
 * @var bool                  $todos o superperfil pode deixar o campus vazio (todos os campi)
 * @var array<string, string> $erros
 * @var bool                  $modal
 */
$id     = isset($dados['id_feriado']) ? (int) $dados['id_feriado'] : null;
$titulo = lang($id === null ? 'Agendamento.holidayNew' : 'Agendamento.holidayEdit');
$acao   = site_url($id === null ? 'agendamento/feriados' : "agendamento/feriados/{$id}");
?>
<form <?= ui_form_attrs($acao, $titulo, $modal) ?>>
    <?= csrf_field() ?>
    <?= ui_field('data_feriado', lang('Agendamento.fieldDate'), 'date', (string) ($dados['data_feriado'] ?? ''), $erros['data_feriado'] ?? null, null, ['required' => true]) ?>
    <?= ui_field('descricao', lang('Agendamento.fieldDescription'), 'text', (string) ($dados['descricao'] ?? ''), $erros['descricao'] ?? null, null, ['required' => true, 'maxlength' => '255']) ?>
    <?= ui_select('abrangencia', lang('Agendamento.fieldScope'), ['' => lang('Ui.select')] + opcoes_traduzidas('Agendamento.scope_', \App\Libraries\Agendamento\Feriados::ABRANGENCIAS), (string) ($dados['abrangencia'] ?? ''), $erros['abrangencia'] ?? null, null, ['required' => true]) ?>
    <?= ui_select_fonte('campus_id', lang('Agendamento.fieldCampus'), 'campi', ['chave' => 'institucional.acesso', 'nivel' => 'editor'], $dados['campus_id'] ?? null, lang($todos ? 'Agendamento.allCampuses' : 'Ui.select'), $erros['campus_id'] ?? null, lang('Agendamento.holidayCampusHelp')) ?>
    <?= ui_form_buttons($id === null, site_url('agendamento/feriados'), $modal) ?>
</form>

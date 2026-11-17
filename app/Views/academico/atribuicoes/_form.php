<?php

/**
 * Novo vínculo professor-disciplina no semestre: na página (form.php) ou no modal da listagem ($modal).
 *
 * @var array<string, mixed>  $dados
 * @var array<string, string> $erros
 * @var bool                  $modal
 */
$titulo = lang('Agendamento.linkNew');
?>
<form <?= ui_form_attrs(site_url('atribuicoes'), $titulo, $modal) ?>>
    <?= csrf_field() ?>
    <?= ui_select_fonte('semestre_id', lang('Agendamento.fieldSemester'), 'semestres-abertos', [], $dados['semestre_id'] ?? null, lang('Ui.select'), $erros['semestre_id'] ?? null, null, null, ['required' => true]) ?>
    <?= ui_select_fonte('disciplina_id', lang('Agendamento.fieldDiscipline'), 'disciplinas-atribuiveis', [], $dados['disciplina_id'] ?? null, lang('Ui.select'), $erros['disciplina_id'] ?? null, null, null, ['required' => true]) ?>
    <?= ui_select_fonte('professor_id', lang('Agendamento.fieldTeacher'), 'pessoas', [], $dados['professor_id'] ?? null, lang('Ui.select'), $erros['professor_id'] ?? null, null, null, ['required' => true]) ?>
    <?= ui_form_buttons(true, site_url('atribuicoes'), $modal) ?>
</form>

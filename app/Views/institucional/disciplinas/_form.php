<?php

/**
 * Formulário de disciplina: na página (form.php) ou no modal da listagem ($modal).
 *
 * @var array<string, mixed>  $disciplina
 * @var array<string, string> $erros
 * @var bool                  $modal
 */
$novo   = ! isset($disciplina['id_disciplina']);
$titulo = lang($novo ? 'Disciplinas.new' : 'Disciplinas.edit');
$acao   = $novo ? site_url('disciplinas') : site_url('disciplinas/' . $disciplina['id_disciplina']);
$voltar = $novo ? site_url('disciplinas') : site_url('disciplinas/' . $disciplina['id_disciplina']);
?>
<form <?= ui_form_attrs($acao, $titulo, $modal) ?>>
    <?= csrf_field() ?>
    <?= ui_select_fonte('curso_id', lang('Disciplinas.fieldCourse'), 'cursos', [], $disciplina['curso_id'] ?? null, lang('Ui.select'), $erros['curso_id'] ?? null, null, null, ['required' => true]) ?>
    <div class="grid gap-x-space-4 sm:grid-cols-[1fr_3fr]">
        <?= ui_field('sigla', lang('Disciplinas.fieldCode'), 'text', (string) ($disciplina['sigla'] ?? ''), $erros['sigla'] ?? null, null, ['required' => true, 'maxlength' => '20']) ?>
        <?= ui_field('nome', lang('Institucional.fieldName'), 'text', (string) ($disciplina['nome'] ?? ''), $erros['nome'] ?? null, null, ['required' => true, 'maxlength' => '255']) ?>
    </div>
    <?= ui_form_buttons($novo, $voltar, $modal) ?>
</form>

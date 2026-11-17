<?php

/**
 * Formulário de semestre letivo: na página (form.php) ou no modal da listagem ($modal).
 *
 * @var array<string, mixed>  $semestre
 * @var array<string, string> $erros
 * @var bool                  $modal
 */
$novo   = ! isset($semestre['id_semestre']);
$titulo = lang($novo ? 'Semestres.new' : 'Semestres.edit');
$acao   = $novo ? site_url('semestres') : site_url('semestres/' . $semestre['id_semestre']);
$status = opcoes_traduzidas('Semestres.status_', \App\Models\Institucional\SemestreModel::STATUS);
?>
<form <?= ui_form_attrs($acao, $titulo, $modal) ?>>
    <?= csrf_field() ?>
    <?= ui_field('nome', lang('Institucional.fieldName'), 'text', (string) ($semestre['nome'] ?? ''), $erros['nome'] ?? null, lang('Semestres.nameHelp'), ['required' => true, 'maxlength' => '50']) ?>
    <div class="grid gap-x-space-4 sm:grid-cols-2">
        <?= ui_field('data_inicio', lang('Semestres.fieldStart'), 'date', (string) ($semestre['data_inicio'] ?? ''), $erros['data_inicio'] ?? null, null, ['required' => true]) ?>
        <?= ui_field('data_fim', lang('Semestres.fieldEnd'), 'date', (string) ($semestre['data_fim'] ?? ''), $erros['data_fim'] ?? null, null, ['required' => true]) ?>
    </div>
    <?= ui_select('status', lang('Ui.status'), $status, (string) ($semestre['status'] ?? 'planejado'), $erros['status'] ?? null, lang('Semestres.statusHelp')) ?>
    <?= ui_form_buttons($novo, site_url('semestres'), $modal) ?>
</form>

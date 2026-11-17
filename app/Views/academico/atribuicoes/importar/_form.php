<?php

/**
 * Importar os vínculos de outro semestre: na página (form.php) ou no modal da listagem ($modal). Copia
 * professor-disciplina do semestre de origem para o de destino, sem duplicar os que já existem.
 *
 * @var array<string, mixed>  $dados
 * @var array<string, string> $erros
 * @var bool                  $modal
 */
$titulo = lang('Agendamento.linkImport');
?>
<form <?= ui_form_attrs(site_url('atribuicoes/importar'), $titulo, $modal) ?>>
    <?= csrf_field() ?>
    <?= ui_select_fonte('origem_id', lang('Agendamento.linkImportFrom'), 'semestres', [], $dados['origem_id'] ?? null, lang('Ui.select'), $erros['origem_id'] ?? null, null, null, ['required' => true]) ?>
    <?= ui_select_fonte('destino_id', lang('Agendamento.linkImportTo'), 'semestres-abertos', [], $dados['destino_id'] ?? null, lang('Ui.select'), $erros['destino_id'] ?? null, null, null, ['required' => true]) ?>
    <?= ui_form_buttons(true, site_url('atribuicoes'), $modal) ?>
</form>

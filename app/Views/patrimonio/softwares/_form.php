<?php

/**
 * Formulário de software: na página (form.php) ou no modal da listagem ($modal).
 *
 * @var array<string, mixed>  $software
 * @var array<string, string> $erros
 * @var bool                  $modal
 */
$novo   = ! isset($software['id_software']);
$titulo = lang($novo ? 'Patrimonio.softwareNew' : 'Patrimonio.softwareEdit');
$acao   = $novo ? site_url('patrimonio/softwares') : site_url('patrimonio/softwares/' . $software['id_software']);
?>
<form <?= ui_form_attrs($acao, $titulo, $modal) ?>>
    <?= csrf_field() ?>
    <?= ui_field('nome', lang('Institucional.fieldName'), 'text', (string) ($software['nome'] ?? ''), $erros['nome'] ?? null, null, ['required' => true, 'maxlength' => '255']) ?>
    <?= ui_field('versao', lang('Patrimonio.fieldVersion'), 'text', (string) ($software['versao'] ?? ''), $erros['versao'] ?? null, null, ['maxlength' => '50']) ?>
    <?= ui_textarea('requisitos_tecnicos', lang('Patrimonio.fieldRequirements'), (string) ($software['requisitos_tecnicos'] ?? ''), $erros['requisitos_tecnicos'] ?? null, lang('Patrimonio.requirementsHelp')) ?>
    <?= ui_form_buttons($novo, site_url('patrimonio/softwares'), $modal) ?>
</form>

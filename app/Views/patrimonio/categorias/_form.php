<?php

/**
 * Formulário de categoria de item: na página (form.php) ou no modal da listagem ($modal).
 *
 * @var array<string, mixed>  $categoria
 * @var array<string, string> $erros
 * @var bool                  $modal
 */
$novo   = ! isset($categoria['id_categoria_item']);
$titulo = lang($novo ? 'Patrimonio.categoryNew' : 'Patrimonio.categoryEdit');
$acao   = $novo ? site_url('patrimonio/categorias') : site_url('patrimonio/categorias/' . $categoria['id_categoria_item']);
?>
<form <?= ui_form_attrs($acao, $titulo, $modal) ?>>
    <?= csrf_field() ?>
    <?= ui_field('nome', lang('Institucional.fieldName'), 'text', (string) ($categoria['nome'] ?? ''), $erros['nome'] ?? null, null, ['required' => true, 'maxlength' => '255']) ?>
    <?= ui_field('descricao', lang('Institucional.fieldDescription'), 'text', (string) ($categoria['descricao'] ?? ''), $erros['descricao'] ?? null, null, ['maxlength' => '255']) ?>
    <?= ui_form_buttons($novo, site_url('patrimonio/categorias'), $modal) ?>
</form>

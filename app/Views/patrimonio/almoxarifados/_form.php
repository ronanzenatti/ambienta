<?php

/**
 * Formulário de almoxarifado: na página (form.php) ou no modal da listagem ($modal). O campus só é
 * escolhido ao criar.
 *
 * @var array<string, mixed>  $almoxarifado
 * @var string                $campusNome  campus do almoxarifado (ao alterar)
 * @var array<string, string> $erros
 * @var bool                  $modal
 */
$novo   = ! isset($almoxarifado['id_almoxarifado']);
$titulo = lang($novo ? 'Patrimonio.warehouseNew' : 'Patrimonio.warehouseEdit');
$acao   = $novo ? site_url('patrimonio/almoxarifados') : site_url('patrimonio/almoxarifados/' . $almoxarifado['id_almoxarifado']);
?>
<form <?= ui_form_attrs($acao, $titulo, $modal) ?>>
    <?= csrf_field() ?>
    <?php if ($novo) : ?>
        <?= ui_select_fonte('campus_id', lang('Patrimonio.fieldCampus'), 'campi', ['chave' => 'patrimonio.acesso', 'nivel' => 'editor'] + ['sem_almoxarifado' => '1'], $almoxarifado['campus_id'] ?? null, lang('Ui.select'), $erros['campus_id'] ?? null, lang('Patrimonio.warehouseCampusHelp'), null, ['required' => true]) ?>
    <?php else : ?>
        <p class="mb-space-4 text-body"><span class="text-body-strong"><?= esc(lang('Patrimonio.fieldCampus')) ?>:</span> <?= esc($campusNome) ?></p>
    <?php endif ?>
    <?= ui_field('nome', lang('Institucional.fieldName'), 'text', (string) ($almoxarifado['nome'] ?? ''), $erros['nome'] ?? null, null, ['required' => true, 'maxlength' => '255']) ?>
    <?= ui_form_buttons($novo, site_url('patrimonio/almoxarifados'), $modal) ?>
</form>

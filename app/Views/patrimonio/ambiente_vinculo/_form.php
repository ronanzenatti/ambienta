<?php

/**
 * Adicionar softwares ou itens a um ambiente: na página (form.php) ou no modal da página do ambiente ($modal).
 * Seleção múltipla com busca; ao salvar, o modal fecha e só a tabela do cartão recarrega (data-tabela).
 *
 * @var string                $tipo     'softwares' | 'itens'
 * @var array<string, mixed>  $ambiente
 * @var array<string, mixed>  $dados
 * @var array<string, string> $erros
 * @var bool                  $modal
 */
$id      = (int) $ambiente['id_ambiente'];
$itens   = $tipo === 'itens';
$titulo  = lang($itens ? 'Patrimonio.addItemsTitle' : 'Patrimonio.addSoftwaresTitle');
$acao    = site_url("ambientes/{$id}/" . ($itens ? 'itens' : 'softwares'));
$tabela  = $itens ? 'ambiente-itens' : 'ambiente-softwares';
?>
<form <?= ui_form_attrs($acao, $titulo, $modal) ?> data-tabela="<?= esc($tabela, 'attr') ?>">
    <?= csrf_field() ?>
    <?php if ($itens) : ?>
        <?= ui_select_fonte('item_ids[]', lang('Patrimonio.fieldItems'), 'itens-para-ambiente', ['ambiente' => $id], null, null, $erros['item_ids'] ?? null, lang('Patrimonio.itemsPickHelp'), null, ['multiple' => true, 'required' => true]) ?>
        <?= ui_select_fonte('destinatario_id', lang('Patrimonio.fieldRecipient'), 'destinatarios', [], $dados['destinatario_id'] ?? null, lang('Ui.select'), $erros['destinatario_id'] ?? null, lang('Patrimonio.recipientHelp'), null, ['required' => true]) ?>
    <?php else : ?>
        <?= ui_select_fonte('software_ids[]', lang('Patrimonio.fieldSoftwares'), 'softwares', ['fora_ambiente' => $id], null, null, $erros['software_ids'] ?? null, lang('Patrimonio.softwaresPickHelp'), null, ['multiple' => true, 'required' => true]) ?>
    <?php endif ?>
    <?= ui_form_buttons(true, site_url("ambientes/{$id}"), $modal) ?>
</form>

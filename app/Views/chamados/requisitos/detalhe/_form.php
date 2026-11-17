<?php

/**
 * Adicionar item, adicionar software ou propor software novo a um requisito: na página (form.php) ou no modal
 * da página do requisito ($modal). Ao salvar, o modal fecha e só a tabela do cartão recarrega (data-tabela).
 *
 * @var string                $tipo      'item' | 'software' | 'proposta'
 * @var array<string, mixed>  $requisito
 * @var array<string, mixed>  $dados     valores postados (reexibidos com erro)
 * @var array<string, string> $erros
 * @var bool                  $modal
 */
$id     = (int) $requisito['id_requisito_disciplina'];
$titulo = lang(match ($tipo) {
    'item'     => 'Chamados.addItem',
    'software' => 'Chamados.addSoftware',
    default    => 'Chamados.proposeSoftware',
});
$acao   = site_url("requisitos/{$id}/" . match ($tipo) {
    'item'     => 'itens',
    'software' => 'softwares',
    default    => 'softwares/propor',
});
$tabela = $tipo === 'item' ? 'requisito-itens' : 'requisito-softwares';
?>
<form <?= ui_form_attrs($acao, $titulo, $modal) ?> data-tabela="<?= esc($tabela, 'attr') ?>">
    <?= csrf_field() ?>
    <?php if ($tipo === 'item') : ?>
        <div class="grid gap-x-space-4 sm:grid-cols-[3fr_1fr]">
            <?= ui_select_fonte('categoria_item_id', lang('Chamados.fieldItemCategory'), 'categorias-item', [], (int) ($dados['categoria_item_id'] ?? 0) ?: null, lang('Ui.select'), $erros['categoria_item_id'] ?? null, null, null, ['required' => true]) ?>
            <?= ui_field('quantidade', lang('Chamados.fieldQuantity'), 'number', (string) ($dados['quantidade'] ?? '1'), $erros['quantidade'] ?? null, null, ['required' => true, 'min' => '1', 'max' => '65535', 'inputmode' => 'numeric']) ?>
        </div>
        <p class="mb-space-4 text-caption text-ink-muted"><?= esc(lang('Chamados.itemAddHelp')) ?></p>
    <?php elseif ($tipo === 'software') : ?>
        <?= ui_select_fonte('software_id', lang('Chamados.fieldSoftware'), 'softwares', ['fora_requisito' => $id], null, lang('Ui.select'), $erros['software_id'] ?? null, null, null, ['required' => true]) ?>
    <?php else : ?>
        <p class="mb-space-4 text-caption text-ink-muted"><?= esc(lang('Chamados.proposeSoftwareHelp')) ?></p>
        <div class="grid gap-x-space-4 sm:grid-cols-[3fr_1fr]">
            <?= ui_field('nome', lang('Institucional.fieldName'), 'text', (string) ($dados['nome'] ?? ''), $erros['nome'] ?? null, null, ['required' => true, 'maxlength' => '255']) ?>
            <?= ui_field('versao', lang('Patrimonio.fieldVersion'), 'text', (string) ($dados['versao'] ?? ''), $erros['versao'] ?? null, null, ['maxlength' => '50']) ?>
        </div>
        <?= ui_textarea('requisitos_tecnicos', lang('Patrimonio.fieldRequirements'), (string) ($dados['requisitos_tecnicos'] ?? ''), $erros['requisitos_tecnicos'] ?? null, null, 3, ['maxlength' => '5000']) ?>
    <?php endif ?>
    <?= ui_form_buttons(true, site_url("requisitos/{$id}"), $modal) ?>
</form>

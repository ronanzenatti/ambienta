<?php

/**
 * Abertura de chamado de adequação: na página (form.php) ou no modal da listagem ($modal).
 *
 * @var array<string, mixed>  $dados          ambiente_fixo = 1: veio da página do ambiente (ambiente escolhido e fixo)
 * @var string|null           $ambienteRotulo texto do ambiente fixo
 * @var array<string, string> $erros
 * @var bool                  $modal
 */
$titulo = lang('Chamados.ticketNew');
$fixo   = (string) ($dados['ambiente_fixo'] ?? '') === '1';
?>
<form <?= ui_form_attrs(site_url('chamados'), $titulo, $modal) ?><?= $fixo ? ' data-tabela="ambiente-chamados"' : '' ?>>
    <?= csrf_field() ?>
    <?php if ($fixo) : ?>
        <input type="hidden" name="ambiente_id" value="<?= esc((string) $dados['ambiente_id'], 'attr') ?>">
        <input type="hidden" name="ambiente_fixo" value="1">
        <p class="mb-space-4 text-body"><span class="text-body-strong"><?= esc(lang('Chamados.fieldEnvironment')) ?>:</span> <?= esc($ambienteRotulo ?? '') ?></p>
        <?php if (isset($erros['ambiente_id'])) : ?>
            <p class="mb-space-4 text-data text-signal-critical"><?= esc($erros['ambiente_id']) ?></p>
        <?php endif ?>
    <?php else : ?>
        <?= ui_select_fonte('ambiente_id', lang('Chamados.fieldEnvironment'), 'ambientes', ['chave' => 'chamados.acesso', 'nivel' => 'editor'], $dados['ambiente_id'] ?? null, lang('Ui.select'), $erros['ambiente_id'] ?? null, null, null, ['required' => true]) ?>
    <?php endif ?>
    <?= ui_textarea('descricao', lang('Chamados.fieldDescription'), (string) ($dados['descricao'] ?? ''), $erros['descricao'] ?? null, lang('Chamados.descriptionHelp'), 5, ['required' => true, 'maxlength' => '5000']) ?>
    <?= ui_form_buttons(true, site_url('chamados'), $modal) ?>
</form>

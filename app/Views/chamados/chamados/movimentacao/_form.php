<?php

/**
 * Movimentação do chamado (andamento sem mudar a situação), com observação obrigatória: na página
 * (form.php) ou no modal da página do chamado ($modal).
 *
 * @var array<string, mixed>  $chamado
 * @var array<string, mixed>  $dados
 * @var array<string, string> $erros
 * @var bool                  $modal
 */
$id     = (int) $chamado['id_chamado'];
$titulo = lang('Chamados.movementTitle', [$id]);
$atual  = (string) $chamado['status'];
?>
<form <?= ui_form_attrs(site_url("chamados/{$id}/movimentacao"), $titulo, $modal) ?>>
    <?= csrf_field() ?>
    <p class="mb-space-4 flex flex-wrap items-center gap-space-2 text-body">
        <span class="text-body-strong"><?= esc(lang('Chamados.currentStatus')) ?>:</span>
        <?= ui_status_dot(\App\Tabelas\Chamados\ChamadosTabela::tom($atual), lang('Chamados.status_' . $atual)) ?>
    </p>
    <?= ui_textarea('observacao', lang('Chamados.fieldMovement'), (string) ($dados['observacao'] ?? ''), $erros['observacao'] ?? null, lang('Chamados.movementHelp'), 4, ['required' => true, 'maxlength' => '5000']) ?>
    <?= ui_form_buttons(false, site_url("chamados/{$id}"), $modal) ?>
</form>

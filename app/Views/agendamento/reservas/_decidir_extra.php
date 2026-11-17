<?php

/**
 * Deferir ou indeferir um pedido de extra (equipamento ou software) da reserva, com observação opcional.
 * O campo é montado aqui (e não com ui_field) porque a página tem um formulário destes por pedido e cada
 * campo precisa de id próprio.
 *
 * @var string $acao  URL do POST
 * @var string $chave sufixo único do id do campo
 */
$campo = 'extra-nota-' . $chave;
?>
<form method="post" action="<?= esc($acao, 'attr') ?>" class="mt-space-2">
    <?= csrf_field() ?>
    <div class="mb-space-2">
        <label for="<?= esc($campo, 'attr') ?>" class="mb-space-1 block text-body-strong"><?= esc(lang('Agendamento.fieldDecisionNote')) ?></label>
        <input type="text" id="<?= esc($campo, 'attr') ?>" name="justificativa" maxlength="1000"
               class="min-h-11 w-full rounded-sm border border-border-strong bg-surface px-space-3 text-body shadow-xs focus:border-brand">
    </div>
    <div class="flex flex-wrap gap-space-2">
        <?= ui_button(lang('Agendamento.grant'), 'primary', ['type' => 'submit', 'name' => 'decisao', 'value' => 'deferido']) ?>
        <?= ui_button(lang('Agendamento.refuse'), 'secondary', ['type' => 'submit', 'name' => 'decisao', 'value' => 'indeferido']) ?>
    </div>
</form>

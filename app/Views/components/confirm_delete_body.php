<?php

/**
 * Corpo do modal de exclusão: mensagem + campo de confirmação.
 * O botão Excluir fica no rodapé do modal, ligado a este form pelo atributo form=.
 * Enter no campo não submete enquanto CONFIRMO não estiver digitado.
 *
 * Sem $message/$action (null), o modal é o compartilhado de uma listagem (ui_confirm_delete_linhas):
 * texto e URL chegam no evento que abre o modal (dados.mensagem, dados.acao).
 *
 * @var string|null $message
 * @var string|null $action
 * @var string $formId
 * @var string $fieldId
 * @var string $confirmWord
 */
?>
<?php if ($message === null) : ?>
    <p class="mb-space-4" x-text="dados.mensagem ?? ''"></p>
<?php else : ?>
    <p class="mb-space-4"><?= esc($message) ?></p>
<?php endif ?>
<form id="<?= esc($formId, 'attr') ?>" method="post"
      <?= $action === null ? ':action="dados.acao"' : 'action="' . esc($action, 'attr') . '"' ?>
      @submit="if (!canConfirm) $event.preventDefault()">
    <?= csrf_field() ?>
    <input type="hidden" name="_method" value="DELETE">
    <label for="<?= esc($fieldId, 'attr') ?>" class="mb-space-1 block text-body">
        <?= esc(lang('Ui.confirmDeleteInstruction', [$confirmWord])) ?>
    </label>
    <input type="text" id="<?= esc($fieldId, 'attr') ?>" name="confirmacao" x-model="typed"
           autocomplete="off" autocapitalize="characters" spellcheck="false"
           class="min-h-11 w-full rounded-sm border border-border-strong bg-surface px-space-3 text-body shadow-xs placeholder:text-ink-muted focus:border-brand aria-[invalid=true]:border-signal-critical">
</form>

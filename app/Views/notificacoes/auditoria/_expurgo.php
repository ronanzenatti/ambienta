<?php

/**
 * Modal do expurgo do log (só superusuário). Mesmo padrão CONFIRMO das exclusões: o botão começa
 * desabilitado e só habilita ao digitar a palavra; o servidor revalida a palavra e a data.
 *
 * @var DateTimeImmutable $limite   data mais recente aceita (hoje menos a retenção)
 * @var int               $retencao anos de retenção mínima
 */
$confirmWord = lang('Ui.confirmWord');
$limiteIso   = $limite->format('Y-m-d');

$corpo = '<p class="mb-space-4">' . esc(lang('Auditoria.purgeIntro', [$retencao])) . '</p>'
    . '<form id="expurgar-log-form" method="post" action="' . esc(site_url('auditoria/expurgo'), 'attr') . '"'
    . ' @submit="if (!canConfirm) $event.preventDefault()">'
    . csrf_field()
    . '<input type="hidden" name="_method" value="DELETE">'
    . ui_field('data', lang('Auditoria.purgeDate'), 'date', $limiteIso, null, null, ['required' => true, 'max' => $limiteIso])
    . '<p class="mb-space-4">' . esc(lang('Auditoria.purgeConfirmText')) . '</p>'
    . '<label for="expurgar-log-confirmacao" class="mb-space-1 block text-body">'
    . esc(lang('Ui.confirmDeleteInstruction', [$confirmWord])) . '</label>'
    . '<input type="text" id="expurgar-log-confirmacao" name="confirmacao" x-model="typed" autocomplete="off"'
    . ' autocapitalize="characters" spellcheck="false"'
    . ' class="min-h-11 w-full rounded-sm border border-border-strong bg-surface px-space-3 text-body shadow-xs focus:border-brand">'
    . '</form>';

$rodape = ui_button(lang('Auditoria.purgeButton'), 'delete', [
    'type'      => 'submit',
    'form'      => 'expurgar-log-form',
    'disabled'  => true,
    ':disabled' => '!canConfirm',
]);
?>
<?= ui_modal('expurgar-log', lang('Auditoria.purgeTitle'), $corpo, $rodape, lang('Ui.cancel'), $confirmWord);

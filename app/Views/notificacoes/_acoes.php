<?php

/**
 * Botões do cabeçalho da caixa de notificações: marcar todas como lidas e preferências.
 */

?>
<form method="post" action="<?= site_url('notificacoes/lidas') ?>">
    <?= csrf_field() ?>
    <?= ui_button(lang('Notificacoes.markAllRead'), 'secondary', ['type' => 'submit']) ?>
</form>
<?= ui_button(lang('Notificacoes.navPreferences'), 'secondary', ['href' => site_url('notificacoes/preferencias')]) ?>

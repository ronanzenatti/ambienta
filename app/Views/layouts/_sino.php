<?php

/**
 * Sino da barra superior: leva à caixa de notificações, com o número de não lidas. O nome acessível diz o
 * número por extenso; o selo é só visual (e nunca só cor: traz o número).
 *
 * @var int $usuarioId
 */
$naoLidas = db_connect()->table('notificacoes')
    ->where('usuario_id', $usuarioId)
    ->where('lida', 0)
    ->where('deletado_em', null)
    ->countAllResults();
$rotulo = lang('Notificacoes.bell', [$naoLidas]);
?>
<a href="<?= site_url('notificacoes') ?>" aria-label="<?= esc($rotulo, 'attr') ?>" title="<?= esc($rotulo, 'attr') ?>"
   <?= url_is('notificacoes*') ? 'aria-current="page"' : '' ?>
   class="relative inline-flex size-11 items-center justify-center rounded-sm border border-border bg-surface text-ink-muted shadow-xs hover:bg-paper hover:text-ink">
    <?= ui_icon('sino') ?>
    <?php if ($naoLidas > 0) : ?>
        <span class="absolute -right-space-1 -top-space-1 flex min-w-5 items-center justify-center rounded-full bg-signal-critical px-space-1 text-caption font-semibold text-brand-ink" aria-hidden="true">
            <?= $naoLidas > 99 ? '99+' : $naoLidas ?>
        </span>
    <?php endif ?>
</a>

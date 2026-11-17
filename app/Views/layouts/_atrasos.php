<?php

/**
 * Alerta de atraso a cada acesso (REQUISITOS §4): movimentações de patrimônio que o usuário logado deveria
 * ter confirmado e cujo prazo de resposta venceu. Some quando ele confirma (não há cancelamento automático).
 *
 * @var int $usuarioId
 */
$atrasadas = (new \App\Libraries\Patrimonio\LembreteAtrasos(db_connect()))->atrasadasParaReceber($usuarioId);
?>
<?php if ($atrasadas > 0) : ?>
    <div class="mb-space-6">
        <?= ui_alert('critical', lang('Patrimonio.overdueBanner', [$atrasadas])) ?>
        <a href="<?= site_url('patrimonio/movimentacoes/a-receber') ?>" class="inline-flex min-h-11 items-center text-body underline"><?= esc(lang('Patrimonio.overdueBannerLink')) ?></a>
    </div>
<?php endif ?>

<?php

/**
 * Mensagem de retorno: ponto colorido + texto (status nunca só por cor).
 * Erros usam role="alert" (anunciado na hora); o resto, role="status".
 *
 * @var string $tone    'confirmed' | 'pending' | 'critical'
 * @var string $message
 */
?>
<?php
// Fundo levemente tingido na cor do sinal (como os alertas do TailAdmin); o texto continua ink.
$tons = [
    'confirmed' => 'border-signal-confirmed/40 bg-signal-confirmed/5',
    'pending'   => 'border-signal-pending/40 bg-signal-pending/5',
    'critical'  => 'border-signal-critical/40 bg-signal-critical/5',
];
?>
<div role="<?= $tone === 'critical' ? 'alert' : 'status' ?>" class="mb-space-4 rounded-sm border p-space-4 <?= $tons[$tone] ?>">
    <?= ui_status_dot($tone, $message) ?>
</div>

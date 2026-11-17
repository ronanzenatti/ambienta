<?php

/**
 * Indicador de status: ponto de 8px + rótulo por extenso.
 *
 * @var string $tone  'confirmed' | 'pending' | 'critical' | 'neutral'
 * @var string $label
 */
$dotClasses = [
    'confirmed' => 'bg-signal-confirmed',
    'pending'   => 'bg-signal-pending',
    'critical'  => 'bg-signal-critical',
    'neutral'   => 'bg-ink-muted',
];
?>
<span class="inline-flex items-center gap-space-2 text-body">
    <span class="size-2 shrink-0 rounded-full <?= $dotClasses[$tone] ?>" aria-hidden="true"></span>
    <span><?= esc($label) ?></span>
</span>

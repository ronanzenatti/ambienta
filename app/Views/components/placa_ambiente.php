<?php

/**
 * Placa de ambiente: imita a placa física na porta da sala.
 *
 * @var string $codigo
 * @var string $nome
 */
?>
<span class="inline-flex flex-col rounded-sm border border-border-strong bg-surface px-space-3 py-space-2 shadow-xs">
    <span class="text-data tabular-nums"><?= esc($codigo) ?></span>
    <span class="text-body-strong"><?= esc($nome) ?></span>
</span>

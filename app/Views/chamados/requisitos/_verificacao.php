<?php

/**
 * Resultado da verificação de um ambiente contra um requisito (dentro do modal da página do requisito). Só
 * sinaliza o que falta; nunca bloqueia nada.
 *
 * @var string                       $ambiente
 * @var list<array<string, mixed>>   $pendencias
 */
?>
<?php if ($pendencias === []) : ?>
    <?= ui_alert('confirmed', lang('Chamados.checkOk', [$ambiente])) ?>
<?php else : ?>
    <?= ui_alert('pending', lang('Chamados.checkPending', [$ambiente])) ?>
    <ul class="rounded-md border border-border bg-surface shadow-xs">
        <?php foreach ($pendencias as $pendencia) : ?>
            <li class="border-b border-border px-space-4 py-space-3 last:border-b-0">
                <?= ui_status_dot('pending', match ($pendencia['tipo']) {
                    'capacidade' => lang('Chamados.gapCapacity', [$pendencia['exigido'], $pendencia['disponivel']]),
                    'item'       => lang('Chamados.gapItem', [$pendencia['nome'], $pendencia['exigido'], $pendencia['disponivel']]),
                    default      => lang('Chamados.gapSoftware', [$pendencia['nome']]),
                }) ?>
            </li>
        <?php endforeach ?>
    </ul>
<?php endif ?>

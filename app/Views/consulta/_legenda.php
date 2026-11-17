<?php

/**
 * Legenda da visão, ao lado das abas. Linha do tempo e semana por turno: aula do semestre (faixa de cor à
 * esquerda) e reserva pontual (borda tracejada). Resumo por sala: ocupado/livre e as siglas dos turnos.
 *
 * @var string                                    $visao gantt | turnos | resumo
 * @var array{manha: int, tarde: int, noite: int} $fins
 */

use App\Libraries\Agendamento\GradeConsulta;

?>
<ul class="flex flex-wrap gap-x-space-6 gap-y-space-2 text-data" aria-label="<?= esc(lang('Consulta.legend'), 'attr') ?>">
    <?php if ($visao === 'resumo') : ?>
        <li class="inline-flex items-center gap-space-2">
            <span class="inline-block h-4 w-8 rounded-sm bg-brand" aria-hidden="true"></span><?= esc(lang('Consulta.busy')) ?>
        </li>
        <li class="inline-flex items-center gap-space-2">
            <span class="inline-block h-4 w-8 rounded-sm border border-border-strong bg-surface" aria-hidden="true"></span><?= esc(lang('Consulta.free')) ?>
        </li>
        <?php foreach (GradeConsulta::TURNOS as $t) : ?>
            <?php [$ini, $fim] = GradeConsulta::faixaDoTurno($t, $fins); ?>
            <li><span class="font-semibold"><?= esc(lang('Consulta.shiftShort_' . $t)) ?></span> = <?= esc(lang('Consulta.shift_' . $t)) ?> (<?= esc(consulta_hora($ini) . '–' . consulta_hora($fim)) ?>)</li>
        <?php endforeach ?>
    <?php else : ?>
        <li class="inline-flex items-center gap-space-2">
            <span class="inline-block h-4 w-8 rounded-sm border border-l-4 border-border-strong border-l-brand bg-brand/10" aria-hidden="true"></span>
            <?= esc(lang('Consulta.typeClass')) ?>
        </li>
        <li class="inline-flex items-center gap-space-2">
            <span class="inline-block h-4 w-8 rounded-sm border-2 border-dashed border-border-strong bg-surface" aria-hidden="true"></span>
            <?= esc(lang('Consulta.typeOneOff')) ?>
        </li>
    <?php endif ?>
</ul>

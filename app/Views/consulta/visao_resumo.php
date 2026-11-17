<?php

/**
 * Visão 3, resumo por sala (mestre-detalhe): uma linha compacta por ambiente, com a ocupação de cada dia em três
 * barras (manhã, tarde, noite, pelos limites do campus). Clicar na sala expande a semana completa dela (a mesma
 * agenda de colunas do modal da linha do tempo). Ideal para achar horários vagos entre muitas salas.
 *
 * @var array<string, mixed>                      $grade
 * @var array{manha: int, tarde: int, noite: int} $fins
 * @var string                                    $formato
 */

use App\Libraries\Agendamento\GradeConsulta;

$blocos = GradeConsulta::porPredio($grade['ambientes']);
$ids    = array_map(static fn (array $a): int => (int) $a['id'], $grade['ambientes']);
?>
<div x-data="ambientaResumo(<?= esc(json_encode($ids), 'attr') ?>)">
    <div class="mb-space-4 flex flex-wrap gap-space-2" role="group" aria-label="<?= esc(lang('Consulta.optionsSummary'), 'attr') ?>">
        <?= ui_button(lang('Consulta.expandAll'), 'secondary', ['type' => 'button', '@click' => 'todos(true)']) ?>
        <?= ui_button(lang('Consulta.collapseAll'), 'secondary', ['type' => 'button', '@click' => 'todos(false)']) ?>
    </div>

    <?php
    // Ambiente "sem agendamentos" = nenhum agendamento na semana (switch do topo da página).
    $livre = [];

    foreach ($grade['ambientes'] as $a) {
        $livre[(int) $a['id']] = true;

        foreach ($grade['dias'] as $dia) {
            if (($grade['grade'][$a['id']][$dia] ?? []) !== []) {
                $livre[(int) $a['id']] = false;
            }
        }
    }
    ?>
    <?php if (! in_array(true, $livre, true)) : ?>
        <p class="mb-space-4 text-data text-ink-muted" x-show="soLivres" x-cloak><?= esc(lang('Consulta.noFreeRooms')) ?></p>
    <?php endif ?>

    <?php foreach ($blocos as $bloco) : ?>
        <?php $temLivre = array_filter($bloco['ambientes'], static fn (array $a): bool => $livre[(int) $a['id']]) !== []; ?>
        <?= ui_recolhivel_inicio(
            lang('Consulta.blockTitle', [$bloco['nome'], count($bloco['ambientes'])]),
            true,
            true,
            'x-show="!soLivres || ' . ($temLivre ? 'true' : 'false') . '"'
        ) ?>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[56rem] border-collapse text-left">
            <caption class="sr-only"><?= esc(lang('Consulta.tableCaptionSummary')) ?></caption>
            <thead>
                <tr class="border-b border-border">
                    <th scope="col" class="sticky left-0 z-10 w-56 border-r border-border bg-surface px-space-2 py-space-2 text-body-strong"><?= esc(lang('Consulta.roomColumn')) ?></th>
                    <?php foreach ($grade['dias'] as $dia) : ?>
                        <th scope="col" class="min-w-32 px-space-2 py-space-2 text-body-strong">
                            <?= esc(lang('Consulta.weekdayShort_' . $dia)) ?>
                            <span class="block text-data font-normal tabular-nums text-ink-muted"><?= esc((new DateTimeImmutable($grade['datas'][$dia]))->format(lang('Consulta.shortDate'))) ?></span>
                        </th>
                    <?php endforeach ?>
                </tr>
            </thead>
            <?php foreach ($bloco['ambientes'] as $amb) : ?>
                <?php $id = (int) $amb['id']; ?>
                <tbody class="border-b border-border last:border-b-0" x-show="!soLivres || <?= $livre[$id] ? 'true' : 'false' ?>">
                    <tr>
                        <th scope="row" class="sticky left-0 z-10 border-r border-border bg-surface px-space-2 py-space-2 text-left font-normal">
                            <button type="button" class="flex min-h-11 w-full items-start gap-space-2 text-left"
                                    @click="alternar(<?= $id ?>)" :aria-expanded="abertos[<?= $id ?>] === true" aria-controls="resumo-sala-<?= $id ?>">
                                <span class="mt-space-1 shrink-0 transition-transform" :class="abertos[<?= $id ?>] ? 'rotate-180' : ''"><?= ui_icon('seta-baixo', 'size-5') ?></span>
                                <span>
                                    <span class="block text-body-strong"><?= esc($amb['numero']) ?></span>
                                    <span class="block text-data"><?= esc($amb['nome']) ?></span>
                                    <span class="block text-caption underline" x-show="!abertos[<?= $id ?>]"><?= esc(lang('Consulta.expandRoom')) ?></span>
                                    <span class="block text-caption underline" x-show="abertos[<?= $id ?>]" x-cloak><?= esc(lang('Consulta.collapseRoom')) ?></span>
                                </span>
                            </button>
                        </th>
                        <?php foreach ($grade['dias'] as $dia) : ?>
                            <?php
                            $eventos = array_map(static fn (string $c): array => $grade['eventos'][$c], $grade['grade'][$id][$dia] ?? []);
                            ?>
                            <td class="px-space-2 py-space-2 align-middle">
                                <div class="flex gap-space-1" role="group" aria-label="<?= esc(lang('Consulta.weekday_' . $dia), 'attr') ?>">
                                    <?php foreach (GradeConsulta::TURNOS as $t) : ?>
                                        <?php
                                        [$de, $ate] = GradeConsulta::faixaDoTurno($t, $fins);
                                        $ocupado    = array_filter($eventos, static fn (array $e): bool => GradeConsulta::toca($e, $de, $ate)) !== [];
                                        ?>
                                        <span class="flex-1 rounded-sm px-space-1 py-space-1 text-center text-caption font-semibold <?= $ocupado ? 'bg-brand text-brand-ink' : 'border border-border-strong text-ink-muted' ?>"
                                              title="<?= esc(lang('Consulta.shift_' . $t) . ': ' . ($ocupado ? lang('Consulta.busy') : lang('Consulta.free')), 'attr') ?>">
                                            <?= esc(lang('Consulta.shiftShort_' . $t)) ?><span class="sr-only">, <?= esc(lang('Consulta.shift_' . $t) . ': ' . ($ocupado ? lang('Consulta.busy') : lang('Consulta.free'))) ?></span>
                                        </span>
                                    <?php endforeach ?>
                                </div>
                            </td>
                        <?php endforeach ?>
                    </tr>
                    <tr id="resumo-sala-<?= $id ?>" x-show="abertos[<?= $id ?>]" x-cloak>
                        <td colspan="<?= count($grade['dias']) + 1 ?>" class="border-t border-border bg-paper p-space-4">
                            <template x-if="abertos[<?= $id ?>]">
                                <div x-data="ambientaSala(<?= $id ?>)">
                                    <?= view('consulta/_sala_semana', [], ['saveData' => false]) ?>
                                </div>
                            </template>
                        </td>
                    </tr>
                </tbody>
            <?php endforeach ?>
        </table>
    </div>
        <?= ui_recolhivel_fim() ?>
    <?php endforeach ?>
</div>

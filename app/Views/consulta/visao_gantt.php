<?php

/**
 * Visão 1, linha do tempo (padrão): uma linha por ambiente, uma aba por dia da semana, horas de 07h a 23h na
 * horizontal. Cada agendamento é posicionado pela proporção de minutos sobre a régua (em %, ver .consulta-pos em
 * resources/css/app.css): a régua é uma faixa [--r0, --r0 + --rs] em minutos, que o filtro de turno encolhe
 * (zoom) sem recarregar a página. A coluna do ambiente é única; cada linha guarda uma trilha por dia e só a do
 * dia escolhido aparece. Sem JS, aparece só o dia do início (os botões de dia dependem de JS).
 *
 * @var array<string, mixed>                      $grade
 * @var array{manha: int, tarde: int, noite: int} $fins
 * @var string                                    $diaPadrao
 * @var DateTimeImmutable                         $domingo
 * @var string                                    $formato
 */

use App\Libraries\Agendamento\GradeConsulta;

$linha = 4.5; // altura (rem) de uma faixa de agendamento (.consulta-pos); cabe as 3 linhas de texto do bloco

$faixas = [];

foreach (['todos', ...GradeConsulta::TURNOS] as $t) {
    $faixas[$t] = GradeConsulta::faixaDoTurno($t, $fins);
}

$rotuloTurno = static fn (string $t): string => $t === 'todos'
    ? lang('Consulta.shiftAll', [consulta_hora($faixas[$t][0]), consulta_hora($faixas[$t][1])])
    : lang('Consulta.shiftRange', [lang('Consulta.shift_' . $t), consulta_hora($faixas[$t][0]), consulta_hora($faixas[$t][1])]);

$blocos  = GradeConsulta::porPredio($grade['ambientes']);
$horas   = range(GradeConsulta::INICIO / 60, GradeConsulta::FIM / 60 - 1);
$posicao = static fn (int $minuto): string => 'calc((' . $minuto . ' - var(--r0)) / var(--rs) * 100%)';
$curta   = lang('Consulta.shortDate');
// Só o dia de início aparece no HTML; o Alpine mostra e esconde os demais.
$oculto = static fn (string $dia): bool => $dia !== $diaPadrao;

// Dias com agendamento de cada ambiente (para o switch "só ambientes sem agendamentos", que age no dia escolhido).
$diasOcupados = [];

foreach ($grade['ambientes'] as $a) {
    $diasOcupados[(int) $a['id']] = array_values(array_filter(
        $grade['dias'],
        static fn (string $d): bool => ($grade['grade'][$a['id']][$d] ?? []) !== []
    ));
}

$json = static fn (array $v): string => esc(json_encode($v), 'attr');
?>
<div x-data="ambientaConsulta(<?= esc(json_encode(['dia' => $diaPadrao, 'faixas' => $faixas]), 'attr') ?>)"
     :style="estilo" style="--r0: <?= GradeConsulta::INICIO ?>; --rs: <?= GradeConsulta::FIM - GradeConsulta::INICIO ?>">
    <?php // Dias à esquerda e turno (controle segmentado) à direita, numa linha só. ?>
    <div class="mb-space-4 flex flex-wrap items-center justify-between gap-x-space-6 gap-y-space-2">
        <div class="flex flex-wrap gap-space-2" role="group" aria-label="<?= esc(lang('Consulta.dayPicker'), 'attr') ?>">
            <?php foreach ($grade['dias'] as $dia) : ?>
                <?php $quando = (new DateTimeImmutable($grade['datas'][$dia]))->format($curta); ?>
                <button type="button" @click="dia = '<?= $dia ?>'" :aria-pressed="dia === '<?= $dia ?>'"
                        aria-label="<?= esc(lang('Consulta.dayCount', [lang('Consulta.weekday_' . $dia) . ' ' . $quando, $grade['totais'][$dia]]), 'attr') ?>"
                        title="<?= esc(lang('Consulta.dayTotal', [$grade['totais'][$dia]]), 'attr') ?>"
                        :class="dia === '<?= $dia ?>' ? 'bg-brand text-brand-ink' : 'border border-border-strong bg-surface text-ink hover:bg-paper'"
                        class="inline-flex min-h-11 items-baseline gap-space-2 rounded-sm px-space-3 py-space-2">
                    <span class="text-body-strong"><?= esc(lang('Consulta.weekdayShort_' . $dia)) ?> <span class="tabular-nums"><?= esc($quando) ?></span></span>
                    <span class="text-caption tabular-nums"><?= (int) $grade['totais'][$dia] ?></span>
                </button>
            <?php endforeach ?>
        </div>
        <div class="flex flex-wrap items-center gap-space-2">
            <span class="text-body-strong" id="consulta-turno-rotulo"><?= esc(lang('Consulta.shiftPicker')) ?></span>
            <div class="inline-flex" role="group" aria-labelledby="consulta-turno-rotulo">
                <?php foreach (array_keys($faixas) as $t) : ?>
                    <button type="button" @click="turno = '<?= $t ?>'" :aria-pressed="turno === '<?= $t ?>'"
                            :class="turno === '<?= $t ?>' ? 'relative z-10 border-brand bg-brand text-brand-ink' : 'border-border-strong bg-surface text-ink hover:bg-paper'"
                            class="inline-flex min-h-11 items-center border px-space-3 text-data no-underline -ml-px first:ml-0 first:rounded-l-sm last:rounded-r-sm"><?= esc($rotuloTurno($t)) ?></button>
                <?php endforeach ?>
            </div>
        </div>
    </div>

    <?php // O dia escolhido já aparece no botão; o título fica só para leitor de tela, com o aviso de dia vazio. ?>
    <?php foreach ($grade['dias'] as $dia) : ?>
        <div x-show="dia === '<?= $dia ?>'"<?= $oculto($dia) ? ' style="display: none"' : '' ?> aria-live="polite">
            <h2 class="sr-only">
                <?= esc(lang('Consulta.weekday_' . $dia)) ?>
                <?= esc((new DateTimeImmutable($grade['datas'][$dia]))->format($formato)) ?>
            </h2>
            <?php if ($grade['totais'][$dia] === 0) : ?>
                <p class="mb-space-2 text-data text-ink-muted"><?= esc(lang('Consulta.dayEmpty')) ?></p>
            <?php endif ?>
        </div>
    <?php endforeach ?>

    <p class="mb-space-4 text-data text-ink-muted" x-show="soLivres && !<?= $json(array_values($diasOcupados)) ?>.some((d) => ! d.includes(dia))" x-cloak><?= esc(lang('Consulta.noFreeRooms')) ?></p>

    <?php foreach ($blocos as $bloco) : ?>
        <?php $ocupadosDoBloco = array_values(array_map(static fn (array $a): array => $diasOcupados[(int) $a['id']], $bloco['ambientes'])); ?>
        <?= ui_recolhivel_inicio(
            lang('Consulta.blockTitle', [$bloco['nome'], count($bloco['ambientes'])]),
            true,
            true,
            'x-show="!soLivres || ' . $json($ocupadosDoBloco) . '.some((d) => ! d.includes(dia))"'
        ) ?>
    <div class="overflow-x-auto">
        <div class="min-w-[calc(13rem_+_var(--rs)_*_2px)]">
            <div class="flex border-b border-border">
                <div class="sticky left-0 z-20 flex h-8 w-52 shrink-0 items-center border-r border-border bg-surface px-space-2 text-body-strong"><?= esc(lang('Consulta.roomColumn')) ?></div>
                <div class="relative h-8 flex-1 overflow-hidden" aria-hidden="true">
                    <?php foreach ($horas as $h) : ?>
                        <span class="absolute inset-y-0 border-l border-border pl-space-1 text-caption tabular-nums text-ink-muted" style="left: <?= $posicao($h * 60) ?>"><?= $h ?>h</span>
                    <?php endforeach ?>
                </div>
            </div>

            <ul class="relative">
                <li class="pointer-events-none absolute inset-y-0 left-52 right-0 overflow-hidden" aria-hidden="true">
                    <?php foreach ($horas as $h) : ?>
                        <span class="absolute inset-y-0 border-l border-border" style="left: <?= $posicao($h * 60) ?>"></span>
                    <?php endforeach ?>
                </li>
                <?php foreach ($bloco['ambientes'] as $amb) : ?>
                    <li class="relative flex border-b border-border last:border-b-0" x-show="!soLivres || !<?= $json($diasOcupados[(int) $amb['id']]) ?>.includes(dia)">
                        <div class="sticky left-0 z-10 w-52 shrink-0 border-r border-border bg-surface px-space-2 py-space-2">
                            <p class="text-body-strong"><?= esc($amb['numero']) ?></p>
                            <p class="text-data"><?= esc($amb['nome']) ?></p>
                            <a class="inline-flex min-h-11 items-center text-data underline" href="<?= site_url('agenda/ambiente/' . (int) $amb['id']) ?>"
                               @click.prevent="$dispatch('open-modal', { id: 'consulta-sala', ambienteId: <?= (int) $amb['id'] ?> })"><?= esc(lang('Consulta.agendaLink')) ?><span class="sr-only">: <?= esc($amb['numero'] . ' — ' . $amb['nome']) ?></span></a>
                        </div>
                        <?php foreach ($grade['dias'] as $dia) : ?>
                            <?php
                            $chaves      = $grade['grade'][$amb['id']][$dia] ?? [];
                            $faixasDoDia = 1;

                            foreach ($chaves as $c) {
                                $faixasDoDia = max($faixasDoDia, $grade['eventos'][$c]['faixas']);
                            }
                            ?>
                            <div x-show="dia === '<?= $dia ?>'" class="relative flex-1 overflow-hidden"
                                 style="min-height: <?= $faixasDoDia * $linha ?>rem<?= $oculto($dia) ? '; display: none' : '' ?>">
                                <?php foreach ($chaves as $c) : ?>
                                    <?php $e = $grade['eventos'][$c]; ?>
                                    <?= consulta_bloco(
                                        $e,
                                        'consulta-pos',
                                        '--i: ' . $e['imin'] . '; --d: ' . ($e['fmin'] - $e['imin']) . '; --f: ' . $e['faixa']
                                    ) ?>
                                <?php endforeach ?>
                            </div>
                        <?php endforeach ?>
                    </li>
                <?php endforeach ?>
            </ul>
        </div>
    </div>
        <?= ui_recolhivel_fim() ?>
    <?php endforeach ?>
</div>

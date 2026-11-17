<?php

/**
 * Visão 2, semana completa por turno: ambiente nas linhas, dias nas colunas, e em cada célula os agendamentos do
 * dia em ordem de horário. O filtro de turno (Manhã/Tarde/Noite, com os limites do campus) recorta o que cada
 * célula mostra; é feito no servidor (link), então funciona sem JS.
 *
 * @var array<string, mixed>                      $grade
 * @var array{manha: int, tarde: int, noite: int} $fins
 * @var string                                    $turno
 * @var Closure                                   $linkTurno turno => URL
 * @var string                                    $formato
 */

use App\Libraries\Agendamento\GradeConsulta;

[$de, $ate] = GradeConsulta::faixaDoTurno($turno, $fins);
$blocos     = GradeConsulta::porPredio($grade['ambientes']);
?>
<div class="mb-space-4 flex flex-wrap items-center gap-space-2">
    <span class="text-body-strong" id="consulta-turno-rotulo"><?= esc(lang('Consulta.shiftPicker')) ?></span>
    <div class="inline-flex" role="group" aria-labelledby="consulta-turno-rotulo">
        <?php foreach (['todos', ...GradeConsulta::TURNOS] as $t) : ?>
            <?php
            [$ini, $fim] = GradeConsulta::faixaDoTurno($t, $fins);
            $rotulo      = $t === 'todos'
                ? lang('Consulta.shiftAll', [consulta_hora($ini), consulta_hora($fim)])
                : lang('Consulta.shiftRange', [lang('Consulta.shift_' . $t), consulta_hora($ini), consulta_hora($fim)]);
            ?>
            <a href="<?= esc($linkTurno($t), 'attr') ?>"<?= $turno === $t ? ' aria-current="true"' : '' ?>
               class="inline-flex min-h-11 items-center border px-space-3 text-data no-underline -ml-px first:ml-0 first:rounded-l-sm last:rounded-r-sm <?= $turno === $t ? 'relative z-10 border-brand bg-brand text-brand-ink' : 'border-border-strong bg-surface text-ink hover:bg-paper' ?>"><?= esc($rotulo) ?></a>
        <?php endforeach ?>
    </div>
</div>

<?php
// Ambiente "sem agendamentos" = nenhum agendamento na semana dentro do turno escolhido (switch do topo da página).
$livre = [];

foreach ($grade['ambientes'] as $a) {
    $livre[(int) $a['id']] = true;

    foreach ($grade['dias'] as $dia) {
        foreach ($grade['grade'][$a['id']][$dia] ?? [] as $c) {
            if (GradeConsulta::toca($grade['eventos'][$c], $de, $ate)) {
                $livre[(int) $a['id']] = false;
            }
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
            <caption class="sr-only"><?= esc(lang('Consulta.tableCaptionShifts')) ?></caption>
            <thead>
                <tr class="border-b border-border">
                    <th scope="col" class="sticky left-0 z-10 w-44 border-r border-border bg-surface px-space-2 py-space-2 text-body-strong"><?= esc(lang('Consulta.roomColumn')) ?></th>
                    <?php foreach ($grade['dias'] as $dia) : ?>
                        <th scope="col" class="min-w-44 px-space-2 py-space-2 text-body-strong">
                            <?= esc(lang('Consulta.weekday_' . $dia)) ?>
                            <span class="block text-data font-normal tabular-nums text-ink-muted"><?= esc((new DateTimeImmutable($grade['datas'][$dia]))->format($formato)) ?></span>
                        </th>
                    <?php endforeach ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bloco['ambientes'] as $amb) : ?>
                    <tr class="border-b border-border last:border-b-0" x-show="!soLivres || <?= $livre[(int) $amb['id']] ? 'true' : 'false' ?>">
                        <th scope="row" class="sticky left-0 z-10 border-r border-border bg-surface px-space-2 py-space-2 align-top font-normal">
                            <span class="block text-body-strong"><?= esc($amb['numero']) ?></span>
                            <span class="block text-data"><?= esc($amb['nome']) ?></span>
                        </th>
                        <?php foreach ($grade['dias'] as $dia) : ?>
                            <?php
                            $eventos = array_values(array_filter(
                                array_map(static fn (string $c): array => $grade['eventos'][$c], $grade['grade'][$amb['id']][$dia] ?? []),
                                static fn (array $e): bool => GradeConsulta::toca($e, $de, $ate)
                            ));
                            ?>
                            <td class="align-top px-space-2 py-space-2">
                                <?php if ($eventos === []) : ?>
                                    <span class="text-data text-ink-muted"><span aria-hidden="true">—</span><span class="sr-only"><?= esc(lang('Consulta.free')) ?></span></span>
                                <?php else : ?>
                                    <ul class="flex flex-col gap-space-1">
                                        <?php foreach ($eventos as $e) : ?>
                                            <li><?= consulta_bloco($e, 'w-full') ?></li>
                                        <?php endforeach ?>
                                    </ul>
                                <?php endif ?>
                            </td>
                        <?php endforeach ?>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?= ui_recolhivel_fim() ?>
<?php endforeach ?>

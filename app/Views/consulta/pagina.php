<?php

/**
 * Consulta pública (sem login): a semana (domingo a sábado) de um campus, em três visões (cada uma na sua
 * rota). Parte fixa: uma linha com a semana e o botão dos filtros (painel recolhido) e outra com as visões e a legenda; o resto vem da
 * visão (consulta/visao_*). Sempre com a identidade padrão do sistema.
 *
 * @var string                                  $visao     gantt | turnos | resumo
 * @var int|null                                $campus
 * @var array<string, int|string>               $filtros
 * @var DateTimeImmutable                       $data      dia escolhido
 * @var DateTimeImmutable                       $domingo   primeiro dia da semana mostrada
 * @var array<string, mixed>|null               $grade     GradeConsulta::montar(); null sem campus
 * @var array{manha: int, tarde: int, noite: int} $fins
 * @var string                                  $turno     filtro de turno da visão por turno
 * @var string                                  $diaPadrao
 * @var string                                  $acao      rota da visão atual
 * @var array<string, string>                   $links     visão => URL (mantém campus, filtros e data)
 * @var string                                  $semanaAnterior
 * @var string                                  $semanaSeguinte
 * @var string                                  $hoje
 * @var string                                  $limpar
 * @var Closure                                 $linkTurno
 */
$formato = lang('Institucional.dateFormat');
$sabado  = $domingo->modify('+6 days');
$visoes  = ['gantt' => 'viewGantt', 'turnos' => 'viewShifts', 'resumo' => 'viewSummary'];
$filtrou = $filtros !== [];
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc(lang('Consulta.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <h1 class="mb-space-2 text-display"><?= esc(lang('Consulta.title')) ?></h1>
    <p class="mb-space-6 text-body text-ink-muted"><?= esc(lang('Consulta.intro')) ?></p>

    <?php if ($campus === null || $grade === null) : ?>
        <?= ui_alert('pending', lang('Consulta.noCampus')) ?>
    <?php else : ?>
        <?php // Estado compartilhado com as visões (o Alpine herda o escopo): painel de filtros e "só ambientes sem agendamentos". ?>
        <div x-data="{ filtros: false, soLivres: false }">
        <form method="get" action="<?= esc($acao, 'attr') ?>" role="search">
            <?php if ($visao === 'turnos' && $turno !== 'todos') : ?>
                <input type="hidden" name="turno" value="<?= esc($turno, 'attr') ?>">
            <?php endif ?>

            <?php // Uma linha só: semana (setas, intervalo, hoje, data) à esquerda e o botão dos filtros à direita. ?>
            <div class="mb-space-4 flex flex-wrap items-end gap-x-space-4 gap-y-space-2 rounded-md border border-border bg-surface p-space-4 shadow-xs">
                <div class="flex flex-wrap items-center gap-space-2">
                    <?= ui_icon_button('seta-esquerda', lang('Agendamento.previousWeek'), lang('Agendamento.previousWeek'), 'secondary', ['href' => $semanaAnterior]) ?>
                    <p class="px-space-2 text-body-strong tabular-nums" aria-live="polite">
                        <span class="sr-only"><?= esc(lang('Consulta.weekRange')) ?>:</span>
                        <?= esc($domingo->format($formato) . ' – ' . $sabado->format($formato)) ?>
                    </p>
                    <?= ui_icon_button('seta-direita', lang('Agendamento.nextWeek'), lang('Agendamento.nextWeek'), 'secondary', ['href' => $semanaSeguinte]) ?>
                    <?= ui_button(lang('Consulta.thisWeekShort'), 'secondary', ['href' => $hoje, 'aria-label' => lang('Consulta.thisWeek')]) ?>
                </div>
                <div class="[&>div]:mb-0">
                    <?= ui_field('data', lang('Consulta.fieldWeekOf'), 'date', $data->format('Y-m-d'), null, null, ['@change' => '$el.form.requestSubmit()', 'required' => true]) ?>
                </div>
                <?php // A data já envia o formulário ao mudar; o botão só é preciso sem JS. ?>
                <noscript><?= ui_button(lang('Consulta.showWeek'), 'secondary', ['type' => 'submit']) ?></noscript>
                <div class="ml-auto flex flex-wrap items-center gap-x-space-4 gap-y-space-2">
                    <button type="button" role="switch" :aria-checked="soLivres" @click="soLivres = ! soLivres" x-cloak
                            class="inline-flex min-h-11 items-center gap-space-2 rounded-sm text-body-strong">
                        <span class="relative inline-block h-6 w-11 shrink-0 rounded-full border border-border-strong transition-colors"
                              :class="soLivres ? 'bg-brand' : 'bg-surface'" aria-hidden="true">
                            <span class="absolute top-0.5 size-4.5 rounded-full transition-all"
                                  :class="soLivres ? 'left-5.5 bg-brand-ink' : 'left-0.5 bg-ink-muted'"></span>
                        </span>
                        <?= esc(lang('Consulta.freeRoomsOnly')) ?>
                    </button>
                    <?= ui_button($filtrou ? lang('Consulta.filtersCount', [count($filtros)]) : lang('Consulta.filtersTitle'), 'secondary', [
                        'type'           => 'button',
                        '@click'         => 'filtros = ! filtros',
                        ':aria-expanded' => 'filtros',
                        'aria-controls'  => 'consulta-filtros',
                        'x-cloak'        => true,
                    ]) ?>
                    <?php if ($filtrou) : ?>
                        <?= ui_button(lang('Consulta.clear'), 'secondary', ['href' => $limpar]) ?>
                    <?php endif ?>
                </div>
            </div>

            <div id="consulta-filtros" x-show="filtros" class="mb-space-4 rounded-md border border-border bg-surface p-space-4 shadow-xs sm:p-space-6">
                <div class="grid gap-x-space-4 sm:grid-cols-2 lg:grid-cols-3">
                    <?= ui_select_fonte('campus', lang('Consulta.fieldCampus'), 'campi-ativos', [], $campus) ?>
                    <?= ui_select_fonte('curso', lang('Consulta.fieldCourse'), 'cursos', [], $filtros['curso'] ?? null, lang('Consulta.any')) ?>
                    <?= ui_select_fonte('disciplina', lang('Consulta.fieldDiscipline'), 'disciplinas', [], $filtros['disciplina'] ?? null, lang('Consulta.any')) ?>
                    <?= ui_field('professor', lang('Consulta.fieldProfessor'), 'search', (string) ($filtros['professor'] ?? ''), null, lang('Consulta.professorHelp'), ['maxlength' => '100', 'autocomplete' => 'off']) ?>
                    <div class="sm:col-span-2">
                        <?= ui_field('texto', lang('Consulta.fieldText'), 'search', (string) ($filtros['texto'] ?? ''), null, lang('Consulta.textHelp'), ['maxlength' => '100', 'autocomplete' => 'off']) ?>
                    </div>
                </div>
                <?= ui_button(lang('Consulta.search'), 'primary', ['type' => 'submit']) ?>
            </div>
            <?php // Com JS o painel já nasce fechado (sem piscar); sem JS fica aberto, porque o botão "Filtros" não funciona. ?>
            <script>document.currentScript.previousElementSibling.style.display = 'none';</script>
        </form>

        <div class="mb-space-4 flex flex-wrap items-center justify-between gap-x-space-6 gap-y-space-2">
            <nav aria-label="<?= esc(lang('Consulta.viewsNav'), 'attr') ?>">
                <ul class="flex flex-wrap gap-space-2">
                    <?php foreach ($visoes as $chave => $rotulo) : ?>
                        <li>
                            <a href="<?= esc($links[$chave], 'attr') ?>"<?= $visao === $chave ? ' aria-current="page"' : '' ?>
                               title="<?= esc(lang('Consulta.' . $rotulo . 'Hint'), 'attr') ?>"
                               class="inline-flex min-h-11 items-center rounded-sm px-space-4 text-body-strong no-underline <?= $visao === $chave ? 'bg-brand text-brand-ink' : 'border border-border-strong bg-surface text-ink hover:bg-paper' ?>">
                                <?= esc(lang('Consulta.' . $rotulo)) ?><span class="sr-only">. <?= esc(lang('Consulta.' . $rotulo . 'Hint')) ?></span>
                            </a>
                        </li>
                    <?php endforeach ?>
                </ul>
            </nav>
            <?= view('consulta/_legenda', ['visao' => $visao, 'fins' => $fins], ['saveData' => false]) ?>
        </div>

        <?php
        $dadosVisao = compact('grade', 'fins', 'turno', 'diaPadrao', 'domingo', 'linkTurno', 'formato');
        echo view('consulta/visao_' . $visao, $dadosVisao, ['saveData' => false]);

        // Dados dos agendamentos para o JS (modal de detalhes e agenda semanal de um ambiente).
        $store = [
            'dias'      => array_map(static fn (string $dia): array => [
                'chave'  => $dia,
                'rotulo' => lang('Consulta.weekday_' . $dia),
                'curto'  => lang('Consulta.weekdayShort_' . $dia),
                'data'   => (new DateTimeImmutable($grade['datas'][$dia]))->format(lang('Consulta.shortDate')),
            ], $grade['dias']),
            'ambientes' => (object) array_map(static fn (array $a): array => [
                'numero' => (string) $a['numero'],
                'nome'   => (string) $a['nome'],
                'predio' => $a['predio'] . ' · ' . $a['campus'],
            ], array_column($grade['ambientes'], null, 'id')),
            'tipos'     => ['aula' => '', 'pontual' => lang('Consulta.typeOneOffShort')],
            // Ordem e rótulos dos detalhes (cada agendamento traz só os valores, em `d`).
            'ordem'     => ['tipo', 'rec', 'data', 'hora', 'amb', 'local', 'curso', 'disc', 'prof', 'resp', 'titulo', 'sem'],
            'rotulos'   => [
                'tipo'   => lang('Consulta.detailType'),
                'rec'    => lang('Consulta.detailRecurrence'),
                'data'   => lang('Consulta.detailDate'),
                'hora'   => lang('Consulta.detailTime'),
                'amb'    => lang('Consulta.detailRoom'),
                'local'  => lang('Consulta.detailPlace'),
                'curso'  => lang('Consulta.detailCourse'),
                'disc'   => lang('Consulta.detailDiscipline'),
                'prof'   => lang('Consulta.detailTeacher'),
                'resp'   => lang('Consulta.detailResponsible'),
                'titulo' => lang('Consulta.detailTitle'),
                'sem'    => lang('Consulta.detailSemester'),
            ],
            'eventos'   => (object) array_map(
                static fn (array $e): array => array_intersect_key(
                    $e,
                    array_flip(['k', 'tipo', 'imin', 'fmin', 'faixa', 'faixas', 'l1', 'l2', 'pre', 'l3', 'd'])
                ),
                $grade['eventos']
            ),
            'grade'     => (object) $grade['grade'],
        ];
        ?>
        <script>
            document.addEventListener('alpine:init', () => {
                window.Alpine.store('consulta', <?= json_encode($store, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>);
            });
        </script>

        </div>

        <?= ui_modal('consulta-evento', lang('Consulta.eventModalTitle'), view('consulta/_modal_evento', [], ['saveData' => false])) ?>
        <?php if ($visao === 'gantt') : ?>
            <?= ui_modal('consulta-sala', lang('Consulta.roomModalTitle'), view('consulta/_modal_sala', [], ['saveData' => false]), '', null, null, true) ?>
        <?php endif ?>
    <?php endif ?>
<?= $this->endSection() ?>

<?php

/**
 * Nova reserva, em cinco abas (Alpine, `ambientaReserva`): avançar só com o requisito de cada etapa cumprido,
 * voltar sempre livre.
 * 1. Quando (GET): data, horário e disciplina; "Próxima" busca os ambientes. Os ocupados só aparecem marcando
 *    "Mostrar também os ambientes ocupados" (seleção explícita, REQUISITOS §7): escolher um ocupado por alocação
 *    semestral vira um conflito a autorizar.
 * 2. Onde e o quê: os ambientes do horário numa tabela com filtro; "Visualizar" abre o resumo do ambiente em
 *    modal, onde ele é selecionado. Só avança com um ambiente selecionado.
 * 3. Detalhes: título, para quem, repetição e "ignorar os requisitos" (interruptor; desligado e travado quando o
 *    ambiente já atende ou não há disciplina).
 * 4. Equipamentos e softwares extras: duas listas, preenchidas por modais (como nos requisitos de disciplina).
 * 5. Resumo: tudo o que foi marcado; "Salvar" pede a confirmação da solicitação antes de enviar (POST).
 *
 * As listas da aba 2 e 4 são estado ainda não salvo da própria tela (não há registro no banco para a listagem
 * padrão paginar), por isso são tabelas locais com filtro no navegador.
 *
 * @var array{data: string, inicio: string, fim: string, ocupados: bool, disciplina: string} $busca
 * @var array<string, mixed>                                             $dados
 * @var array<int, string>                                               $ambientes   onde o usuário pode reservar
 * @var array<int, ?string>|null                                         $situacao    null = ainda sem horário
 * @var array<int, string>                                               $niveis      atendimento por ambiente (só com disciplina)
 * @var array<int, array<string, mixed>>                                 $resumos     dados de cada ambiente (modal)
 * @var int                                                              $passoInicial
 * @var bool                                                            $terceiros   pode reservar para outra pessoa
 * @var array<int, string>                                               $categorias  categorias de item (extras)
 * @var array<int, string>                                               $softwares
 * @var array<string, string>                                            $erros
 */
use App\Libraries\Agendamento\Recorrencia;
use App\Libraries\Chamados\Atendimento;

$titulo = lang('Agendamento.reservationNew');

$temDisciplina = $busca['disciplina'] !== '';
$formatoData   = lang('Institucional.dateFormat');
$dataFmt       = (DateTimeImmutable::createFromFormat('!Y-m-d', $busca['data']) ?: null)?->format($formatoData) ?? $busca['data'];
$fonteDisc     = $terceiros ? 'disciplinas' : 'disciplinas-do-professor';
$disciplina    = $temDisciplina ? service('opcoes')->porNome($fonteDisc, [])?->rotuloDe($busca['disciplina']) : null;

// Ambientes que a tabela mostra: livres e, com a seleção explícita, os ocupados (com o motivo).
$linhas = [];
if ($situacao !== null) {
    foreach ($ambientes as $id => $rotulo) {
        $motivo = $situacao[$id] ?? null;

        if ($motivo !== null && ! $busca['ocupados']) {
            continue;
        }

        $resumo = $resumos[$id] ?? [];
        $nivel  = $niveis[$id] ?? null;

        $linhas[$id] = [
            'rotulo'      => $rotulo,
            'nome'        => (string) ($resumo['nome'] ?? $rotulo),
            'local'       => (string) ($resumo['local'] ?? ''),
            'categoria'   => (string) ($resumo['categoria'] ?? ''),
            'capacidade'  => (int) ($resumo['capacidade'] ?? 0),
            'tamanho'     => (string) ($resumo['tamanho'] ?? ''),
            'descricao'   => (string) ($resumo['descricao'] ?? ''),
            'softwares'   => $resumo['softwares'] ?? [],
            'itens'       => $resumo['itens'] ?? [],
            'motivo'      => $motivo,
            'situacao'    => $motivo === null ? lang('Agendamento.free') : lang('Agendamento.busy_' . $motivo),
            'nivel'       => $nivel ?? '',
            'atendimento' => $nivel === null ? '' : Atendimento::rotulo($nivel),
            'tom'         => $nivel === null ? '' : Atendimento::tom($nivel),
            'busca'       => implode(' ', [$rotulo, $resumo['categoria'] ?? '', $motivo === null ? lang('Agendamento.free') : lang('Agendamento.busy_' . $motivo)]),
        ];
    }
}

$itensPedidos = [];
foreach ((array) ($dados['itens'] ?? []) as $categoriaId => $quantidade) {
    if (isset($categorias[(int) $categoriaId]) && ctype_digit((string) $quantidade) && (int) $quantidade > 0) {
        $itensPedidos[] = ['id' => (string) $categoriaId, 'rotulo' => $categorias[(int) $categoriaId], 'qtd' => (int) $quantidade];
    }
}
$softwaresPedidos = [];
foreach (array_map('intval', (array) ($dados['softwares'] ?? [])) as $softwareId) {
    if (isset($softwares[$softwareId])) {
        $softwaresPedidos[] = ['id' => (string) $softwareId, 'rotulo' => $softwares[$softwareId]];
    }
}

$config = [
    'passoInicial' => $passoInicial,
    'buscado'      => $situacao !== null,
    'disciplina'   => $temDisciplina,
    'integral'     => Atendimento::INTEGRAL,
    'ambienteId'   => (string) ($dados['ambiente_id'] ?? ''),
    'ambientes'    => $linhas,
    'titulo'       => (string) ($dados['titulo'] ?? ''),
    'ignorar'      => (bool) ($dados['ignorar'] ?? false),
    'itens'        => $itensPedidos,
    'softwares'    => $softwaresPedidos,
    'busca'        => [
        'data' => $busca['data'], 'inicio' => $busca['inicio'], 'fim' => $busca['fim'],
        'disciplina' => $busca['disciplina'], 'ocupados' => $busca['ocupados'] ? '1' : '',
    ],
    'textos'       => [
        'yes'                => lang('Ui.yes'),
        'no'                 => lang('Ui.no'),
        'forMyself'          => lang('Agendamento.forMyself'),
        'repeatNone'         => lang('Agendamento.repeatNone'),
        'chooseEnvironment'  => lang('Agendamento.chooseEnvironment'),
        'titleRequired'      => lang('Agendamento.titleRequired'),
        'repeatRequired'     => lang('Agendamento.recurrenceEndRequired'),
        'optionInvalid'      => lang('Agendamento.optionInvalid'),
        'quantityInvalid'    => lang('Agendamento.quantityInvalid'),
        'summaryTimes'       => lang('Agendamento.summaryTimes'),
        'summaryUntil'       => lang('Agendamento.summaryUntil'),
        'fulfilmentAsk'      => lang('Agendamento.fulfilmentAsk'),
        'ignoreMet'          => lang('Agendamento.ignoreMet'),
        'ignoreNoDiscipline' => lang('Agendamento.ignoreNoDiscipline'),
        'ignoreHelp'         => lang('Agendamento.ignoreHelp'),
        'seats'              => lang('Agendamento.seats'),
    ],
];

$abas = [
    1 => lang('Agendamento.stepWhen'),
    2 => lang('Agendamento.stepWhere'),
    3 => lang('Agendamento.stepDetails'),
    4 => lang('Agendamento.stepExtras'),
    5 => lang('Agendamento.stepSummary'),
];

$painel   = 'rounded-md border border-border bg-surface p-space-6 shadow-xs';
$cartao   = 'rounded-md border border-border p-space-4';
$subtitulo = 'mb-space-3 text-body-strong';
$th       = 'px-space-4 py-space-3';
$td       = 'px-space-4 py-space-3';

// Aviso de requisito não cumprido da aba (texto, nunca só cor).
$aviso = '<div x-show="erro !== \'\'" x-cloak role="alert" class="mb-space-4 rounded-sm border border-signal-critical/40 bg-signal-critical/5 p-space-4">'
    . '<span class="inline-flex items-center gap-space-2 text-body"><span class="size-2 shrink-0 rounded-full bg-signal-critical" aria-hidden="true"></span>'
    . '<span x-text="erro"></span></span></div>';

$ponto = static fn (string $xTom, string $xTexto): string => '<span class="inline-flex items-center gap-space-2 text-body">'
    . '<span class="size-2 shrink-0 rounded-full" :class="{\'bg-signal-confirmed\': ' . $xTom . ' === \'confirmed\', \'bg-signal-pending\': ' . $xTom . ' === \'pending\', \'bg-signal-critical\': ' . $xTom . ' === \'critical\'}" aria-hidden="true"></span>'
    . '<span x-text="' . $xTexto . '"></span></span>';

$botoesPasso = static fn (bool $primeiro, bool $ultimo): string => '<div class="mt-space-6 flex flex-wrap justify-between gap-space-3">'
    . ($primeiro ? '<span></span>' : ui_button(lang('Ui.previous'), 'secondary', ['type' => 'button', '@click' => 'anterior()']))
    . '<div class="flex flex-wrap gap-space-3">'
    . ui_button(lang('Ui.cancel'), 'secondary', ['href' => site_url('agendamento/reservas')])
    . ($ultimo
        ? ui_button(lang('Ui.save'), 'primary', ['type' => 'button', '@click' => "\$dispatch('open-modal', 'reserva-confirmar')", ':disabled' => 'confirmado'])
        : ui_button(lang('Ui.next'), 'primary', ['type' => 'button', '@click' => 'proximo()']))
    . '</div></div>';
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [
        [lang('Agendamento.reservationsTitle'), site_url('agendamento/reservas')],
        [$titulo, null],
    ]], ['saveData' => false]) ?>

    <h1 class="mb-space-6 text-display"><?= esc($titulo) ?></h1>

    <?php if (isset($erros['geral'])) : ?>
        <?= ui_alert('critical', $erros['geral']) ?>
    <?php endif ?>
    <?php if (isset($erros['beneficiario_id']) && ! $terceiros) : ?>
        <?php /* Sem a permissão, o campo "Para quem" nem aparece: o erro vai no topo. */ ?>
        <?= ui_alert('critical', $erros['beneficiario_id']) ?>
    <?php endif ?>

    <div x-data="ambientaReserva(<?= esc(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'attr') ?>)" class="max-w-6xl">
        <div role="tablist" aria-label="<?= esc(lang('Agendamento.stepsLabel'), 'attr') ?>" class="mb-space-6 flex flex-wrap gap-x-space-2 border-b border-border">
            <?php foreach ($abas as $n => $rotulo) : ?>
                <button type="button" role="tab" id="aba-<?= $n ?>" aria-controls="painel-<?= $n ?>"
                        :aria-selected="passo === <?= $n ?> ? 'true' : 'false'"
                        <?= $n > 1 ? ':disabled="! buscado"' : '' ?>
                        @click="ir(<?= $n ?>)"
                        class="-mb-px min-h-11 border-b-4 px-space-4 text-body-strong disabled:cursor-not-allowed disabled:opacity-50"
                        :class="passo === <?= $n ?> ? 'border-brand text-ink' : 'border-transparent text-ink-muted hover:text-ink'">
                    <?= esc($rotulo) ?>
                </button>
            <?php endforeach ?>
        </div>

        <?php /* ---- 1. Quando ---- */ ?>
        <section id="painel-1" role="tabpanel" aria-labelledby="aba-1" x-show="passo === 1" <?= $passoInicial === 1 ? '' : 'x-cloak' ?> class="<?= $painel ?> max-w-2xl">
            <form x-ref="busca" method="get" action="<?= site_url('agendamento/reservas/novo') ?>" @submit.prevent="proximo()">
                <?= ui_field('data', lang('Agendamento.fieldDate'), 'date', $busca['data'], $erros['data'] ?? null, null, ['required' => true]) ?>
                <div class="grid gap-x-space-4 sm:grid-cols-2">
                    <?= ui_field('inicio', lang('Agendamento.fieldStartTime'), 'time', $busca['inicio'], null, null, ['required' => true]) ?>
                    <?= ui_field('fim', lang('Agendamento.fieldEndTime'), 'time', $busca['fim'], null, null, ['required' => true]) ?>
                </div>
                <?= ui_select_fonte('disciplina', lang('Agendamento.fieldForDiscipline'), $fonteDisc, [], $temDisciplina ? $busca['disciplina'] : null, lang('Agendamento.noDiscipline'), null, lang('Agendamento.forDisciplineHelp')) ?>
                <?= ui_checkbox('ocupados', lang('Agendamento.showBusy'), $busca['ocupados']) ?>
            </form>
            <?= $botoesPasso(true, false) ?>
        </section>

        <?php if ($situacao !== null) : ?>
            <form x-ref="form" method="post" action="<?= site_url('agendamento/reservas') ?>"
                  @submit="if (! confirmado) { $event.preventDefault(); }"
                  @keydown.enter="if ($event.target.tagName === 'INPUT') { $event.preventDefault(); }">
                <?= csrf_field() ?>
                <input type="hidden" name="data" value="<?= esc($busca['data'], 'attr') ?>">
                <input type="hidden" name="inicio" value="<?= esc($busca['inicio'], 'attr') ?>">
                <input type="hidden" name="fim" value="<?= esc($busca['fim'], 'attr') ?>">
                <input type="hidden" name="ocupados" value="<?= $busca['ocupados'] ? '1' : '0' ?>">
                <input type="hidden" name="disciplina" value="<?= esc($busca['disciplina'], 'attr') ?>">
                <input type="hidden" name="ambiente_id" :value="ambienteId">
                <input type="hidden" name="ciente" value="" x-ref="ciente">
                <template x-for="item in itens" :key="item.id"><input type="hidden" :name="'itens[' + item.id + ']'" :value="item.qtd"></template>
                <template x-for="software in softwares" :key="software.id"><input type="hidden" name="softwares[]" :value="software.id"></template>

                <?php /* ---- 2. Onde e o quê ---- */ ?>
                <section id="painel-2" role="tabpanel" aria-labelledby="aba-2" x-show="passo === 2" <?= $passoInicial === 2 ? '' : 'x-cloak' ?> class="<?= $painel ?>">
                    <?php if (isset($erros['ambiente_id'])) : ?>
                        <?= ui_alert('critical', $erros['ambiente_id']) ?>
                    <?php endif ?>
                    <?php if ($linhas === []) : ?>
                        <?= ui_alert('pending', lang($busca['ocupados'] ? 'Agendamento.noEnvironments' : 'Agendamento.noFreeEnvironments')) ?>
                    <?php else : ?>
                        <?php if ($busca['ocupados']) : ?>
                            <p class="mb-space-4 text-caption text-ink-muted"><?= esc(lang('Agendamento.busyHelp')) ?></p>
                        <?php endif ?>
                        <div class="mb-space-4 flex flex-wrap items-end justify-between gap-space-4">
                            <div class="w-full max-w-sm">
                                <label for="filtro-ambientes" class="mb-space-1 block text-body"><?= esc(lang('Agendamento.filterEnvironments')) ?></label>
                                <input type="search" id="filtro-ambientes" x-model="filtro" autocomplete="off" maxlength="100"
                                       @keydown.enter.prevent
                                       class="min-h-11 w-full rounded-sm border border-border-strong bg-surface px-space-3 text-body shadow-xs focus:border-brand">
                            </div>
                            <p class="text-caption text-ink-muted" role="status" aria-live="polite">
                                <span x-text="visiveis"></span> / <?= count($linhas) ?>
                            </p>
                        </div>

                        <div class="overflow-x-auto rounded-md border border-border bg-surface shadow-xs">
                            <table class="w-full text-left text-data">
                                <caption class="sr-only"><?= esc(lang('Agendamento.stepWhere')) ?></caption>
                                <thead class="border-b border-border text-caption text-ink-muted">
                                    <tr>
                                        <th scope="col" class="<?= $th ?>"><?= esc(lang('Agendamento.fieldEnvironment')) ?></th>
                                        <th scope="col" class="<?= $th ?> text-right"><?= esc(lang('Agendamento.colCapacity')) ?></th>
                                        <th scope="col" class="<?= $th ?>"><?= esc(lang('Agendamento.colAvailability')) ?></th>
                                        <?php if ($temDisciplina) : ?>
                                            <th scope="col" class="<?= $th ?>"><?= esc(lang('Agendamento.colFulfilment')) ?></th>
                                        <?php endif ?>
                                        <th scope="col" class="<?= $th ?> text-right"><?= esc(lang('Ui.actions')) ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($linhas as $id => $linha) : ?>
                                        <tr x-show="combina('<?= $id ?>')" :class="ambienteId === '<?= $id ?>' && 'bg-brand/5'" class="border-b border-border align-middle last:border-b-0">
                                            <th scope="row" class="<?= $td ?> font-semibold">
                                                <?= esc($linha['nome']) ?>
                                                <span class="block text-caption font-normal text-ink-muted"><?= esc($linha['local']) ?></span>
                                                <span x-show="ambienteId === '<?= $id ?>'" x-cloak class="mt-space-1 block text-caption font-normal">
                                                    <?= ui_status_dot('confirmed', lang('Agendamento.selectedEnv')) ?>
                                                </span>
                                            </th>
                                            <td class="<?= $td ?> text-right tabular-nums"><?= esc((string) $linha['capacidade']) ?></td>
                                            <td class="<?= $td ?>"><?= ui_status_dot($linha['motivo'] === null ? 'confirmed' : 'critical', $linha['situacao']) ?></td>
                                            <?php if ($temDisciplina) : ?>
                                                <td class="<?= $td ?>"><?= $linha['nivel'] === '' ? '' : ui_status_dot($linha['tom'], $linha['atendimento']) ?></td>
                                            <?php endif ?>
                                            <td class="px-space-4 py-space-1">
                                                <div class="flex justify-end gap-space-2">
                                                    <?= ui_button(lang('Agendamento.viewEnvironment'), 'secondary', [
                                                        'type'       => 'button',
                                                        'aria-label' => lang('Agendamento.viewEnvironment') . ': ' . $linha['nome'],
                                                        '@click'     => "abrirResumo('{$id}')",
                                                    ]) ?>
                                                    <?php /* Seleção única: escolher um ambiente desmarca o anterior (um só ambienteId). */ ?>
                                                    <button type="button" @click="selecionar('<?= $id ?>')"
                                                            :aria-pressed="ambienteId === '<?= $id ?>' ? 'true' : 'false'"
                                                            aria-label="<?= esc(lang('Agendamento.selectLabel') . ': ' . $linha['nome'], 'attr') ?>"
                                                            :class="ambienteId === '<?= $id ?>' ? 'bg-brand text-brand-ink' : 'border border-border-strong bg-surface text-ink hover:bg-paper'"
                                                            class="inline-flex min-h-11 items-center justify-center rounded-sm px-space-4 text-body-strong shadow-xs">
                                                        <span x-text="ambienteId === '<?= $id ?>' ? '<?= esc(lang('Agendamento.selectedEnv'), 'js') ?>' : '<?= esc(lang('Agendamento.selectLabel'), 'js') ?>'"></span>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach ?>
                                    <tr x-show="visiveis === 0" x-cloak>
                                        <td colspan="<?= $temDisciplina ? 5 : 4 ?>" class="px-space-4 py-space-8 text-center text-body text-ink-muted"><?= esc(lang('Agendamento.noEnvironmentMatches')) ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php endif ?>
                    <div class="mt-space-4"><?= $aviso ?></div>
                    <?= $botoesPasso(false, false) ?>
                </section>

                <?php /* ---- 3. Detalhes ---- */ ?>
                <section id="painel-3" role="tabpanel" aria-labelledby="aba-3" x-show="passo === 3" <?= $passoInicial === 3 ? '' : 'x-cloak' ?> class="<?= $painel ?> max-w-2xl">
                    <?= ui_field('titulo', lang('Agendamento.fieldTitle'), 'text', (string) ($dados['titulo'] ?? ''), $erros['titulo'] ?? null, null, ['required' => true, 'maxlength' => '255', 'x-model' => 'titulo']) ?>

                    <?php if ($terceiros) : ?>
                        <?= ui_select_fonte('beneficiario_id', lang('Agendamento.fieldFor'), 'usuarios', ['chave' => 'agendamento.reservar_em_nome_de_terceiros', 'nivel' => 'editor'], $dados['beneficiario_id'] ?? null, lang('Agendamento.forMyself'), $erros['beneficiario_id'] ?? null, lang('Agendamento.forHelp')) ?>
                    <?php endif ?>

                    <fieldset class="mb-space-4 rounded-sm border border-border p-space-4">
                        <legend class="px-space-1 text-body-strong"><?= esc(lang('Agendamento.repeat')) ?></legend>
                        <?= ui_select('repetir', lang('Agendamento.fieldRepeat'), ['' => lang('Agendamento.repeatNone')] + opcoes_traduzidas('Agendamento.recurrence_', Recorrencia::TIPOS), (string) ($dados['repetir'] ?? ''), $erros['repetir'] ?? null) ?>
                        <div class="grid gap-x-space-4 sm:grid-cols-2">
                            <?= ui_field('repetir_ate', lang('Agendamento.fieldRepeatUntil'), 'date', (string) ($dados['repetir_ate'] ?? ''), $erros['repetir_ate'] ?? null) ?>
                            <?= ui_field('repetir_vezes', lang('Agendamento.fieldRepeatTimes'), 'number', (string) ($dados['repetir_vezes'] ?? ''), null, lang('Agendamento.repeatHelp'), ['min' => '2', 'max' => (string) Recorrencia::MAXIMO]) ?>
                        </div>
                    </fieldset>

                    <?php /* Interruptor Sim/Não; travado em Não quando não há o que ignorar (sem disciplina ou ambiente que já atende). */ ?>
                    <div class="mb-space-4">
                        <label class="inline-flex min-h-11 cursor-pointer items-center gap-space-3 has-[:disabled]:cursor-not-allowed">
                            <input type="checkbox" role="switch" name="ignorar" value="1" x-model="ignorar" :disabled="ignorarDesativado"
                                   aria-describedby="ignorar-ajuda" class="toggle">
                            <span class="text-body-strong"><?= esc(lang('Agendamento.ignoreRequirements')) ?></span>
                            <span class="min-w-8 text-body" x-text="ignorar ? t.yes : t.no"></span>
                        </label>
                        <p id="ignorar-ajuda" class="text-caption text-ink-muted" x-text="ajudaIgnorar"></p>
                        <?php if (isset($erros['ciente'])) : ?>
                            <?php /* Rede de segurança: o servidor ainda pergunta se o envio não passou pela confirmação do resumo. */ ?>
                            <div class="mt-space-2 rounded-sm border border-signal-pending/40 bg-signal-pending/5 p-space-4">
                                <p class="mb-space-2 text-body"><?= esc($erros['ciente']) ?></p>
                                <div class="flex min-h-11 items-center gap-space-3">
                                    <input type="checkbox" id="campo-ciente-aviso" role="switch" class="toggle"
                                           @change="$refs.ciente.value = $event.target.checked ? '1' : ''">
                                    <label for="campo-ciente-aviso" class="text-body"><?= esc(lang('Agendamento.fulfilmentAware')) ?></label>
                                </div>
                            </div>
                        <?php endif ?>
                    </div>

                    <?= $aviso ?>
                    <?= $botoesPasso(false, false) ?>
                </section>

                <?php /* ---- 4. Equipamentos e softwares extras ---- */ ?>
                <section id="painel-4" role="tabpanel" aria-labelledby="aba-4" x-show="passo === 4" x-cloak class="<?= $painel ?>">
                    <p class="mb-space-4 text-caption text-ink-muted"><?= esc(lang('Agendamento.extrasHelp')) ?></p>
                    <div class="grid items-start gap-space-6 lg:grid-cols-2">
                        <div class="min-w-0">
                            <div class="mb-space-3 flex flex-wrap items-center justify-between gap-space-3">
                                <h2 class="text-title"><?= esc(lang('Agendamento.extraEquipment')) ?></h2>
                                <?= ui_button(lang('Agendamento.addEquipment'), 'primary', ['type' => 'button', '@click' => "erroExtra = ''; \$dispatch('open-modal', 'reserva-item')"]) ?>
                            </div>
                            <div class="overflow-x-auto rounded-md border border-border bg-surface shadow-xs">
                                <table class="w-full text-left text-data">
                                    <caption class="sr-only"><?= esc(lang('Agendamento.extraEquipment')) ?></caption>
                                    <thead class="border-b border-border text-caption text-ink-muted">
                                        <tr>
                                            <th scope="col" class="<?= $th ?>"><?= esc(lang('Agendamento.fieldItemCategory')) ?></th>
                                            <th scope="col" class="<?= $th ?> text-right"><?= esc(lang('Agendamento.fieldQuantity')) ?></th>
                                            <th scope="col" class="<?= $th ?> text-right"><?= esc(lang('Ui.actions')) ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr x-show="itens.length === 0"><td colspan="3" class="px-space-4 py-space-8 text-center text-body text-ink-muted"><?= esc(lang('Agendamento.noExtraItems')) ?></td></tr>
                                        <template x-for="item in itens" :key="item.id">
                                            <tr class="border-b border-border align-middle last:border-b-0">
                                                <th scope="row" class="<?= $td ?> font-semibold" x-text="item.rotulo"></th>
                                                <td class="<?= $td ?> text-right tabular-nums" x-text="item.qtd"></td>
                                                <td class="px-space-4 py-space-1">
                                                    <div class="flex justify-end">
                                                        <?= ui_button(lang('Agendamento.removeExtra'), 'secondary', ['type' => 'button', ':aria-label' => "'" . esc(lang('Agendamento.removeExtra'), 'js') . ": ' + item.rotulo", '@click' => 'removerItem(item.id)']) ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="min-w-0">
                            <div class="mb-space-3 flex flex-wrap items-center justify-between gap-space-3">
                                <h2 class="text-title"><?= esc(lang('Agendamento.extraSoftware')) ?></h2>
                                <?= ui_button(lang('Agendamento.addSoftwareExtra'), 'primary', ['type' => 'button', '@click' => "erroExtra = ''; \$dispatch('open-modal', 'reserva-software')"]) ?>
                            </div>
                            <div class="overflow-x-auto rounded-md border border-border bg-surface shadow-xs">
                                <table class="w-full text-left text-data">
                                    <caption class="sr-only"><?= esc(lang('Agendamento.extraSoftware')) ?></caption>
                                    <thead class="border-b border-border text-caption text-ink-muted">
                                        <tr>
                                            <th scope="col" class="<?= $th ?>"><?= esc(lang('Agendamento.fieldSoftware')) ?></th>
                                            <th scope="col" class="<?= $th ?> text-right"><?= esc(lang('Ui.actions')) ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr x-show="softwares.length === 0"><td colspan="2" class="px-space-4 py-space-8 text-center text-body text-ink-muted"><?= esc(lang('Agendamento.noExtraSoftware')) ?></td></tr>
                                        <template x-for="software in softwares" :key="software.id">
                                            <tr class="border-b border-border align-middle last:border-b-0">
                                                <th scope="row" class="<?= $td ?> font-semibold" x-text="software.rotulo"></th>
                                                <td class="px-space-4 py-space-1">
                                                    <div class="flex justify-end">
                                                        <?= ui_button(lang('Agendamento.removeExtra'), 'secondary', ['type' => 'button', ':aria-label' => "'" . esc(lang('Agendamento.removeExtra'), 'js') . ": ' + software.rotulo", '@click' => 'removerSoftware(software.id)']) ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?= $botoesPasso(false, false) ?>
                </section>

                <?php /* ---- 5. Resumo ---- */ ?>
                <section id="painel-5" role="tabpanel" aria-labelledby="aba-5" x-show="passo === 5" x-cloak class="<?= $painel ?>">
                    <h2 class="mb-space-4 text-title"><?= esc(lang('Agendamento.summaryTitle')) ?></h2>
                    <div class="grid gap-space-4 lg:grid-cols-2">
                        <section class="<?= $cartao ?>" aria-labelledby="resumo-quando">
                            <h3 id="resumo-quando" class="<?= $subtitulo ?>"><?= esc(lang('Agendamento.summaryWhen')) ?></h3>
                            <dl class="grid grid-cols-[minmax(0,11rem)_minmax(0,1fr)] gap-x-space-4 gap-y-space-2 text-body">
                                <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldDate')) ?></dt>
                                <dd><?= esc($dataFmt) ?></dd>
                                <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldStartTime')) ?></dt>
                                <dd class="tabular-nums"><?= esc($busca['inicio']) ?></dd>
                                <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldEndTime')) ?></dt>
                                <dd class="tabular-nums"><?= esc($busca['fim']) ?></dd>
                                <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldForDiscipline')) ?></dt>
                                <dd><?= esc($disciplina ?? lang('Agendamento.noDiscipline')) ?></dd>
                            </dl>
                        </section>

                        <section class="<?= $cartao ?>" aria-labelledby="resumo-onde">
                            <h3 id="resumo-onde" class="<?= $subtitulo ?>"><?= esc(lang('Agendamento.summaryWhere')) ?></h3>
                            <dl class="grid grid-cols-[minmax(0,11rem)_minmax(0,1fr)] gap-x-space-4 gap-y-space-2 text-body">
                                <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldEnvironment')) ?></dt>
                                <dd x-text="ambiente?.rotulo"></dd>
                                <?php if ($temDisciplina) : ?>
                                    <dt class="text-ink-muted"><?= esc(lang('Agendamento.fulfilmentTitle')) ?></dt>
                                    <dd><?= $ponto('ambiente?.tom', 'ambiente?.atendimento') ?></dd>
                                <?php endif ?>
                            </dl>
                            <p x-show="avisoAtendimento !== ''" x-cloak class="mt-space-3 rounded-sm border border-signal-pending/40 bg-signal-pending/5 p-space-3 text-body" x-text="avisoAtendimento"></p>
                        </section>

                        <section class="<?= $cartao ?>" aria-labelledby="resumo-detalhes">
                            <h3 id="resumo-detalhes" class="<?= $subtitulo ?>"><?= esc(lang('Agendamento.summaryDetails')) ?></h3>
                            <dl class="grid grid-cols-[minmax(0,11rem)_minmax(0,1fr)] gap-x-space-4 gap-y-space-2 text-body">
                                <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldTitle')) ?></dt>
                                <dd x-text="res.titulo"></dd>
                                <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldFor')) ?></dt>
                                <dd x-text="res.paraQuem"></dd>
                                <dt class="text-ink-muted"><?= esc(lang('Agendamento.repeat')) ?></dt>
                                <dd x-text="res.repeticao"></dd>
                                <?php if ($temDisciplina) : ?>
                                    <dt class="text-ink-muted"><?= esc(lang('Agendamento.ignoreRequirements')) ?></dt>
                                    <dd x-text="ignorar ? t.yes : t.no"></dd>
                                <?php endif ?>
                            </dl>
                        </section>

                        <section class="<?= $cartao ?>" aria-labelledby="resumo-extras">
                            <h3 id="resumo-extras" class="<?= $subtitulo ?>"><?= esc(lang('Agendamento.summaryExtras')) ?></h3>
                            <p class="text-body-strong"><?= esc(lang('Agendamento.extraEquipment')) ?></p>
                            <p x-show="itens.length === 0" class="mb-space-3 text-body text-ink-muted"><?= esc(lang('Agendamento.noExtraItems')) ?></p>
                            <ul class="mb-space-3 list-disc pl-space-6 text-body">
                                <template x-for="item in itens" :key="item.id"><li><span x-text="item.rotulo"></span> · <span class="tabular-nums" x-text="item.qtd"></span></li></template>
                            </ul>
                            <p class="text-body-strong"><?= esc(lang('Agendamento.extraSoftware')) ?></p>
                            <p x-show="softwares.length === 0" class="text-body text-ink-muted"><?= esc(lang('Agendamento.noExtraSoftware')) ?></p>
                            <ul class="list-disc pl-space-6 text-body">
                                <template x-for="software in softwares" :key="software.id"><li x-text="software.rotulo"></li></template>
                            </ul>
                        </section>
                    </div>
                    <?= $botoesPasso(false, true) ?>
                </section>
            </form>

            <?php /* Os modais ficam fora do <form>: os campos deles não são enviados. */ ?>
            <?php
            ob_start();
            ?>
            <dl class="grid grid-cols-[minmax(0,11rem)_minmax(0,1fr)] gap-x-space-4 gap-y-space-2 text-body">
                <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldLocation')) ?></dt>
                <dd x-text="ver?.local"></dd>
                <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldCategory')) ?></dt>
                <dd x-text="ver?.categoria"></dd>
                <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldCapacity')) ?></dt>
                <dd x-text="ver ? t.seats.replace('{0}', ver.capacidade) : ''"></dd>
                <dt class="text-ink-muted" x-show="ver?.tamanho"><?= esc(lang('Agendamento.fieldSize')) ?></dt>
                <dd x-show="ver?.tamanho" x-text="ver?.tamanho"></dd>
                <dt class="text-ink-muted"><?= esc(lang('Agendamento.colAvailability')) ?></dt>
                <dd x-text="ver?.situacao"></dd>
                <?php if ($temDisciplina) : ?>
                    <dt class="text-ink-muted"><?= esc(lang('Agendamento.fulfilmentTitle')) ?></dt>
                    <dd x-text="ver?.atendimento"></dd>
                <?php endif ?>
                <dt class="text-ink-muted" x-show="ver?.descricao"><?= esc(lang('Agendamento.fieldDescription')) ?></dt>
                <dd x-show="ver?.descricao" x-text="ver?.descricao"></dd>
            </dl>
            <h3 class="mb-space-1 mt-space-4 text-body-strong"><?= esc(lang('Agendamento.installedSoftware')) ?></h3>
            <p x-show="! ver || ver.softwares.length === 0" class="text-body text-ink-muted"><?= esc(lang('Agendamento.noneRegistered')) ?></p>
            <ul class="list-disc pl-space-6 text-body"><template x-for="nome in (ver?.softwares ?? [])" :key="nome"><li x-text="nome"></li></template></ul>
            <h3 class="mb-space-1 mt-space-4 text-body-strong"><?= esc(lang('Agendamento.equipment')) ?></h3>
            <p x-show="! ver || ver.itens.length === 0" class="text-body text-ink-muted"><?= esc(lang('Agendamento.noneRegistered')) ?></p>
            <ul class="list-disc pl-space-6 text-body"><template x-for="item in (ver?.itens ?? [])" :key="item.nome"><li><span x-text="item.nome"></span> · <span class="tabular-nums" x-text="item.total"></span></li></template></ul>
            <?php $corpoResumo = (string) ob_get_clean(); ?>
            <?= ui_modal(
                'reserva-ambiente',
                lang('Agendamento.environmentSummary'),
                $corpoResumo,
                ui_button(lang('Agendamento.selectEnvironment'), 'primary', ['type' => 'button', '@click' => 'selecionar(verId); close()']),
                lang('Ui.close')
            ) ?>

            <?= ui_modal(
                'reserva-item',
                lang('Agendamento.addEquipment'),
                ui_select_fonte('extra_categoria', lang('Agendamento.fieldItemCategory'), 'categorias-item', [], null, lang('Ui.select'))
                . ui_field('extra_quantidade', lang('Agendamento.fieldQuantity'), 'number', '1', null, null, ['min' => '1', 'max' => '999'])
                . '<p x-show="erroExtra !== \'\'" x-cloak role="alert" class="text-data text-signal-critical" x-text="erroExtra"></p>',
                ui_button(lang('Ui.add'), 'primary', ['type' => 'button', '@click' => 'adicionarItem() && close()']),
                lang('Ui.cancel')
            ) ?>

            <?= ui_modal(
                'reserva-software',
                lang('Agendamento.addSoftwareExtra'),
                ui_select_fonte('extra_software', lang('Agendamento.fieldSoftware'), 'softwares', [], null, lang('Ui.select'))
                . '<p x-show="erroExtra !== \'\'" x-cloak role="alert" class="text-data text-signal-critical" x-text="erroExtra"></p>',
                ui_button(lang('Ui.add'), 'primary', ['type' => 'button', '@click' => 'adicionarSoftware() && close()']),
                lang('Ui.cancel')
            ) ?>

            <?php
            ob_start();
            ?>
            <p class="mb-space-4"><?= esc(lang('Agendamento.confirmText')) ?></p>
            <dl class="grid grid-cols-[minmax(0,11rem)_minmax(0,1fr)] gap-x-space-4 gap-y-space-2 text-body">
                <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldEnvironment')) ?></dt>
                <dd x-text="ambiente?.rotulo"></dd>
                <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldDate')) ?></dt>
                <dd class="tabular-nums"><?= esc($dataFmt . ' · ' . $busca['inicio'] . ' – ' . $busca['fim']) ?></dd>
                <dt class="text-ink-muted"><?= esc(lang('Agendamento.fieldTitle')) ?></dt>
                <dd x-text="res.titulo"></dd>
            </dl>
            <p x-show="avisoAtendimento !== ''" x-cloak class="mt-space-4 rounded-sm border border-signal-pending/40 bg-signal-pending/5 p-space-3 text-body" x-text="avisoAtendimento"></p>
            <?php $corpoConfirmar = (string) ob_get_clean(); ?>
            <?= ui_modal(
                'reserva-confirmar',
                lang('Agendamento.confirmTitle'),
                $corpoConfirmar,
                ui_button(lang('Agendamento.confirmReserve'), 'primary', ['type' => 'button', '@click' => 'enviar()', ':disabled' => 'confirmado']),
                lang('Ui.cancel')
            ) ?>
        <?php endif ?>
    </div>
<?= $this->endSection() ?>

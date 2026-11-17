<?php

/**
 * Menu lateral (painel administrativo). Mostra só o que o usuário logado pode usar, por permissão;
 * cada módulo acrescenta os seus itens quando for construído.
 *
 * Estado vem do x-data "ambientaShell" do <body>: menuAberto (celular) e recolhido (desktop, só ícones;
 * os rótulos continuam lidos por leitor de tela via sr-only).
 */

$autorizacao = service('autorizacao');

$ativo = static fn (array $caminhos): bool => array_filter($caminhos, static fn (string $c): bool => url_is($c)) !== [];

// Grupo => lista de itens. Item: [rota, rótulo, ícone, caminhos] ou [null, rótulo, ícone, subitens].
$grupos = [
    lang('Ui.navGroupMenu') => [
        ['/', lang('Ui.navHome'), 'inicio', ['/']],
        ['consulta', lang('Ui.navConsulta'), 'busca', ['consulta*']],
        ['notificacoes', lang('Notificacoes.nav'), 'sino', ['notificacoes*']],
    ],
];

if ($autorizacao->pode('institucional.acesso')) {
    $grupos[lang('Ui.navGroupMenu')][] = [null, lang('Ui.navInstitutional'), 'predio', [
        // As páginas de um prédio/ambiente (predios/5) seguem na trilha do Campus; as listas gerais têm item próprio.
        ['campus', lang('Institucional.navCampus'), ['campus*', 'predios/*', 'ambientes/*']],
        ['predios', lang('Institucional.navBuildings'), ['predios']],
        ['ambientes', lang('Institucional.navEnvironments'), ['ambientes']],
        ['categorias-ambiente', lang('Institucional.navCategories'), ['categorias-ambiente*']],
    ]];
}

// Acadêmico: cada item respeita a permissão da sua própria tela. Atribuições de aulas é de todo usuário logado
// (cada um vê as suas; ver GestaoAtribuicoes), então o grupo sempre aparece.
$itensAcademico = [];
if ($autorizacao->pode('academico.acesso')) {
    $itensAcademico[] = ['cursos', lang('Institucional.navCourses'), ['cursos*']];
    $itensAcademico[] = ['disciplinas', lang('Institucional.navDisciplines'), ['disciplinas*']];
    $itensAcademico[] = ['semestres', lang('Institucional.navSemesters'), ['semestres*']];
}
$itensAcademico[] = ['atribuicoes', lang('Agendamento.navLinks'), ['atribuicoes*']];
if ($autorizacao->pode('academico.requisitos')) {
    $itensAcademico[] = ['requisitos', lang('Chamados.navRequirements'), ['requisitos*']];
}
if ($itensAcademico !== []) {
    $grupos[lang('Ui.navGroupMenu')][] = [null, lang('Ui.navAcademic'), 'curso', $itensAcademico];
}

if ($autorizacao->pode('patrimonio.acesso')) {
    $grupos[lang('Ui.navGroupMenu')][] = [null, lang('Patrimonio.navAssets'), 'caixa', [
        ['patrimonio/itens', lang('Patrimonio.navItems'), ['patrimonio/itens*', 'patrimonio/numeros*']],
        ['patrimonio/movimentacoes', lang('Patrimonio.navMovements'), ['patrimonio/movimentacoes*']],
        ['patrimonio/descartes', lang('Patrimonio.navDisposals'), ['patrimonio/descartes*']],
        ['patrimonio/modelos', lang('Patrimonio.navModels'), ['patrimonio/modelos*']],
        ['patrimonio/categorias', lang('Patrimonio.navCategories'), ['patrimonio/categorias*']],
        ['patrimonio/almoxarifados', lang('Patrimonio.navWarehouses'), ['patrimonio/almoxarifados*']],
        ['patrimonio/softwares', lang('Patrimonio.navSoftware'), ['patrimonio/softwares', 'patrimonio/softwares/*/editar']],
        ...($autorizacao->pode('patrimonio.aprovar_software', 'editor')
            ? [['patrimonio/softwares/pendentes', lang('Patrimonio.navSoftwareQueue'), ['patrimonio/softwares/pendentes*', 'patrimonio/softwares/*/revisar']]]
            : []),
    ]];
}

if ($autorizacao->pode('agendamento.acesso')) {
    $itensAgenda = [
        ['agendamento/agenda', lang('Agendamento.navAgenda'), ['agendamento/agenda*']],
        ['agendamento/reservas', lang('Agendamento.navReservations'), ['agendamento/reservas*']],
    ];
    if ($autorizacao->pode('agendamento.confirmar_reserva', 'editor')) {
        $itensAgenda[] = ['agendamento/confirmacoes', lang('Agendamento.navQueue'), ['agendamento/confirmacoes*']];
    }
    if ($autorizacao->pode('agendamento.autorizar_conflito', 'editor')) {
        $itensAgenda[] = ['agendamento/conflitos', lang('Agendamento.navConflicts'), ['agendamento/conflitos*']];
    }
    $itensAgenda[] = ['agendamento/alocacoes', lang('Agendamento.navAllocations'), ['agendamento/alocacoes*']];
    $itensAgenda[] = ['agendamento/bloqueios', lang('Agendamento.navBlocks'), ['agendamento/bloqueios*']];
    $itensAgenda[] = ['agendamento/feriados', lang('Agendamento.navHolidays'), ['agendamento/feriados*']];

    $grupos[lang('Ui.navGroupMenu')][] = [null, lang('Agendamento.navGroup'), 'relogio', $itensAgenda];
}

if ($autorizacao->pode('relatorios.acesso')) {
    $grupos[lang('Ui.navGroupMenu')][] = ['relatorios', lang('Relatorios.nav'), 'documento', ['relatorios*']];
}

// Manutenção: chamados e, agora, as telas que ficavam em "Administração" (cada uma com a sua permissão).
$itensManutencao = [];
if ($autorizacao->pode('chamados.acesso')) {
    $itensManutencao[] = ['chamados', lang('Chamados.navTickets'), ['chamados*']];
}
if ($autorizacao->pode('usuarios.acesso')) {
    $itensManutencao[] = ['usuarios', lang('Ui.navUsers'), ['usuarios*']];
}
if ($autorizacao->ehSuper()) {
    $itensManutencao[] = ['perfis', lang('Ui.navProfiles'), ['perfis*']];
    $itensManutencao[] = ['dominios', lang('Ui.navDomains'), ['dominios*']];
}
if ($autorizacao->pode('auditoria.consultar', 'editor')) {
    $itensManutencao[] = ['auditoria', lang('Auditoria.nav'), ['auditoria*']];
}
if ($itensManutencao !== []) {
    $grupos[lang('Ui.navGroupMenu')][] = [null, lang('Chamados.navGroup'), 'chave', $itensManutencao];
}

$classeItem = 'group flex min-h-11 w-full items-center gap-space-3 rounded-sm px-space-3 text-body no-underline';
$classeAtivo   = 'bg-brand/10 font-semibold text-brand';
$classeInativo = 'text-ink hover:bg-paper';
?>
<aside id="menu-lateral" aria-label="<?= esc(lang('Ui.mainNav'), 'attr') ?>"
       :data-aberto="menuAberto" :class="recolhido ? 'xl:w-20' : 'xl:w-72'"
       class="fixed inset-y-0 left-0 z-50 flex w-72 -translate-x-full flex-col border-r border-border bg-surface transition-all duration-200 data-aberto:translate-x-0 data-aberto:shadow-lg xl:static xl:translate-x-0 xl:shadow-none">

    <div class="flex min-h-18 items-center justify-between gap-space-2 border-b border-brand bg-brand text-brand-ink px-space-4"
         :class="recolhido && 'xl:justify-center xl:px-0'">
        <a href="<?= site_url('/') ?>" class="flex min-h-11 items-center gap-space-3 no-underline">
            <span class="flex size-9 shrink-0 items-center justify-center rounded-sm bg-brand-ink text-heading text-brand" aria-hidden="true">A</span>
            <span class="text-title text-brand-ink" :class="recolhido && 'xl:sr-only'"><?= esc(lang('App.name')) ?></span>
        </a>
        <button type="button" @click="menuAberto = false" aria-label="<?= esc(lang('Ui.closeMenu'), 'attr') ?>"
                class="inline-flex size-11 items-center justify-center rounded-sm text-brand-ink hover:bg-brand-ink/10 xl:hidden">
            <?= ui_icon('fechar') ?>
        </button>
    </div>

    <nav class="flex-1 overflow-y-auto px-space-4 py-space-6" :class="recolhido && 'xl:px-space-3'">
        <?php foreach (array_keys($grupos) as $g => $tituloGrupo) : ?>
            <?php $itens = $grupos[$tituloGrupo]; ?>
            <p id="grupo-menu-<?= $g ?>" class="mb-space-2 px-space-3 text-caption uppercase tracking-wide text-ink-muted"
               :class="recolhido && 'xl:sr-only'"><?= esc($tituloGrupo) ?></p>
            <ul aria-labelledby="grupo-menu-<?= $g ?>" class="mb-space-6 flex flex-col gap-space-1">
                <?php foreach ($itens as [$rota, $rotulo, $icone, $alvo]) : ?>
                    <?php if ($rota !== null) : ?>
                        <?php $atual = $ativo($alvo); ?>
                        <li>
                            <a href="<?= site_url($rota) ?>"<?= $atual ? ' aria-current="page"' : '' ?> title="<?= esc($rotulo, 'attr') ?>"
                               class="<?= $classeItem ?> <?= $atual ? $classeAtivo : $classeInativo ?>"
                               :class="recolhido && 'xl:justify-center'">
                                <?= ui_icon($icone, 'size-5 ' . ($atual ? 'text-brand' : 'text-ink-muted group-hover:text-ink')) ?>
                                <span :class="recolhido && 'xl:sr-only'"><?= esc($rotulo) ?></span>
                            </a>
                        </li>
                    <?php else : ?>
                        <?php
                        $caminhosGrupo = array_merge(...array_column($alvo, 2));
                        $grupoAtual    = $ativo($caminhosGrupo);
                        $idSubmenu     = 'submenu-' . $icone;
                        ?>
                        <li x-data="{ aberto: <?= $grupoAtual ? 'true' : 'false' ?> }">
                            <button type="button" title="<?= esc($rotulo, 'attr') ?>"
                                    @click="if (recolhido && telaLarga()) { expandir(); aberto = true } else { aberto = ! aberto }"
                                    :aria-expanded="aberto.toString()" aria-expanded="<?= $grupoAtual ? 'true' : 'false' ?>" aria-controls="<?= $idSubmenu ?>"
                                    class="<?= $classeItem ?> text-left <?= $grupoAtual ? $classeAtivo : $classeInativo ?>"
                                    :class="recolhido && 'xl:justify-center'">
                                <?= ui_icon($icone, 'size-5 ' . ($grupoAtual ? 'text-brand' : 'text-ink-muted group-hover:text-ink')) ?>
                                <span class="flex-1" :class="recolhido && 'xl:sr-only'"><?= esc($rotulo) ?></span>
                                <span class="transition-transform" :class="[aberto && 'rotate-180', recolhido && 'xl:hidden']">
                                    <?= ui_icon('seta-baixo', 'size-4') ?>
                                </span>
                            </button>
                            <ul id="<?= $idSubmenu ?>" x-show="aberto"<?= $grupoAtual ? '' : ' x-cloak' ?>
                                :class="recolhido && 'xl:hidden'"
                                class="mt-space-1 ml-space-6 flex flex-col gap-space-1 border-l border-border pl-space-3">
                                <?php foreach ($alvo as [$subRota, $subRotulo, $subCaminhos]) : ?>
                                    <?php $subAtual = $ativo($subCaminhos); ?>
                                    <li>
                                        <a href="<?= site_url($subRota) ?>"<?= $subAtual ? ' aria-current="page"' : '' ?>
                                           class="flex min-h-11 items-center rounded-sm px-space-3 text-body no-underline <?= $subAtual ? $classeAtivo : 'text-ink-muted hover:bg-paper hover:text-ink' ?>">
                                            <?= esc($subRotulo) ?>
                                        </a>
                                    </li>
                                <?php endforeach ?>
                            </ul>
                        </li>
                    <?php endif ?>
                <?php endforeach ?>
            </ul>
        <?php endforeach ?>
    </nav>

    <p class="border-t border-brand bg-brand px-space-4 py-space-4 text-caption text-brand-ink" :class="recolhido && 'xl:sr-only'">
        <?= esc(lang('App.institution')) ?>
    </p>
</aside>

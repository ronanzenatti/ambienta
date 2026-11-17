<?php

/**
 * Página do ambiente: placa de ambiente, dados e foto, com a identidade do campus.
 *
 * @var array<string, mixed>      $ambiente
 * @var array<string, mixed>|null $categoria
 * @var array<string, mixed>      $predio
 * @var array<string, mixed>      $campus
 * @var bool                      $podeEditar
 * @var bool                      $podeEditarPatrimonio editor de patrimônio no prédio (softwares e itens)
 * @var App\Tabelas\Patrimonio\AmbienteSoftwaresTabela $tabelaSoftwares
 * @var App\Tabelas\Patrimonio\AmbienteItensTabela|null $tabelaItens null: sem leitura de patrimônio no prédio
 * @var App\Tabelas\Chamados\ChamadosTabela|null $tabelaChamados null: sem leitura de chamados no prédio
 * @var bool                      $podeAbrirChamado editor de chamados no prédio
 */
$id   = (int) $ambiente['id_ambiente'];
$nome = (string) $ambiente['nome'];

$confirmacao = match (true) {
    $ambiente['exige_confirmacao_reserva'] === null => lang('Ambientes.confirmInherit'),
    (bool) $ambiente['exige_confirmacao_reserva']   => lang('Ambientes.confirmYes'),
    default                                         => lang('Ambientes.confirmNo'),
};
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($nome) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [
        [lang('Campus.title'), site_url('campus')],
        [campus_rotulo($campus), site_url('campus/' . $campus['id_campus'])],
        [(string) $predio['nome'], site_url('predios/' . $predio['id_predio'])],
        [$nome, null],
    ]], ['saveData' => false]) ?>

    <div style="<?= esc(campus_estilo($campus), 'attr') ?>">
        <?= view('institucional/_faixa_campus', [
            'campus' => $campus,
            'titulo' => $nome,
            'sub'    => $predio['nome'] . ' · ' . campus_rotulo($campus),
            'acoes'  => $podeEditar
                ? ui_button(lang('Ui.edit'), 'edit', ['href' => site_url("ambientes/{$id}/editar")])
                    . ui_button(lang('Ui.delete'), 'delete', ['@click' => "\$dispatch('open-modal', 'excluir-ambiente')"])
                : null,
        ], ['saveData' => false]) ?>
        <?php if ($podeEditar) : ?>
            <?= ui_confirm_delete('excluir-ambiente', lang('Ambientes.deleteText', [$nome]), site_url("ambientes/{$id}"), lang('Ambientes.deleteTitle')) ?>
        <?php endif ?>

        <?= ui_recolhivel_inicio(lang('Ui.recordDetails')) ?>
        <div class="grid gap-space-6 md:grid-cols-2">
            <section aria-labelledby="ambiente-dados" class="rounded-md border border-border bg-surface shadow-xs p-space-6">
                <div class="mb-space-4"><?= ui_placa_ambiente((string) $ambiente['numero'], $nome) ?></div>
                <h2 id="ambiente-dados" class="sr-only"><?= esc(lang('Institucional.sectionData')) ?></h2>
                <dl class="grid grid-cols-[auto_1fr] gap-x-space-4 gap-y-space-2 text-body">
                    <dt class="text-ink-muted"><?= esc(lang('Institucional.fieldAbbreviation')) ?></dt>
                    <dd><?= esc($ambiente['abreviacao']) ?></dd>
                    <dt class="text-ink-muted"><?= esc(lang('Ambientes.fieldCategory')) ?></dt>
                    <dd><?= esc($categoria['nome'] ?? '') ?></dd>
                    <dt class="text-ink-muted"><?= esc(lang('Ambientes.fieldCapacity')) ?></dt>
                    <dd class="tabular-nums"><?= esc($ambiente['capacidade']) ?></dd>
                    <?php if ($ambiente['tamanho_m2'] !== null) : ?>
                        <dt class="text-ink-muted"><?= esc(lang('Ambientes.fieldSize')) ?></dt>
                        <dd class="tabular-nums"><?= esc(lang('Ambientes.sizeValue', [(string) $ambiente['tamanho_m2']])) ?></dd>
                    <?php endif ?>
                    <dt class="text-ink-muted"><?= esc(lang('Ambientes.fieldConfirmation')) ?></dt>
                    <dd><?= esc($confirmacao) ?></dd>
                    <dt class="text-ink-muted"><?= esc(lang('Ui.status')) ?></dt>
                    <dd><?= (bool) $ambiente['ativo'] ? ui_status_dot('confirmed', lang('Ui.active')) : ui_status_dot('critical', lang('Ui.inactive')) ?></dd>
                </dl>
                <?php if (! empty($ambiente['descricao'])) : ?>
                    <p class="mt-space-4 whitespace-pre-line text-body"><?= esc($ambiente['descricao']) ?></p>
                <?php endif ?>
            </section>

            <section aria-labelledby="ambiente-agenda-publica" class="rounded-md border border-border bg-surface p-space-6 shadow-xs">
                <h2 id="ambiente-agenda-publica" class="mb-space-2 text-title"><?= esc(lang('Ambientes.publicAgendaTitle')) ?></h2>
                <p class="mb-space-4 text-body text-ink-muted"><?= esc(lang('Ambientes.publicAgendaHelp')) ?></p>
                <div class="flex flex-wrap items-start gap-space-6">
                    <img src="<?= site_url("agenda/ambiente/{$id}/qrcode.svg") ?>" width="160" height="160"
                         alt="<?= esc(lang('Ambientes.qrAlt', [$nome]), 'attr') ?>" class="rounded-sm border border-border bg-surface">
                    <div class="flex flex-col items-start gap-space-3">
                        <?= ui_button(lang('Ambientes.qrDownload'), 'primary', ['href' => site_url("agenda/ambiente/{$id}/qrcode.svg") . '?baixar=1']) ?>
                        <?= ui_button(lang('Ambientes.publicAgendaOpen'), 'secondary', ['href' => site_url("agenda/ambiente/{$id}")]) ?>
                        <?= ui_button(lang('Agendamento.icalDownload'), 'secondary', ['href' => site_url("agenda/ambiente/{$id}/agenda.ics")]) ?>
                    </div>
                </div>
            </section>

            <?php if (! empty($ambiente['foto'])) : ?>
                <figure class="rounded-md border border-border bg-surface shadow-xs p-space-4">
                    <img src="<?= esc(arquivo_url((string) $ambiente['foto']), 'attr') ?>" alt="<?= esc(lang('Ambientes.photoAlt', [$nome]), 'attr') ?>" class="w-full rounded-sm object-cover">
                </figure>
            <?php endif ?>
        </div>
        <?= ui_recolhivel_fim() ?>

        <div class="grid items-start gap-space-6<?= $tabelaItens !== null ? ' lg:grid-cols-2' : '' ?>">
            <section aria-labelledby="ambiente-softwares-titulo" id="ambiente-softwares" class="min-w-0 rounded-md border border-border bg-surface p-space-6 shadow-xs">
                <div class="mb-space-4 flex flex-wrap items-center justify-between gap-space-4">
                    <h2 id="ambiente-softwares-titulo" class="text-title"><?= esc(lang('Patrimonio.softwaresInstalled')) ?></h2>
                    <div class="flex flex-wrap items-center gap-space-2">
                        <?= $tabelaSoftwares->botaoExportar() ?>
                        <?php if ($podeEditarPatrimonio) : ?>
                            <?= ui_button(lang('Patrimonio.addSoftwares'), 'primary', [
                                'href'   => site_url("ambientes/{$id}/softwares/novo"),
                                '@click' => 'ambientaAbrirFormulario($event)',
                            ]) ?>
                        <?php endif ?>
                    </div>
                </div>
                <?= $tabelaSoftwares->renderizar() ?>
            </section>

            <?php if ($tabelaItens !== null) : ?>
                <section aria-labelledby="ambiente-itens-titulo" id="ambiente-itens" class="min-w-0 rounded-md border border-border bg-surface p-space-6 shadow-xs">
                    <div class="mb-space-4 flex flex-wrap items-center justify-between gap-space-4">
                        <h2 id="ambiente-itens-titulo" class="text-title"><?= esc(lang('Patrimonio.itemsInEnvironment')) ?></h2>
                        <div class="flex flex-wrap items-center gap-space-2">
                            <?= $tabelaItens->botaoExportar() ?>
                            <?php if ($podeEditarPatrimonio) : ?>
                                <?= ui_button(lang('Patrimonio.addItems'), 'primary', [
                                    'href'   => site_url("ambientes/{$id}/itens/novo"),
                                    '@click' => 'ambientaAbrirFormulario($event)',
                                ]) ?>
                            <?php endif ?>
                        </div>
                    </div>
                    <?= $tabelaItens->renderizar() ?>
                </section>
            <?php endif ?>
        </div>

        <?php if ($tabelaChamados !== null) : ?>
            <?= ui_recolhivel_inicio(lang('Ambientes.ticketsTitle')) ?>
            <section aria-labelledby="ambiente-chamados-titulo" id="ambiente-chamados" class="min-w-0">
                <div class="mb-space-4 flex flex-wrap items-center justify-between gap-space-4">
                    <h2 id="ambiente-chamados-titulo" class="text-title"><?= esc(lang('Chamados.ticketsTitle')) ?></h2>
                    <div class="flex flex-wrap items-center gap-space-2">
                        <?= $tabelaChamados->botaoExportar() ?>
                        <?php if ($podeAbrirChamado) : ?>
                            <?= ui_button(lang('Chamados.ticketNew'), 'primary', [
                                'href'   => site_url('chamados/novo') . '?ambiente=' . $id,
                                '@click' => 'ambientaAbrirFormulario($event)',
                            ]) ?>
                        <?php endif ?>
                    </div>
                </div>
                <?= $tabelaChamados->renderizar() ?>
            </section>
            <?= ui_recolhivel_fim() ?>
        <?php endif ?>

        <?php if ($podeEditarPatrimonio || $podeAbrirChamado) : ?>
            <?php /* Um só modal para os "Adicionar" e o chamado: o formulário diz (data-tabela) qual tabela recarregar. */ ?>
            <?= view('components/tabela/formulario_modal', [], ['saveData' => false]) ?>
        <?php endif ?>
    </div>
<?= $this->endSection() ?>

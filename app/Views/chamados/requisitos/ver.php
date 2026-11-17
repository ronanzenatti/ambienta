<?php

/**
 * Requisito de disciplina: faixa com as ações (Verificar um ambiente, Alterar, Excluir), dados em acordeão e,
 * lado a lado, os itens por categoria e os softwares exigidos (listagens padrão; adicionar, propor e verificar
 * abrem em modal). A verificação só sinaliza o que falta, não bloqueia nada.
 *
 * @var array<string, mixed>        $requisito
 * @var App\Libraries\Tabela\Tabela $tabelaItens
 * @var App\Libraries\Tabela\Tabela $tabelaSoftwares
 * @var bool                        $temAmbientes    há ambientes no escopo, para a verificação
 * @var bool                        $podeAlterar
 */
$id     = (int) $requisito['id_requisito_disciplina'];
$nome   = $requisito['sigla'] . ' · ' . $requisito['disciplina'];
$titulo = lang('Chamados.requirementTitle');

// Sem campus: vale a identidade padrão do sistema.
$semCampus = ['nome' => '', 'logo' => null];

$acoes = ($temAmbientes ? ui_button(lang('Chamados.checkTitle'), 'secondary', ['@click' => "\$dispatch('open-modal', 'verificar-ambiente')"]) : '')
    . ($podeAlterar ? ui_button(lang('Ui.edit'), 'edit', ['href' => site_url("requisitos/{$id}/editar")])
        . ui_button(lang('Ui.delete'), 'delete', ['@click' => "\$dispatch('open-modal', 'excluir-requisito')"]) : '');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo . ': ' . $nome) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [[lang('Chamados.requirementsTitle'), site_url('requisitos')], [$nome, null]]], ['saveData' => false]) ?>

    <?= view('institucional/_faixa_campus', [
        'campus' => $semCampus,
        'titulo' => $nome,
        'sub'    => $titulo . ' · ' . $requisito['professor'],
        'acoes'  => $acoes,
    ], ['saveData' => false]) ?>

    <?php if ($podeAlterar) : ?>
        <?= ui_confirm_delete('excluir-requisito', lang('Chamados.requirementDeleteText', [$nome, (string) $requisito['professor']]), site_url("requisitos/{$id}"), lang('Chamados.requirementDeleteTitle')) ?>
    <?php endif ?>

    <?php if ($temAmbientes) : ?>
        <?php ob_start(); ?>
        <div x-data="ambientaVerificacao()">
            <p class="mb-space-4 text-body text-ink-muted"><?= esc(lang('Chamados.checkIntro')) ?></p>
            <form id="verificar-ambiente-form" method="get" action="<?= site_url("requisitos/{$id}/verificar") ?>" @submit.prevent="verificar($event.target)">
                <?= ui_select_fonte('ambiente_id', lang('Chamados.fieldEnvironment'), 'ambientes', ['chave' => 'chamados.acesso'], null, lang('Ui.select'), null, null, null, ['required' => true]) ?>
            </form>
            <p x-show="carregando" x-cloak class="mb-space-4 text-body text-ink-muted"><?= esc(lang('Tabela.loading')) ?></p>
            <div x-show="erro" x-cloak><?= ui_alert('critical', lang('Tabela.loadError')) ?></div>
            <div x-ref="resultado" role="status" aria-live="polite"></div>
        </div>
        <?php $corpoVerificacao = (string) ob_get_clean(); ?>
        <?= ui_modal(
            'verificar-ambiente',
            lang('Chamados.checkTitle'),
            $corpoVerificacao,
            ui_button(lang('Chamados.check'), 'primary', ['type' => 'submit', 'form' => 'verificar-ambiente-form']),
            lang('Ui.close')
        ) ?>
    <?php endif ?>

    <?= ui_recolhivel_inicio(lang('Ui.recordDetails')) ?>
    <section aria-labelledby="requisito-dados" class="rounded-md border border-border bg-surface p-space-6 shadow-xs">
        <h2 id="requisito-dados" class="sr-only"><?= esc(lang('Institucional.sectionData')) ?></h2>
        <dl class="grid grid-cols-[auto_1fr] gap-x-space-4 gap-y-space-2 text-body">
            <dt class="text-ink-muted"><?= esc(lang('Chamados.fieldDiscipline')) ?></dt>
            <dd><?= esc($nome) ?></dd>
            <dt class="text-ink-muted"><?= esc(lang('Chamados.fieldTeacher')) ?></dt>
            <dd><?= esc($requisito['professor']) ?></dd>
            <dt class="text-ink-muted"><?= esc(lang('Chamados.fieldMinCapacity')) ?></dt>
            <dd class="tabular-nums"><?= esc((string) $requisito['capacidade_minima']) ?></dd>
        </dl>
    </section>
    <?= ui_recolhivel_fim() ?>

    <div class="grid items-start gap-space-6 lg:grid-cols-2">
        <section aria-labelledby="requisito-itens-titulo" id="requisito-itens" class="min-w-0 rounded-md border border-border bg-surface p-space-6 shadow-xs">
            <div class="mb-space-4 flex flex-wrap items-center justify-between gap-space-4">
                <h2 id="requisito-itens-titulo" class="text-title"><?= esc(lang('Chamados.requiredItems')) ?></h2>
                <div class="flex flex-wrap items-center gap-space-2">
                    <?= $tabelaItens->botaoExportar() ?>
                    <?php if ($podeAlterar) : ?>
                        <?= ui_button(lang('Chamados.addItem'), 'primary', [
                            'href'   => site_url("requisitos/{$id}/itens/novo"),
                            '@click' => 'ambientaAbrirFormulario($event)',
                        ]) ?>
                    <?php endif ?>
                </div>
            </div>
            <?= $tabelaItens->renderizar() ?>
        </section>

        <section aria-labelledby="requisito-softwares-titulo" id="requisito-softwares" class="min-w-0 rounded-md border border-border bg-surface p-space-6 shadow-xs">
            <div class="mb-space-4 flex flex-wrap items-center justify-between gap-space-4">
                <h2 id="requisito-softwares-titulo" class="text-title"><?= esc(lang('Chamados.requiredSoftware')) ?></h2>
                <div class="flex flex-wrap items-center gap-space-2">
                    <?= $tabelaSoftwares->botaoExportar() ?>
                    <?php if ($podeAlterar) : ?>
                        <?= ui_button(lang('Chamados.proposeSoftware'), 'secondary', [
                            'href'   => site_url("requisitos/{$id}/softwares/propor"),
                            '@click' => 'ambientaAbrirFormulario($event)',
                        ]) ?>
                        <?= ui_button(lang('Chamados.addSoftware'), 'primary', [
                            'href'   => site_url("requisitos/{$id}/softwares/novo"),
                            '@click' => 'ambientaAbrirFormulario($event)',
                        ]) ?>
                    <?php endif ?>
                </div>
            </div>
            <?= $tabelaSoftwares->renderizar() ?>
        </section>
    </div>

    <?php if ($podeAlterar) : ?>
        <?php /* Um só modal para os "Adicionar" e "Propor": o formulário diz (data-tabela) qual tabela recarregar. */ ?>
        <?= view('components/tabela/formulario_modal', [], ['saveData' => false]) ?>
    <?php endif ?>
<?= $this->endSection() ?>

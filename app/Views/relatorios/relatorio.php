<?php

/**
 * Um relatório: filtros (GET, a página recarrega com eles), totais em destaque e a listagem padrão, com
 * Exportar no cabeçalho. Os filtros aplicados seguem em toda URL da tabela (Tabela::filtros()).
 *
 * @var App\Tabelas\Relatorios\RelatorioTabela $tabela
 * @var App\Libraries\Relatorios\Filtros       $filtros
 */
$titulo  = $tabela->titulo();
$escopoRelatorio = ['chave' => 'relatorios.acesso'];
$todos   = ['' => lang('Relatorios.all')];
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <p class="mb-space-4">
        <a href="<?= site_url('relatorios') ?>" class="inline-flex min-h-11 items-center gap-space-1 underline">
            <?= ui_icon('seta-esquerda', 'size-4') ?><?= esc(lang('Relatorios.back')) ?>
        </a>
    </p>

    <div class="mb-space-6 flex flex-wrap items-start justify-between gap-space-4">
        <div>
            <h1 class="mb-space-2 text-display"><?= esc($titulo) ?></h1>
            <p class="max-w-2xl text-body text-ink-muted"><?= esc($tabela->descricao()) ?></p>
        </div>
        <div class="flex flex-wrap items-center gap-space-2">
            <?= $tabela->botaoExportar() ?>
        </div>
    </div>

    <form method="get" action="<?= esc($tabela->urlLista(), 'attr') ?>"
          aria-label="<?= esc(lang('Relatorios.filters'), 'attr') ?>"
          class="mb-space-6 flex flex-wrap items-end gap-x-space-4 rounded-md border border-border bg-surface p-space-4 shadow-xs">
        <div class="min-w-48 flex-1">
            <?= ui_select_fonte('campus', lang('Relatorios.fieldCampus'), 'campi', $escopoRelatorio + ['predios' => '1'], $filtros->campusId, $todos['']) ?>
        </div>
        <div class="min-w-48 flex-1">
            <?= ui_select_fonte('predio', lang('Relatorios.fieldBuilding'), 'predios', $escopoRelatorio + ($filtros->campusId === null ? [] : ['campus' => $filtros->campusId]), $filtros->predioId, $todos[''], null, null, ['campus', 'campus']) ?>
        </div>
        <?php if ($tabela->usaPeriodo()) : ?>
            <div>
                <?= ui_field('de', lang('Relatorios.fieldFrom'), 'date', $filtros->de->format('Y-m-d'), null, null, ['required' => true]) ?>
            </div>
            <div>
                <?= ui_field('ate', lang('Relatorios.fieldTo'), 'date', $filtros->ate->format('Y-m-d'), null, null, ['required' => true]) ?>
            </div>
        <?php endif ?>
        <?php if ($tabela->usaAgrupamento()) : ?>
            <div class="min-w-40">
                <?= ui_select('agrupar', lang('Relatorios.fieldGroupBy'), opcoes_traduzidas('Relatorios.group_', App\Libraries\Relatorios\Filtros::AGRUPAMENTOS), $filtros->agrupamento) ?>
            </div>
        <?php endif ?>
        <div class="mb-space-4"><?= ui_button(lang('Relatorios.apply'), 'secondary', ['type' => 'submit']) ?></div>
    </form>

    <?php if ($tabela->resumo() !== []) : ?>
        <h2 class="sr-only"><?= esc(lang('Relatorios.summary')) ?></h2>
        <dl class="mb-space-6 grid grid-cols-2 gap-space-4 md:grid-cols-3 xl:grid-cols-5">
            <?php foreach ($tabela->resumo() as [$rotulo, $valor]) : ?>
                <div class="rounded-md border border-border bg-surface p-space-4 shadow-xs">
                    <dt class="text-caption text-ink-muted"><?= esc($rotulo) ?></dt>
                    <dd class="text-title tabular-nums"><?= esc($valor) ?></dd>
                </div>
            <?php endforeach ?>
        </dl>
    <?php endif ?>

    <?= $tabela->renderizar() ?>
<?= $this->endSection() ?>

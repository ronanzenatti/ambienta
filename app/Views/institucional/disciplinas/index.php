<?php

/**
 * Disciplinas, com quantos cursos cada uma tem.
 *
 * @var App\Libraries\Tabela\Tabela $tabela
 * @var bool                        $podeEditar
 * @var int|null                    $cursoId
 */
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc(lang('Disciplinas.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>

    <div class="mb-space-6 flex flex-wrap items-center justify-between gap-space-4">
        <h1 class="text-display"><?= esc(lang('Disciplinas.title')) ?></h1>
        <div class="flex flex-wrap items-center gap-space-2">
            <?= $tabela->botaoExportar() ?>
            <?php if ($podeEditar) : ?>
                <?= ui_button(lang('Disciplinas.new'), 'primary', ['href' => site_url('disciplinas/novo'), '@click' => 'ambientaAbrirFormulario($event)']) ?>
            <?php endif ?>
        </div>
    </div>

    <form method="get" action="<?= esc($tabela->urlLista(), 'attr') ?>"
          aria-label="<?= esc(lang('Disciplinas.filters'), 'attr') ?>"
          class="mb-space-6 flex flex-wrap items-end gap-x-space-4 rounded-md border border-border bg-surface p-space-4 shadow-xs">
        <div class="min-w-48 flex-1">
            <?= ui_select_fonte('curso', lang('Disciplinas.fieldCourse'), 'cursos', [], $cursoId, lang('Disciplinas.allCourses')) ?>
        </div>
        <div class="mb-space-4"><?= ui_button(lang('Disciplinas.filter'), 'secondary', ['type' => 'submit']) ?></div>
    </form>

    <?= $tabela->renderizar() ?>
<?= $this->endSection() ?>

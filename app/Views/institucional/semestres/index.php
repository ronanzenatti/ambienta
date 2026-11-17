<?php

/**
 * Semestres letivos, do mais recente ao mais antigo.
 *
 * @var App\Libraries\Tabela\Tabela $tabela
 * @var bool                        $podeEditar
 */
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc(lang('Semestres.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>

    <div class="mb-space-6 flex flex-wrap items-center justify-between gap-space-4">
        <h1 class="text-display"><?= esc(lang('Semestres.title')) ?></h1>
        <div class="flex flex-wrap items-center gap-space-2">
            <?= $tabela->botaoExportar() ?>
            <?php if ($podeEditar) : ?>
                <?= ui_button(lang('Semestres.new'), 'primary', ['href' => site_url('semestres/novo'), '@click' => 'ambientaAbrirFormulario($event)']) ?>
            <?php endif ?>
        </div>
    </div>

    <?= $tabela->renderizar() ?>
<?= $this->endSection() ?>

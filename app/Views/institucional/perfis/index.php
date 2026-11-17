<?php

/**
 * Lista de perfis.
 *
 * @var App\Libraries\Tabela\Tabela $tabela
 */
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc(lang('Perfis.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <div class="mb-space-6 flex flex-wrap items-center justify-between gap-space-4">
        <h1 class="text-display"><?= esc(lang('Perfis.title')) ?></h1>
        <div class="flex flex-wrap items-center gap-space-2">
            <?= $tabela->botaoExportar() ?>
            <?= ui_button(lang('Perfis.new'), 'primary', ['href' => site_url('perfis/novo')]) ?>
        </div>
    </div>

    <?= $tabela->renderizar() ?>
<?= $this->endSection() ?>

<?php

/**
 * Campi visíveis para o usuário.
 *
 * @var App\Libraries\Tabela\Tabela $tabela
 * @var bool                        $podeNovo
 */
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc(lang('Campus.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>

    <div class="mb-space-6 flex flex-wrap items-center justify-between gap-space-4">
        <h1 class="text-display"><?= esc(lang('Campus.title')) ?></h1>
        <div class="flex flex-wrap items-center gap-space-2">
            <?= $tabela->botaoExportar() ?>
            <?php if ($podeNovo) : ?>
                <?= ui_button(lang('Campus.new'), 'primary', ['href' => site_url('campus/novo')]) ?>
            <?php endif ?>
        </div>
    </div>

    <?= $tabela->renderizar() ?>
<?= $this->endSection() ?>

<?php

/**
 * Domínios de e-mail permitidos no login com Google Workspace. Criar e alterar abrem em modal (formulário curto).
 *
 * @var App\Libraries\Tabela\Tabela $tabela
 */
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc(lang('Dominios.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>

    <div class="mb-space-6 flex flex-wrap items-start justify-between gap-space-4">
        <div>
            <h1 class="mb-space-2 text-display"><?= esc(lang('Dominios.title')) ?></h1>
            <p class="max-w-2xl text-body text-ink-muted"><?= esc(lang('Dominios.intro')) ?></p>
        </div>
        <div class="flex flex-wrap items-center gap-space-2">
            <?= $tabela->botaoExportar() ?>
            <?= ui_button(lang('Dominios.new'), 'primary', ['href' => site_url('dominios/novo'), '@click' => 'ambientaAbrirFormulario($event)']) ?>
        </div>
    </div>

    <?= $tabela->renderizar() ?>
<?= $this->endSection() ?>

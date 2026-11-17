<?php

/**
 * Categorias de ambiente. Criar e alterar abrem em modal (formulário curto).
 *
 * @var App\Libraries\Tabela\Tabela $tabela
 * @var bool                        $podeEditar
 */
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc(lang('Categorias.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>

    <div class="mb-space-6 flex flex-wrap items-start justify-between gap-space-4">
        <div>
            <h1 class="mb-space-2 text-display"><?= esc(lang('Categorias.title')) ?></h1>
            <p class="max-w-2xl text-body text-ink-muted"><?= esc(lang('Categorias.intro')) ?></p>
        </div>
        <div class="flex flex-wrap items-center gap-space-2">
            <?= $tabela->botaoExportar() ?>
            <?php if ($podeEditar) : ?>
                <?= ui_button(lang('Categorias.newHeading'), 'primary', ['href' => site_url('categorias-ambiente/novo'), '@click' => 'ambientaAbrirFormulario($event)']) ?>
            <?php endif ?>
        </div>
    </div>

    <?= $tabela->renderizar() ?>
<?= $this->endSection() ?>

<?php

/**
 * Página inicial dos relatórios: um cartão por relatório.
 *
 * @var list<string> $relatorios slugs
 */
$titulo = lang('Relatorios.title');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <div class="mb-space-6">
        <h1 class="mb-space-2 text-display"><?= esc($titulo) ?></h1>
        <p class="max-w-2xl text-body text-ink-muted"><?= esc(lang('Relatorios.intro')) ?></p>
    </div>

    <ul class="grid gap-space-4 md:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($relatorios as $slug) : ?>
            <li class="flex flex-col rounded-md border border-border bg-surface p-space-6 shadow-xs">
                <h2 class="mb-space-2 text-title">
                    <a href="<?= site_url('relatorios/' . $slug) ?>" class="underline"><?= esc(lang('Relatorios.title_' . $slug)) ?></a>
                </h2>
                <p class="text-body text-ink-muted"><?= esc(lang('Relatorios.intro_' . $slug)) ?></p>
            </li>
        <?php endforeach ?>
    </ul>
<?= $this->endSection() ?>

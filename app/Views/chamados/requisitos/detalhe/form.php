<?php

/**
 * Página de adicionar item/software a um requisito (sem JavaScript, ou aberta fora da página do requisito).
 * O formulário em si é _form.php, o mesmo que a página do requisito abre em modal.
 *
 * @var string               $tipo
 * @var array<string, mixed> $requisito
 */
$titulo = lang(match ($tipo) {
    'item'     => 'Chamados.addItem',
    'software' => 'Chamados.addSoftware',
    default    => 'Chamados.proposeSoftware',
});
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <h1 class="mb-space-2 text-display"><?= esc($titulo) ?></h1>
    <p class="mb-space-6 text-body text-ink-muted"><?= esc($requisito['sigla'] . ' · ' . $requisito['disciplina'] . ' — ' . $requisito['professor']) ?></p>

    <?= $this->include('chamados/requisitos/detalhe/_form') ?>
<?= $this->endSection() ?>

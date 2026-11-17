<?php

/**
 * Página de cadastro e alteração de semestre letivo (sem JavaScript, ou aberta fora da listagem). O formulário em si é _form.php,
 * o mesmo que a listagem abre em modal.
 *
 * @var array<string, mixed> $semestre
 */
$titulo = lang(isset($semestre['id_semestre']) ? 'Semestres.edit' : 'Semestres.new');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>

    <h1 class="mb-space-6 text-display"><?= esc($titulo) ?></h1>

    <?= $this->include('institucional/semestres/_form') ?>
<?= $this->endSection() ?>

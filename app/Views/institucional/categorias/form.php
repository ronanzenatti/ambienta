<?php

/**
 * Página de cadastro e alteração de categoria de ambiente (sem JavaScript, ou aberta fora da listagem). O formulário em si é _form.php,
 * o mesmo que a listagem abre em modal.
 *
 * @var array<string, mixed> $categoria
 */
$titulo = lang(isset($categoria['id_categoria_ambiente']) ? 'Categorias.edit' : 'Categorias.newHeading');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>

    <h1 class="mb-space-6 text-display"><?= esc($titulo) ?></h1>

    <?= $this->include('institucional/categorias/_form') ?>
<?= $this->endSection() ?>

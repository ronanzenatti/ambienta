<?php

/**
 * Página de cadastro e alteração de almoxarifado (sem JavaScript, ou aberta fora da listagem). O formulário em si é _form.php, o mesmo
 * que a listagem abre em modal.
 *
 * @var array<string, mixed> $almoxarifado
 */
$titulo = lang(isset($almoxarifado['id_almoxarifado']) ? 'Patrimonio.warehouseEdit' : 'Patrimonio.warehouseNew');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>

    <h1 class="mb-space-6 text-display"><?= esc($titulo) ?></h1>

    <?= $this->include('patrimonio/almoxarifados/_form') ?>
<?= $this->endSection() ?>

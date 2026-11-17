<?php

/**
 * Página de cadastro e alteração de software (sem JavaScript, ou aberta fora da listagem). O formulário em si é _form.php, o mesmo
 * que a listagem abre em modal.
 *
 * @var array<string, mixed> $software
 */
$titulo = lang(isset($software['id_software']) ? 'Patrimonio.softwareEdit' : 'Patrimonio.softwareNew');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>

    <h1 class="mb-space-6 text-display"><?= esc($titulo) ?></h1>

    <?= $this->include('patrimonio/softwares/_form') ?>
<?= $this->endSection() ?>

<?php

/**
 * Página da troca rápida de senha (sem JavaScript, ou aberta fora da listagem). O formulário é _senha.php,
 * o mesmo que a listagem abre em modal.
 *
 * @var array<string, mixed> $usuario
 */
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc(lang('Usuarios.passwordTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <h1 class="mb-space-6 text-display"><?= esc(lang('Usuarios.passwordTitle')) ?></h1>

    <?= $this->include('institucional/usuarios/_senha') ?>
<?= $this->endSection() ?>

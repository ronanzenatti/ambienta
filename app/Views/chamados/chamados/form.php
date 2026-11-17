<?php

/**
 * Página de abertura de chamado (sem JavaScript, ou aberta fora da listagem). O formulário em si é
 * _form.php, o mesmo que a listagem abre em modal.
 */

$titulo = lang('Chamados.ticketNew');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [[lang('Chamados.ticketsTitle'), site_url('chamados')], [$titulo, null]]], ['saveData' => false]) ?>

    <h1 class="mb-space-6 text-display"><?= esc($titulo) ?></h1>

    <?= $this->include('chamados/chamados/_form') ?>
<?= $this->endSection() ?>

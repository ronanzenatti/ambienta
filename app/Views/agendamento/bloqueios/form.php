<?php

/**
 * Página do cadastro de bloqueio (sem JavaScript). O formulário é _form.php, o mesmo do modal.
 *
 * (sem variáveis próprias além das do formulário)
 */

$titulo = lang('Agendamento.blockNew');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [[lang('Agendamento.blocksTitle'), site_url('agendamento/bloqueios')], [$titulo, null]]], ['saveData' => false]) ?>

    <h1 class="mb-space-6 text-display"><?= esc($titulo) ?></h1>

    <?= $this->include('agendamento/bloqueios/_form') ?>
<?= $this->endSection() ?>

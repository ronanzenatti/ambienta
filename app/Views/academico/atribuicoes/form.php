<?php

/**
 * Página do cadastro de vínculo (sem JavaScript). O formulário é _form.php, o mesmo do modal.
 *
 * (sem variáveis próprias além das do formulário)
 */

$titulo = lang('Agendamento.linkNew');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [[lang('Agendamento.linksTitle'), site_url('atribuicoes')], [$titulo, null]]], ['saveData' => false]) ?>

    <h1 class="mb-space-6 text-display"><?= esc($titulo) ?></h1>

    <?= $this->include('academico/atribuicoes/_form') ?>
<?= $this->endSection() ?>

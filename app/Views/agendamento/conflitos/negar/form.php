<?php

/**
 * Página da negação de conflito (sem JavaScript). O formulário é _form.php, o mesmo do modal.
 *
 * @var array<string, mixed> $conflito
 */
$titulo = lang('Agendamento.denyTitle');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [[lang('Agendamento.conflictsTitle'), site_url('agendamento/conflitos')], [$titulo, null]]], ['saveData' => false]) ?>

    <h1 class="mb-space-6 text-display"><?= esc($titulo) ?></h1>

    <?= $this->include('agendamento/conflitos/negar/_form') ?>
<?= $this->endSection() ?>

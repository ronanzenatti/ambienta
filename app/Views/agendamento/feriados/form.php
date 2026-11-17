<?php

/**
 * Página do cadastro/alteração de feriado (sem JavaScript). O formulário é _form.php, o mesmo do modal.
 *
 * @var array<string, mixed> $dados
 */
$titulo = lang(isset($dados['id_feriado']) ? 'Agendamento.holidayEdit' : 'Agendamento.holidayNew');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [[lang('Agendamento.holidaysTitle'), site_url('agendamento/feriados')], [$titulo, null]]], ['saveData' => false]) ?>

    <h1 class="mb-space-6 text-display"><?= esc($titulo) ?></h1>

    <?= $this->include('agendamento/feriados/_form') ?>
<?= $this->endSection() ?>

<?php

/**
 * Página da alteração de reserva (sem JavaScript). O formulário é _form.php, o mesmo do modal.
 *
 * @var array<string, mixed> $reserva
 */
$titulo = lang('Agendamento.editTitle');
$id     = (int) $reserva['id_reserva_pontual'];
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [[lang('Agendamento.reservationsTitle'), site_url('agendamento/reservas')], [(string) $reserva['titulo'], site_url("agendamento/reservas/{$id}")], [$titulo, null]]], ['saveData' => false]) ?>

    <h1 class="mb-space-6 text-display"><?= esc($titulo) ?></h1>

    <?= $this->include('agendamento/reservas/editar/_form') ?>
<?= $this->endSection() ?>

<?php

/**
 * Página da movimentação (sem JavaScript, ou aberta fora da página do chamado). O formulário em si é
 * _form.php, o mesmo que a página do chamado abre em modal.
 *
 * @var array<string, mixed> $chamado
 */
$id     = (int) $chamado['id_chamado'];
$titulo = lang('Chamados.movementTitle', [$id]);
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [
        [lang('Chamados.ticketsTitle'), site_url('chamados')],
        ['#' . $id, site_url("chamados/{$id}")],
        [$titulo, null],
    ]], ['saveData' => false]) ?>

    <h1 class="mb-space-6 text-display"><?= esc($titulo) ?></h1>

    <?= $this->include('chamados/chamados/movimentacao/_form') ?>
<?= $this->endSection() ?>

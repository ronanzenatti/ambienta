<?php

/**
 * Página de cadastro e alteração do requisito (sem JavaScript, ou aberta fora da listagem). O formulário em
 * si é _form.php, o mesmo que a listagem abre em modal.
 *
 * @var array<string, mixed> $requisito
 */
$titulo = lang(isset($requisito['id_requisito_disciplina']) ? 'Chamados.requirementEdit' : 'Chamados.requirementNew');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [[lang('Chamados.requirementsTitle'), site_url('requisitos')], [$titulo, null]]], ['saveData' => false]) ?>

    <h1 class="mb-space-6 text-display"><?= esc($titulo) ?></h1>

    <?= $this->include('chamados/requisitos/_form') ?>
<?= $this->endSection() ?>

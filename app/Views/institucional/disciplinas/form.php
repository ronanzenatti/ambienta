<?php

/**
 * Página de cadastro e alteração de disciplina (os cursos são vinculados na página da disciplina) (sem JavaScript, ou aberta fora da listagem). O formulário em si é _form.php,
 * o mesmo que a listagem abre em modal.
 *
 * @var array<string, mixed> $disciplina
 */
$titulo = lang(isset($disciplina['id_disciplina']) ? 'Disciplinas.edit' : 'Disciplinas.new');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>

    <h1 class="mb-space-6 text-display"><?= esc($titulo) ?></h1>

    <?= $this->include('institucional/disciplinas/_form') ?>
<?= $this->endSection() ?>

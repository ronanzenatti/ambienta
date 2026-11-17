<?php

/**
 * Página do cadastro de domínio de e-mail (sem JavaScript). O formulário em si é _form.php, o mesmo que a
 * listagem abre em modal.
 */

?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc(lang('Dominios.new')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>

    <h1 class="mb-space-6 text-display"><?= esc(lang('Dominios.new')) ?></h1>

    <?= $this->include('institucional/dominios/_form') ?>
<?= $this->endSection() ?>

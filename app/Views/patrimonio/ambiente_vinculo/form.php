<?php

/**
 * Página de adicionar softwares ou itens a um ambiente (sem JavaScript, ou aberta fora da página do ambiente).
 * O formulário em si é _form.php, o mesmo que a página do ambiente abre em modal.
 *
 * @var string                $tipo
 * @var array<string, mixed>  $ambiente
 * @var array<string, mixed>  $predio
 */
$titulo = lang($tipo === 'itens' ? 'Patrimonio.addItemsTitle' : 'Patrimonio.addSoftwaresTitle');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <h1 class="mb-space-2 text-display"><?= esc($titulo) ?></h1>
    <p class="mb-space-6 text-body text-ink-muted"><?= esc($ambiente['nome'] . ' · ' . $predio['nome']) ?></p>

    <?= $this->include('patrimonio/ambiente_vinculo/_form') ?>
<?= $this->endSection() ?>

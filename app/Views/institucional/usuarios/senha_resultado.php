<?php

/**
 * Resultado da troca de senha quando feita fora do modal: o texto pronto para copiar. A senha em claro só
 * existe nesta resposta.
 *
 * @var string      $texto
 * @var string|null $email 'enviado' | 'falhou' | null
 */
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc(lang('Usuarios.passwordResultTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <h1 class="mb-space-6 text-display"><?= esc(lang('Usuarios.passwordResultTitle')) ?></h1>

    <?php if ($email === 'enviado') : ?>
        <?= ui_alert('confirmed', lang('Usuarios.emailSent')) ?>
    <?php elseif ($email === 'falhou') : ?>
        <?= ui_alert('critical', lang('Usuarios.emailFailed')) ?>
    <?php endif ?>

    <p class="mb-space-4 text-body"><?= esc(lang('Usuarios.mustChangeNotice')) ?></p>
    <label for="texto-acesso" class="mb-space-1 block text-body-strong"><?= esc(lang('Usuarios.accessTextLabel')) ?></label>
    <textarea id="texto-acesso" readonly rows="5" class="mb-space-6 w-full max-w-xl rounded-sm border border-border-strong bg-surface px-space-3 py-space-2 text-body"><?= esc($texto) ?></textarea>
    <?= ui_button(lang('Ui.back'), 'secondary', ['href' => site_url('usuarios')]) ?>
<?= $this->endSection() ?>

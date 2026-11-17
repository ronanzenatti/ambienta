<?php

/**
 * Escolha obrigatória da própria senha, depois de entrar com uma senha provisória.
 *
 * @var array<string, string> $erros
 */
?>
<?= $this->extend('layouts/auth') ?>

<?= $this->section('title') ?><?= esc(lang('Auth.changeTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <div>
        <h1 class="mb-space-4 text-display"><?= esc(lang('Auth.changeTitle')) ?></h1>
        <p class="mb-space-6 text-body"><?= esc(lang('Auth.changeIntro')) ?></p>

        <form method="post" action="<?= site_url('senha/trocar') ?>">
            <?= csrf_field() ?>
            <?= ui_field('senha', lang('Auth.newPassword'), 'password', null, $erros['senha'] ?? null, lang('Auth.passwordHelp'), [
                'required' => true, 'autocomplete' => 'new-password', 'minlength' => '8', 'autofocus' => true,
            ]) ?>
            <?= ui_field('senha_confirma', lang('Auth.confirmPassword'), 'password', null, $erros['senha_confirma'] ?? null, null, [
                'required' => true, 'autocomplete' => 'new-password', 'minlength' => '8',
            ]) ?>
            <?= ui_button(lang('Ui.save'), 'primary', ['type' => 'submit']) ?>
        </form>
    </div>
<?= $this->endSection() ?>

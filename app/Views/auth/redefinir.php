<?php

/**
 * Nova senha, a partir do link recebido por e-mail.
 *
 * @var string                $token
 * @var array<string, string> $erros
 */
?>
<?= $this->extend('layouts/auth') ?>

<?= $this->section('title') ?><?= esc(lang('Auth.resetNewTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <div>
        <h1 class="mb-space-6 text-display"><?= esc(lang('Auth.resetNewTitle')) ?></h1>

        <form method="post" action="<?= site_url('senha/redefinir/' . $token) ?>">
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

<?php

/**
 * Login por e-mail/CPF + senha, ou com Google Workspace.
 *
 * @var string      $identificador
 * @var string|null $erro
 * @var bool        $googleHabilitado
 */
?>
<?= $this->extend('layouts/auth') ?>

<?= $this->section('title') ?><?= esc(lang('Ui.login')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <h1 class="mb-space-6 text-display"><?= esc(lang('Auth.loginTitle')) ?></h1>

    <?php if ($erro !== null) : ?>
        <?= ui_alert('critical', $erro) ?>
    <?php endif ?>

    <?php if ($googleHabilitado) : ?>
        <?= ui_button(lang('Auth.google'), 'secondary', ['href' => site_url('auth/google'), 'class' => 'w-full']) ?>
        <p class="my-space-6 flex items-center gap-space-3 text-caption text-ink-muted">
            <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
            <?= esc(lang('Auth.or')) ?>
            <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
        </p>
    <?php endif ?>

    <form method="post" action="<?= site_url('entrar') ?>" class="mb-space-6">
        <?= csrf_field() ?>
        <?= ui_field('identificador', lang('Auth.identifier'), 'text', $identificador, null, null, [
            'required' => true, 'autocomplete' => 'username', 'autofocus' => true,
        ]) ?>
        <?= ui_field('senha', lang('Auth.password'), 'password', null, null, null, [
            'required' => true, 'autocomplete' => 'current-password',
        ]) ?>
        <p class="-mt-space-2 mb-space-4 text-right">
            <a href="<?= site_url('senha/esqueci') ?>" class="inline-flex min-h-11 items-center text-body text-brand underline">
                <?= esc(lang('Auth.forgot')) ?>
            </a>
        </p>
        <?= ui_button(lang('Auth.submit'), 'primary', ['type' => 'submit', 'class' => 'w-full']) ?>
    </form>

    <p class="border-t border-border pt-space-4 text-body">
        <a href="<?= site_url('consulta') ?>" class="inline-flex min-h-11 items-center gap-space-2 text-brand underline">
            <?= ui_icon('busca', 'size-4') ?>
            <?= esc(lang('App.homeConsultaText')) ?>
        </a>
    </p>
<?= $this->endSection() ?>

<?php

/**
 * Pedido de recuperação de senha.
 *
 * @var string|null $erro
 */
?>
<?= $this->extend('layouts/auth') ?>

<?= $this->section('title') ?><?= esc(lang('Auth.resetTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <div>
        <h1 class="mb-space-4 text-display"><?= esc(lang('Auth.resetTitle')) ?></h1>
        <p class="mb-space-6 text-body text-ink-muted"><?= esc(lang('Auth.resetIntro')) ?></p>

        <?php if ($erro !== null) : ?>
            <?= ui_alert('critical', $erro) ?>
        <?php endif ?>

        <form method="post" action="<?= site_url('senha/esqueci') ?>">
            <?= csrf_field() ?>
            <?= ui_field('identificador', lang('Auth.identifier'), 'text', null, null, null, [
                'required' => true, 'autocomplete' => 'username', 'autofocus' => true,
            ]) ?>
            <div class="flex flex-wrap gap-space-3">
                <?= ui_button(lang('Auth.resetSubmit'), 'primary', ['type' => 'submit']) ?>
                <?= ui_button(lang('Ui.back'), 'secondary', ['href' => site_url('entrar')]) ?>
            </div>
        </form>
    </div>
<?= $this->endSection() ?>

<?= $this->extend('layouts/auth') ?>

<?= $this->section('title') ?><?= esc(lang('Auth.resetTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <div>
        <h1 class="mb-space-4 text-display"><?= esc(lang('Auth.resetTitle')) ?></h1>
        <?= ui_alert('critical', lang('Auth.resetInvalid')) ?>
        <?= ui_button(lang('Auth.resetSubmit'), 'primary', ['href' => site_url('senha/esqueci')]) ?>
    </div>
<?= $this->endSection() ?>

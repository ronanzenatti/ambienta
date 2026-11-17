<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc(lang('Ui.forbiddenTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <h1 class="mb-space-4 text-display"><?= esc(lang('Ui.forbiddenTitle')) ?></h1>
    <p class="mb-space-6 text-body text-ink-muted"><?= esc(lang('Ui.forbiddenText')) ?></p>
    <?= ui_button(lang('Ui.back'), 'secondary', ['href' => site_url('/')]) ?>
<?= $this->endSection() ?>

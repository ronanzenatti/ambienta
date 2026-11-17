<?php

/**
 * Preferências de notificação. Desmarcar o e-mail mostra o termo de ciência, que precisa ser aceito (o servidor
 * confere de novo). Sem JavaScript o termo fica sempre visível.
 *
 * @var array<string, mixed>|null $preferencia linha de preferencias_notificacao (null = nunca alterou)
 * @var array<string, string>     $erros
 */
$ativo  = $preferencia === null || (bool) $preferencia['canal_email_ativo'];
$titulo = lang('Notificacoes.prefsTitle');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [[lang('Notificacoes.title'), site_url('notificacoes')], [$titulo, null]]], ['saveData' => false]) ?>

    <h1 class="mb-space-2 text-display"><?= esc($titulo) ?></h1>
    <p class="mb-space-6 max-w-2xl text-body text-ink-muted"><?= esc(lang('Notificacoes.prefsIntro')) ?></p>

    <?php if (! $ativo && $preferencia['termo_ciencia_aceito_em'] !== null) : ?>
        <?= ui_alert('pending', lang('Notificacoes.prefsDisabledSince', [
            date(lang('Institucional.dateFormat') . ' H:i', (int) strtotime((string) $preferencia['termo_ciencia_aceito_em'])),
            (string) $preferencia['termo_ciencia_versao'],
        ])) ?>
    <?php endif ?>

    <form method="post" action="<?= site_url('notificacoes/preferencias') ?>" x-data="{ email: <?= $ativo ? 'true' : 'false' ?> }"
          class="max-w-2xl rounded-md border border-border bg-surface p-space-6 shadow-xs">
        <?= csrf_field() ?>

        <div class="mb-space-4 flex min-h-11 items-center gap-space-3">
            <input type="checkbox" id="campo-email" name="email" value="1" x-model="email" <?= $ativo ? 'checked' : '' ?>
                   role="switch" class="toggle">
            <label for="campo-email" class="text-body"><?= esc(lang('Notificacoes.prefsEmail')) ?></label>
        </div>

        <fieldset x-show="! email" class="mb-space-6 rounded-sm border border-border-strong bg-paper p-space-4">
            <legend class="px-space-1 text-body-strong"><?= esc(lang('Notificacoes.termAccept')) ?></legend>
            <p class="mb-space-4 text-body"><?= esc(lang('Notificacoes.emailOptOutTerm')) ?></p>
            <div class="flex min-h-11 items-center gap-space-3">
                <input type="checkbox" id="campo-ciencia" name="ciencia" value="1"
                       <?= isset($erros['ciencia']) ? 'aria-invalid="true" aria-describedby="erro-ciencia"' : '' ?>
                       role="switch" class="toggle">
                <label for="campo-ciencia" class="text-body"><?= esc(lang('Notificacoes.termAccept')) ?></label>
            </div>
            <?php if (isset($erros['ciencia'])) : ?>
                <p id="erro-ciencia" class="mt-space-2 text-body text-signal-critical"><?= esc($erros['ciencia']) ?></p>
            <?php endif ?>
        </fieldset>

        <?= ui_button(lang('Ui.save'), 'primary', ['type' => 'submit']) ?>
    </form>
<?= $this->endSection() ?>

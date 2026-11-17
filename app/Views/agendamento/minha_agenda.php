<?php

/**
 * Link do feed iCal pessoal. O link é um segredo (quem o tiver vê a agenda): por isso o aviso e o botão de
 * gerar outro, que invalida o anterior na hora.
 *
 * @var string|null $feed URL do feed; null enquanto o usuário não gerou nenhum
 */
$titulo = lang('Agendamento.icalMyTitle');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <h1 class="mb-space-2 text-display"><?= esc($titulo) ?></h1>
    <p class="mb-space-6 max-w-2xl text-body text-ink-muted"><?= esc(lang('Agendamento.icalMyIntro')) ?></p>

    <section class="max-w-2xl rounded-md border border-border bg-surface p-space-6 shadow-xs" aria-labelledby="minha-agenda-link">
        <h2 id="minha-agenda-link" class="mb-space-4 text-title"><?= esc(lang('Agendamento.icalSubscribe')) ?></h2>

        <?php if ($feed === null) : ?>
            <p class="mb-space-4 text-body"><?= esc(lang('Agendamento.icalNoLink')) ?></p>
            <form method="post" action="<?= site_url('minha-agenda/link') ?>">
                <?= csrf_field() ?>
                <?= ui_button(lang('Agendamento.icalCreateLink'), 'primary', ['type' => 'submit']) ?>
            </form>
        <?php else : ?>
            <div x-data="{ copiado: false }">
                <?= ui_field('feed', lang('Agendamento.icalLinkLabel'), 'url', $feed, null, lang('Agendamento.icalLinkHelp'), [
                    'readonly' => true,
                    '@focus'   => '$event.target.select()',
                ]) ?>
                <div class="mb-space-6 flex flex-wrap items-center gap-space-3">
                    <?= ui_button(lang('Agendamento.icalCopy'), 'primary', [
                        'type'   => 'button',
                        '@click' => "navigator.clipboard.writeText(document.getElementById('campo-feed').value).then(() => copiado = true)",
                    ]) ?>
                    <?= ui_button(lang('Agendamento.icalDownload'), 'secondary', ['href' => $feed]) ?>
                    <p class="text-body" role="status" x-text="copiado ? <?= esc(json_encode(lang('Agendamento.icalCopied')), 'attr') ?> : ''"></p>
                </div>
            </div>

            <?= ui_alert('pending', lang('Agendamento.icalSecretWarning')) ?>
            <form method="post" action="<?= site_url('minha-agenda/link') ?>" class="mt-space-4">
                <?= csrf_field() ?>
                <?= ui_button(lang('Agendamento.icalNewLink'), 'secondary', ['type' => 'submit']) ?>
            </form>
        <?php endif ?>
    </section>
<?= $this->endSection() ?>

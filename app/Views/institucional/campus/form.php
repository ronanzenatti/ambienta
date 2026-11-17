<?php

/**
 * Cadastro e alteração de campus, com a identidade visual. A prévia de contraste no navegador é só
 * aviso (componente Alpine identidadeCampus); quem decide é a validação do servidor (Contraste).
 *
 * @var array<string, mixed>  $campus
 * @var array<string, string> $erros
 * @var array<string, string> $cores  claro|escuro => hex (tokens brand-ink e ink)
 */
$novo   = ! isset($campus['id_campus']);
$acao   = $novo ? site_url('campus') : site_url('campus/' . $campus['id_campus']);
$titulo = lang($novo ? 'Campus.new' : 'Campus.edit');
$nome   = (string) ($campus['nome'] ?? '');

$textos = [
    ''       => lang('Campus.textDefault'),
    'claro'  => lang('Campus.text_claro'),
    'escuro' => lang('Campus.text_escuro'),
];

$config = [
    'cor'     => (string) ($campus['cor_primaria'] ?? ''),
    'texto'   => (string) ($campus['cor_texto'] ?? ''),
    'cores'   => $cores,
    'minimo'  => \App\Libraries\Contraste::MINIMO_TEXTO,
    'decimal' => lang('Institucional.decimalSeparator'),
];

$arquivo = static function (string $campo) use ($campus): ?string {
    return empty($campus[$campo]) ? null : arquivo_url((string) $campus[$campo]);
};
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>

    <h1 class="mb-space-6 text-display"><?= esc($titulo) ?></h1>

    <form method="post" action="<?= $acao ?>" enctype="multipart/form-data" class="max-w-3xl rounded-md border border-border bg-surface shadow-xs p-space-6">
        <?= csrf_field() ?>

        <?= view('institucional/_endereco_campos', ['registro' => $campus, 'erros' => $erros], ['saveData' => false]) ?>

        <fieldset class="mb-space-6" x-data="identidadeCampus(<?= esc(json_encode($config, JSON_THROW_ON_ERROR), 'attr') ?>)">
            <legend class="mb-space-2 text-heading"><?= esc(lang('Campus.sectionIdentity')) ?></legend>
            <p class="mb-space-4 text-caption text-ink-muted"><?= esc(lang('Campus.identityIntro')) ?></p>

            <div class="grid gap-x-space-4 sm:grid-cols-2">
                <div>
                    <?= ui_field('cor_primaria', lang('Campus.fieldColor'), 'text', $config['cor'], $erros['cor_primaria'] ?? null, lang('Campus.colorHelp'), [
                        'maxlength'      => '7',
                        'x-model'        => 'cor',
                        'autocomplete'   => 'off',
                        'autocapitalize' => 'characters',
                        'spellcheck'     => 'false',
                    ]) ?>
                    <?php /* Seletor visual opcional (sem nome: não é enviado); o campo de texto acima é o que vai para o servidor. */ ?>
                    <div class="-mt-space-2 mb-space-4 flex min-h-11 items-center gap-space-2">
                        <input type="color" id="campo-cor-seletor" x-model="corSeletor"
                               class="size-11 shrink-0 cursor-pointer rounded-sm border border-border-strong bg-surface p-space-1">
                        <label for="campo-cor-seletor" class="text-body"><?= esc(lang('Campus.colorPicker')) ?></label>
                    </div>
                </div>
                <?= ui_select('cor_texto', lang('Campus.fieldTextColor'), $textos, $config['texto'], $erros['cor_texto'] ?? null, null, ['x-model' => 'texto']) ?>
            </div>

            <div x-show="valido" x-cloak class="mb-space-4 rounded-sm border border-border p-space-4" aria-live="polite">
                <p class="mb-space-2 text-caption text-ink-muted"><?= esc(lang('Campus.preview')) ?></p>
                <span class="inline-flex min-h-11 items-center rounded-sm px-space-4 text-body-strong"
                      :style="valido ? `background-color: ${cor}; color: ${corTexto}` : ''"><?= esc(lang('Campus.previewSample')) ?></span>
                <p class="mt-space-2 text-data">
                    <?= esc(lang('Campus.contrastLabel')) ?>: <span class="tabular-nums" x-text="razaoFormatada + ':1'"></span>
                </p>
                <p x-show="atende">
                    <?= ui_status_dot('confirmed', lang('Campus.contrastOk')) ?>
                </p>
                <p x-show="!atende">
                    <?= ui_status_dot('critical', lang('Campus.contrastFail')) ?>
                </p>
            </div>
        </fieldset>

        <fieldset class="mb-space-6">
            <legend class="mb-space-2 text-heading"><?= esc(lang('Institucional.sectionFiles')) ?></legend>
            <?= ui_file('logo', lang('Campus.fieldLogo'), 'image/png,image/jpeg,image/webp', $arquivo('logo'), true, lang('Campus.logoAlt', [$nome]), $erros['logo'] ?? null, lang('Institucional.imageHelp')) ?>
            <?= ui_file('foto', lang('Institucional.fieldPhoto'), 'image/png,image/jpeg,image/webp', $arquivo('foto'), true, lang('Campus.photoAlt', [$nome]), $erros['foto'] ?? null, lang('Institucional.imageHelp')) ?>
            <?php $mapaPdf = \App\Libraries\Arquivos::ehPdf($campus['mapa'] ?? null); ?>
            <?= ui_file('mapa', lang('Campus.fieldMap'), 'image/png,image/jpeg,image/webp,application/pdf', $arquivo('mapa'), ! $mapaPdf, $mapaPdf ? lang('Campus.viewMapPdf') : lang('Campus.mapAlt', [$nome]), $erros['mapa'] ?? null, lang('Campus.mapHelp')) ?>
        </fieldset>

        <?= ui_checkbox('ativo', lang('Campus.fieldActive'), (bool) ($campus['ativo'] ?? true)) ?>

        <div class="flex flex-wrap gap-space-3">
            <?= ui_button(lang('Ui.save'), $novo ? 'primary' : 'edit', ['type' => 'submit']) ?>
            <?= ui_button(lang('Ui.cancel'), 'secondary', ['href' => $novo ? site_url('campus') : site_url('campus/' . $campus['id_campus'])]) ?>
        </div>
    </form>
<?= $this->endSection() ?>

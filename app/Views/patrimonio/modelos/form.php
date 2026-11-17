<?php

/**
 * Cadastro e alteração de modelo de item (tem foto: página própria, não modal).
 *
 * @var array<string, mixed>  $modelo
 * @var bool                  $temCategorias há categorias de item cadastradas
 * @var array<string, string> $erros
 */
$novo   = ! isset($modelo['id_modelo_item']);
$acao   = $novo ? site_url('patrimonio/modelos') : site_url('patrimonio/modelos/' . $modelo['id_modelo_item']);
$voltar = $novo ? site_url('patrimonio/modelos') : site_url('patrimonio/modelos/' . $modelo['id_modelo_item']);
$titulo = lang($novo ? 'Patrimonio.modelNew' : 'Patrimonio.modelEdit');
$v      = static fn (string $campo): string => (string) ($modelo[$campo] ?? '');
$e      = static fn (string $campo): ?string => $erros[$campo] ?? null;
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [[lang('Patrimonio.modelsTitle'), site_url('patrimonio/modelos')], [$titulo, null]]], ['saveData' => false]) ?>

    <h1 class="mb-space-6 text-display"><?= esc($titulo) ?></h1>

    <?php if (! $temCategorias) : ?>
        <?= ui_alert('pending', lang('Patrimonio.noCategories')) ?>
        <?= ui_button(lang('Patrimonio.navCategories'), 'secondary', ['href' => site_url('patrimonio/categorias')]) ?>
    <?php else : ?>
        <form method="post" action="<?= $acao ?>" enctype="multipart/form-data" class="max-w-3xl rounded-md border border-border bg-surface shadow-xs p-space-6">
            <?= csrf_field() ?>

            <?= ui_field('nome', lang('Institucional.fieldName'), 'text', $v('nome'), $e('nome'), lang('Patrimonio.modelNameHelp'), ['required' => true, 'maxlength' => '255']) ?>
            <div class="grid gap-x-space-4 sm:grid-cols-2">
                <?= ui_field('marca', lang('Patrimonio.fieldBrand'), 'text', $v('marca'), $e('marca'), null, ['maxlength' => '100']) ?>
                <?= ui_select_fonte('categoria_item_id', lang('Patrimonio.fieldCategory'), 'categorias-item', [], $v('categoria_item_id'), lang('Ui.select'), $e('categoria_item_id'), null, null, ['required' => true]) ?>
            </div>
            <?= ui_textarea('especificacoes', lang('Patrimonio.fieldSpecs'), $v('especificacoes'), $e('especificacoes'), null, 4) ?>

            <?= ui_checkbox('patrimoniavel', lang('Patrimonio.fieldAssetControlled'), (bool) ($modelo['patrimoniavel'] ?? true)) ?>
            <p class="-mt-space-2 mb-space-4 text-caption text-ink-muted"><?= esc(lang('Patrimonio.assetControlledHelp')) ?></p>

            <?= ui_file(
                'foto',
                lang('Institucional.fieldPhoto'),
                'image/png,image/jpeg,image/webp',
                empty($modelo['foto']) ? null : arquivo_url((string) $modelo['foto']),
                true,
                lang('Patrimonio.modelPhotoAlt', [$v('nome')]),
                $e('foto'),
                lang('Institucional.imageHelp')
            ) ?>

            <div class="flex flex-wrap gap-space-3">
                <?= ui_button(lang('Ui.save'), $novo ? 'primary' : 'edit', ['type' => 'submit']) ?>
                <?= ui_button(lang('Ui.cancel'), 'secondary', ['href' => $voltar]) ?>
            </div>
        </form>
    <?php endif ?>
<?= $this->endSection() ?>

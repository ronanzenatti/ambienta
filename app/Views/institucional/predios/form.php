<?php

/**
 * Cadastro e alteração de prédio.
 *
 * @var array<string, mixed>  $campus
 * @var array<string, mixed>  $predio
 * @var array<string, string> $erros
 */
$novo   = ! isset($predio['id_predio']);
$acao   = $novo ? site_url('campus/' . $campus['id_campus'] . '/predios') : site_url('predios/' . $predio['id_predio']);
$voltar = $novo ? site_url('campus/' . $campus['id_campus']) : site_url('predios/' . $predio['id_predio']);
$titulo = lang($novo ? 'Predios.new' : 'Predios.edit');
$nome   = (string) ($predio['nome'] ?? '');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [
        [lang('Campus.title'), site_url('campus')],
        [campus_rotulo($campus), site_url('campus/' . $campus['id_campus'])],
        [$titulo, null],
    ]], ['saveData' => false]) ?>

    <h1 class="mb-space-6 text-display"><?= esc($titulo) ?></h1>

    <form method="post" action="<?= $acao ?>" enctype="multipart/form-data" class="max-w-3xl rounded-md border border-border bg-surface shadow-xs p-space-6">
        <?= csrf_field() ?>

        <?= view('institucional/_endereco_campos', ['registro' => $predio, 'erros' => $erros], ['saveData' => false]) ?>

        <fieldset class="mb-space-6">
            <legend class="mb-space-2 text-heading"><?= esc(lang('Institucional.sectionFiles')) ?></legend>
            <?= ui_file(
                'foto',
                lang('Institucional.fieldPhoto'),
                'image/png,image/jpeg,image/webp',
                empty($predio['foto']) ? null : arquivo_url((string) $predio['foto']),
                true,
                lang('Predios.photoAlt', [$nome]),
                $erros['foto'] ?? null,
                lang('Institucional.imageHelp')
            ) ?>
            <?php $mapaPdf = \App\Libraries\Arquivos::ehPdf($predio['mapa'] ?? null); ?>
            <?= ui_file(
                'mapa',
                lang('Predios.fieldMap'),
                'image/png,image/jpeg,image/webp,application/pdf',
                empty($predio['mapa']) ? null : arquivo_url((string) $predio['mapa']),
                ! $mapaPdf,
                $mapaPdf ? lang('Predios.viewMapPdf') : lang('Predios.mapAlt', [$nome]),
                $erros['mapa'] ?? null,
                lang('Predios.mapHelp')
            ) ?>
        </fieldset>

        <div class="flex flex-wrap gap-space-3">
            <?= ui_button(lang('Ui.save'), $novo ? 'primary' : 'edit', ['type' => 'submit']) ?>
            <?= ui_button(lang('Ui.cancel'), 'secondary', ['href' => $voltar]) ?>
        </div>
    </form>
<?= $this->endSection() ?>

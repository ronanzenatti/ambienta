<?php

/**
 * Página de listagem do patrimônio: título, introdução, Exportar e o botão de cadastro (opcional), e a tabela.
 *
 * @var App\Libraries\Tabela\Tabela $tabela
 * @var string                      $titulo
 * @var string                      $intro
 * @var array{0: string, 1: string}|null $novo   [rótulo, URL]; abre em modal quando a tabela usa formulariosEmModal()
 * @var string|null                 $extra  HTML confiável de botões extras do cabeçalho
 * @var string|null                 $antes  HTML confiável exibido entre o cabeçalho e a tabela
 */
$novo  ??= null;
$extra ??= null;
$antes ??= null;
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>

    <div class="mb-space-6 flex flex-wrap items-start justify-between gap-space-4">
        <div>
            <h1 class="mb-space-2 text-display"><?= esc($titulo) ?></h1>
            <p class="max-w-2xl text-body text-ink-muted"><?= esc($intro) ?></p>
        </div>
        <div class="flex flex-wrap items-center gap-space-2">
            <?= $extra ?? '' ?>
            <?= $tabela->botaoExportar() ?>
            <?php if ($novo !== null) : ?>
                <?= ui_button($novo[0], 'primary', $tabela->formulariosEmModal()
                    ? ['href' => $novo[1], '@click' => 'ambientaAbrirFormulario($event)']
                    : ['href' => $novo[1]]) ?>
            <?php endif ?>
        </div>
    </div>

    <?= $antes ?? '' ?>

    <?= $tabela->renderizar() ?>
<?= $this->endSection() ?>

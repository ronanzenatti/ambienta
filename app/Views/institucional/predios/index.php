<?php

/**
 * Prédios de todos os campi visíveis, com filtro por campus.
 *
 * @var App\Libraries\Tabela\Tabela $tabela
 * @var int|null                    $campusId
 * @var bool                        $podeNovo  campus filtrado onde o usuário edita: "Novo prédio" vai direto ao formulário
 * @var bool                        $podeCriar edita prédios em algum campus: sem $podeNovo, o botão abre o modal de escolha
 */
$escopoSelect = ['chave' => 'institucional.acesso', 'predios' => '1'];
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc(lang('Predios.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>

    <div class="mb-space-6 flex flex-wrap items-center justify-between gap-space-4">
        <h1 class="text-display"><?= esc(lang('Predios.title')) ?></h1>
        <div class="flex flex-wrap items-center gap-space-2">
            <?= $tabela->botaoExportar() ?>
            <?php if ($podeNovo) : ?>
                <?= ui_button(lang('Predios.new'), 'primary', ['href' => site_url("campus/{$campusId}/predios/novo")]) ?>
            <?php elseif ($podeCriar) : ?>
                <?= ui_button(lang('Predios.new'), 'primary', ['@click' => "\$dispatch('open-modal', 'novo-predio')"]) ?>
            <?php endif ?>
        </div>
    </div>

    <form method="get" action="<?= esc($tabela->urlLista(), 'attr') ?>"
          aria-label="<?= esc(lang('Predios.filters'), 'attr') ?>"
          class="mb-space-6 flex flex-wrap items-end gap-x-space-4 rounded-md border border-border bg-surface p-space-4 shadow-xs">
        <div class="min-w-48 flex-1">
            <?= ui_select_fonte('campus', lang('Predios.fieldCampus'), 'campi', $escopoSelect, $campusId, lang('Predios.allCampuses')) ?>
        </div>
        <div class="mb-space-4"><?= ui_button(lang('Predios.filter'), 'secondary', ['type' => 'submit']) ?></div>
    </form>

    <?php if ($podeCriar && ! $podeNovo) : ?>
        <?php ob_start() ?>
        <p class="mb-space-4 text-ink-muted"><?= esc(lang('Predios.chooseHelp')) ?></p>
        <form id="novo-predio-form" method="get" action="<?= esc(site_url('predios/novo'), 'attr') ?>">
            <?= ui_select_fonte('escolha_campus', lang('Predios.fieldCampus'), 'campi', ['chave' => 'institucional.acesso', 'nivel' => 'editor'], null, lang('Ui.select')) ?>
        </form>
        <?php $corpoEscolha = (string) ob_get_clean() ?>
        <?= ui_modal(
            'novo-predio',
            lang('Predios.chooseTitle'),
            $corpoEscolha,
            ui_button(lang('Predios.continue'), 'primary', ['type' => 'submit', 'form' => 'novo-predio-form'])
        ) ?>
    <?php endif ?>

    <?= $tabela->renderizar() ?>
<?= $this->endSection() ?>

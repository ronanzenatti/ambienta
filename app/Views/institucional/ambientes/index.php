<?php

/**
 * Ambientes de todos os prédios visíveis, com filtros de campus e prédio (o prédio depende do campus).
 *
 * @var App\Libraries\Tabela\Tabela $tabela
 * @var int|null                    $campusId
 * @var int|null                    $predioId
 * @var bool                        $podeNovo  prédio filtrado onde o usuário edita: "Novo ambiente" vai direto ao formulário
 * @var bool                        $podeCriar edita ambientes em algum prédio: sem $podeNovo, o botão abre o modal de escolha
 */
$escopoSelect = ['chave' => 'institucional.acesso'];
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc(lang('Ambientes.title')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>

    <div class="mb-space-6 flex flex-wrap items-center justify-between gap-space-4">
        <h1 class="text-display"><?= esc(lang('Ambientes.title')) ?></h1>
        <div class="flex flex-wrap items-center gap-space-2">
            <?= $tabela->botaoExportar() ?>
            <?php if ($podeNovo) : ?>
                <?= ui_button(lang('Ambientes.new'), 'primary', ['href' => site_url("predios/{$predioId}/ambientes/novo")]) ?>
            <?php elseif ($podeCriar) : ?>
                <?= ui_button(lang('Ambientes.new'), 'primary', ['@click' => "\$dispatch('open-modal', 'novo-ambiente')"]) ?>
            <?php endif ?>
        </div>
    </div>

    <form method="get" action="<?= esc($tabela->urlLista(), 'attr') ?>"
          aria-label="<?= esc(lang('Ambientes.filters'), 'attr') ?>"
          class="mb-space-6 flex flex-wrap items-end gap-x-space-4 rounded-md border border-border bg-surface p-space-4 shadow-xs">
        <div class="min-w-48 flex-1">
            <?= ui_select_fonte('campus', lang('Ambientes.fieldCampus'), 'campi', $escopoSelect + ['predios' => '1'], $campusId, lang('Ambientes.allCampuses')) ?>
        </div>
        <div class="min-w-48 flex-1">
            <?= ui_select_fonte('predio', lang('Ambientes.fieldBuilding'), 'predios', $escopoSelect + ($campusId === null ? [] : ['campus' => $campusId]), $predioId, lang('Ambientes.allBuildings'), null, null, ['campus', 'campus']) ?>
        </div>
        <div class="mb-space-4"><?= ui_button(lang('Ambientes.filter'), 'secondary', ['type' => 'submit']) ?></div>
    </form>

    <?php if ($podeCriar && ! $podeNovo) : ?>
        <?php
        // Só os campi e prédios onde o usuário edita (nível editor); o prédio depende do campus escolhido.
        $escopoEscolha = ['chave' => 'institucional.acesso', 'nivel' => 'editor'];
        ob_start();
        ?>
        <p class="mb-space-4 text-ink-muted"><?= esc(lang('Ambientes.chooseHelp')) ?></p>
        <form id="novo-ambiente-form" method="get" action="<?= esc(site_url('ambientes/novo'), 'attr') ?>">
            <?= ui_select_fonte('escolha_campus', lang('Ambientes.fieldCampus'), 'campi', $escopoEscolha + ['predios' => '1'], null, lang('Ui.select')) ?>
            <?= ui_select_fonte('escolha_predio', lang('Ambientes.fieldBuilding'), 'predios', $escopoEscolha, null, lang('Ui.select'), null, null, ['escolha_campus', 'campus']) ?>
        </form>
        <?php $corpoEscolha = (string) ob_get_clean() ?>
        <?= ui_modal(
            'novo-ambiente',
            lang('Ambientes.chooseTitle'),
            $corpoEscolha,
            ui_button(lang('Ambientes.continue'), 'primary', ['type' => 'submit', 'form' => 'novo-ambiente-form'])
        ) ?>
    <?php endif ?>

    <?= $tabela->renderizar() ?>
<?= $this->endSection() ?>

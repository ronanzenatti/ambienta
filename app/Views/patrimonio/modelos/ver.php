<?php

/**
 * Página do modelo (um molde): dados (acordeão recolhido, porque há listagem embaixo), criação de itens a partir
 * dele num local e a listagem dos itens criados deste modelo (que depois seguem por conta própria).
 *
 * @var array<string, mixed>        $modelo
 * @var array<string, mixed>|null   $categoria
 * @var App\Libraries\Tabela\Tabela $tabela
 * @var bool                        $podeEditar
 * @var bool                        $temLocais  há locais em que o usuário pode dar entrada (editor)
 * @var array<string, mixed>        $replicacao valores postados na replicação (ao voltar com erro)
 * @var int                         $limite     máximo de unidades por replicação
 * @var array<string, string>       $erros
 */
$id            = (int) $modelo['id_modelo_item'];
$nome          = (string) $modelo['nome'];
$patrimoniavel = (bool) $modelo['patrimoniavel'];
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($nome) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [[lang('Patrimonio.modelsTitle'), site_url('patrimonio/modelos')], [$nome, null]]], ['saveData' => false]) ?>

    <p class="text-data text-ink-muted"><?= esc($categoria['nome'] ?? '') ?></p>
    <h1 class="mb-space-6 text-display"><?= esc($nome) ?></h1>

    <?php if ($podeEditar) : ?>
        <div class="mb-space-6 flex flex-wrap gap-space-3">
            <?= ui_button(lang('Ui.edit'), 'edit', ['href' => site_url("patrimonio/modelos/{$id}/editar")]) ?>
            <?= ui_button(lang('Ui.delete'), 'delete', ['@click' => "\$dispatch('open-modal', 'excluir-modelo')"]) ?>
        </div>
        <?= ui_confirm_delete('excluir-modelo', lang('Patrimonio.modelDeleteText', [$nome]), site_url("patrimonio/modelos/{$id}"), lang('Patrimonio.modelDeleteTitle')) ?>
    <?php endif ?>

    <?= ui_recolhivel_inicio(lang('Ui.recordDetails')) ?>
    <div class="grid gap-space-6 md:grid-cols-2">
        <section aria-labelledby="modelo-dados" class="rounded-md border border-border bg-surface shadow-xs p-space-6">
            <h2 id="modelo-dados" class="sr-only"><?= esc(lang('Institucional.sectionData')) ?></h2>
            <dl class="grid grid-cols-[auto_1fr] gap-x-space-4 gap-y-space-2 text-body">
                <?php if (! empty($modelo['marca'])) : ?>
                    <dt class="text-ink-muted"><?= esc(lang('Patrimonio.fieldBrand')) ?></dt>
                    <dd><?= esc($modelo['marca']) ?></dd>
                <?php endif ?>
                <dt class="text-ink-muted"><?= esc(lang('Patrimonio.fieldControl')) ?></dt>
                <dd><?= esc(lang($patrimoniavel ? 'Patrimonio.controlAsset' : 'Patrimonio.controlQuantity')) ?></dd>
            </dl>
            <?php if (! empty($modelo['especificacoes'])) : ?>
                <p class="mt-space-4 whitespace-pre-line text-body"><?= esc($modelo['especificacoes']) ?></p>
            <?php endif ?>
        </section>

        <?php if (! empty($modelo['foto'])) : ?>
            <figure class="rounded-md border border-border bg-surface shadow-xs p-space-4">
                <img src="<?= esc(arquivo_url((string) $modelo['foto']), 'attr') ?>" alt="<?= esc(lang('Patrimonio.modelPhotoAlt', [$nome]), 'attr') ?>" class="w-full rounded-sm object-contain">
            </figure>
        <?php endif ?>
    </div>
    <?= ui_recolhivel_fim() ?>

    <?php if ($temLocais) : ?>
        <section aria-labelledby="modelo-replicar" class="mb-space-8 max-w-3xl rounded-md border border-border bg-surface shadow-xs p-space-6">
            <h2 id="modelo-replicar" class="mb-space-2 text-heading"><?= esc(lang('Patrimonio.replicateTitle')) ?></h2>
            <p class="mb-space-4 text-body text-ink-muted"><?= esc(lang($patrimoniavel ? 'Patrimonio.replicateAssetHelp' : 'Patrimonio.replicateStockHelp')) ?></p>
            <form method="post" action="<?= site_url("patrimonio/modelos/{$id}/replicar") ?>" class="grid gap-x-space-4 sm:grid-cols-[10rem_1fr_auto] sm:items-start">
                <?= csrf_field() ?>
                <?= ui_field('quantidade', lang('Patrimonio.fieldQuantity'), 'number', (string) ($replicacao['quantidade'] ?? '1'), $erros['quantidade'] ?? null, null, ['required' => true, 'min' => '1', 'max' => (string) $limite, 'step' => '1', 'inputmode' => 'numeric']) ?>
                <?= ui_select_fonte('local', lang('Patrimonio.fieldLocation'), 'locais', ['chave' => 'patrimonio.acesso', 'nivel' => 'editor'], $replicacao['local'] ?? null, lang('Ui.select'), $erros['local'] ?? null, null, null, ['required' => true]) ?>
                <div class="sm:pt-space-6">
                    <?= ui_button(lang('Patrimonio.replicate'), 'primary', ['type' => 'submit']) ?>
                </div>
            </form>
        </section>
    <?php endif ?>

    <section aria-labelledby="modelo-unidades">
        <div class="mb-space-4 flex flex-wrap items-center justify-between gap-space-4">
            <h2 id="modelo-unidades" class="text-title"><?= esc($tabela->titulo()) ?></h2>
            <?= $tabela->botaoExportar() ?>
        </div>

        <?= $tabela->renderizar() ?>
    </section>
<?= $this->endSection() ?>

<?php

/**
 * Página do prédio, com a identidade do campus dele, e a lista de ambientes.
 *
 * @var array<string, mixed>       $predio
 * @var array<string, mixed>       $campus
 * @var App\Libraries\Tabela\Tabela $tabela ambientes do prédio
 * @var bool                       $podeEditar
 * @var bool                       $podeExcluir
 */
$id   = (int) $predio['id_predio'];
$nome = (string) $predio['nome'];
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($nome) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [
        [lang('Campus.title'), site_url('campus')],
        [campus_rotulo($campus), site_url('campus/' . $campus['id_campus'])],
        [$nome, null],
    ]], ['saveData' => false]) ?>

    <div style="<?= esc(campus_estilo($campus), 'attr') ?>">
        <?= view('institucional/_faixa_campus', [
            'campus' => $campus,
            'titulo' => $nome,
            'sub'    => campus_rotulo($campus),
            'acoes'  => ($podeEditar ? ui_button(lang('Ui.edit'), 'edit', ['href' => site_url("predios/{$id}/editar")]) : '')
                . ($podeExcluir ? ui_button(lang('Ui.delete'), 'delete', ['@click' => "\$dispatch('open-modal', 'excluir-predio')"]) : ''),
        ], ['saveData' => false]) ?>
        <?php if ($podeExcluir) : ?>
            <?= ui_confirm_delete('excluir-predio', lang('Predios.deleteText', [$nome]), site_url("predios/{$id}"), lang('Predios.deleteTitle')) ?>
        <?php endif ?>

        <?= ui_recolhivel_inicio(lang('Ui.recordDetails')) ?>
        <div class="grid gap-space-6 md:grid-cols-2">
            <section aria-labelledby="predio-dados" class="rounded-md border border-border bg-surface shadow-xs p-space-6">
                <h2 id="predio-dados" class="mb-space-4 text-heading"><?= esc(lang('Institucional.sectionAddress')) ?></h2>
                <dl class="mb-space-4 grid grid-cols-[auto_1fr] gap-x-space-4 gap-y-space-2 text-body">
                    <dt class="text-ink-muted"><?= esc(lang('Institucional.fieldAbbreviation')) ?></dt>
                    <dd><?= esc($predio['abreviacao']) ?></dd>
                    <?php if (! empty($predio['numero'])) : ?>
                        <dt class="text-ink-muted"><?= esc(lang('Institucional.fieldNumber')) ?></dt>
                        <dd class="tabular-nums"><?= esc($predio['numero']) ?></dd>
                    <?php endif ?>
                </dl>
                <?= view('institucional/_endereco_ver', ['registro' => $predio], ['saveData' => false]) ?>
            </section>

            <?php if (! empty($predio['foto'])) : ?>
                <figure class="rounded-md border border-border bg-surface shadow-xs p-space-4">
                    <img src="<?= esc(arquivo_url((string) $predio['foto']), 'attr') ?>" alt="<?= esc(lang('Predios.photoAlt', [$nome]), 'attr') ?>" class="w-full rounded-sm object-cover">
                </figure>
            <?php endif ?>

            <?php if (! empty($predio['mapa'])) : ?>
                <?php $mapaUrl = arquivo_url((string) $predio['mapa']); ?>
                <section aria-labelledby="predio-mapa" class="rounded-md border border-border bg-surface shadow-xs p-space-6">
                    <h2 id="predio-mapa" class="mb-space-4 text-heading"><?= esc(lang('Predios.fieldMap')) ?></h2>
                    <?php if (\App\Libraries\Arquivos::ehPdf((string) $predio['mapa'])) : ?>
                        <a href="<?= esc($mapaUrl, 'attr') ?>" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center text-body underline">
                            <?= esc(lang('Predios.viewMapPdf')) ?>
                        </a>
                    <?php else : ?>
                        <a href="<?= esc($mapaUrl, 'attr') ?>" target="_blank" rel="noopener">
                            <img src="<?= esc($mapaUrl, 'attr') ?>" alt="<?= esc(lang('Predios.mapAlt', [$nome]), 'attr') ?>" class="w-full rounded-sm object-contain">
                        </a>
                    <?php endif ?>
                </section>
            <?php endif ?>
        </div>
        <?= ui_recolhivel_fim() ?>

        <section aria-labelledby="predio-ambientes">
            <div class="mb-space-4 flex flex-wrap items-center justify-between gap-space-4">
                <h2 id="predio-ambientes" class="text-title"><?= esc(lang('Predios.environments')) ?></h2>
                <div class="flex flex-wrap items-center gap-space-2">
                    <?= $tabela->botaoExportar() ?>
                    <?php if ($podeEditar) : ?>
                        <?= ui_button(lang('Ambientes.new'), 'primary', ['href' => site_url("predios/{$id}/ambientes/novo")]) ?>
                    <?php endif ?>
                </div>
            </div>

            <?= $tabela->renderizar() ?>
        </section>
    </div>
<?= $this->endSection() ?>

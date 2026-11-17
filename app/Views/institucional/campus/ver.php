<?php

/**
 * Página do campus: identidade visual aplicada (contêiner com campus_estilo), dados, endereço,
 * foto, mapa e a lista de prédios visíveis.
 *
 * @var array<string, mixed>       $campus
 * @var App\Libraries\Tabela\Tabela $tabela prédios do campus
 * @var bool                       $podeEditar
 * @var bool                       $podeExcluir
 */
$id   = (int) $campus['id_campus'];
$nome = (string) $campus['nome'];
$temIdentidade = campus_estilo($campus) !== '';
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($nome) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [[lang('Campus.title'), site_url('campus')], [campus_rotulo($campus), null]]], ['saveData' => false]) ?>

    <div style="<?= esc(campus_estilo($campus), 'attr') ?>">
        <?= view('institucional/_faixa_campus', [
            'campus' => $campus,
            'titulo' => $nome,
            'sub'    => (string) $campus['abreviacao'],
            'acoes'  => ($podeEditar ? ui_button(lang('Ui.edit'), 'edit', ['href' => site_url("campus/{$id}/editar")])
                    . ui_button(lang('Agendamento.settings'), 'secondary', ['href' => site_url("campus/{$id}/configuracoes")]) : '')
                . ($podeExcluir ? ui_button(lang('Ui.delete'), 'delete', ['@click' => "\$dispatch('open-modal', 'excluir-campus')"]) : ''),
        ], ['saveData' => false]) ?>
        <?php if ($podeExcluir) : ?>
            <?= ui_confirm_delete('excluir-campus', lang('Campus.deleteText', [$nome]), site_url("campus/{$id}"), lang('Campus.deleteTitle')) ?>
        <?php endif ?>

        <?= ui_recolhivel_inicio(lang('Ui.recordDetails')) ?>
        <div class="grid gap-space-6 md:grid-cols-2">
            <section aria-labelledby="campus-dados" class="rounded-md border border-border bg-surface shadow-xs p-space-6">
                <h2 id="campus-dados" class="mb-space-4 text-heading"><?= esc(lang('Institucional.sectionData')) ?></h2>
                <dl class="grid grid-cols-[auto_1fr] gap-x-space-4 gap-y-space-2 text-body">
                    <?php if (! empty($campus['numero'])) : ?>
                        <dt class="text-ink-muted"><?= esc(lang('Institucional.fieldNumber')) ?></dt>
                        <dd class="tabular-nums"><?= esc($campus['numero']) ?></dd>
                    <?php endif ?>
                    <dt class="text-ink-muted"><?= esc(lang('Ui.status')) ?></dt>
                    <dd><?= (bool) $campus['ativo'] ? ui_status_dot('confirmed', lang('Ui.active')) : ui_status_dot('critical', lang('Ui.inactive')) ?></dd>
                    <dt class="text-ink-muted"><?= esc(lang('Campus.sectionIdentity')) ?></dt>
                    <dd>
                        <?php if ($temIdentidade) : ?>
                            <span class="inline-flex items-center gap-space-2">
                                <span class="size-4 shrink-0 rounded-sm border border-border-strong bg-brand" aria-hidden="true"></span>
                                <?= esc(lang('Campus.identityColors', [(string) $campus['cor_primaria'], lang('Campus.text_' . $campus['cor_texto'])])) ?>
                            </span>
                        <?php else : ?>
                            <?= esc(lang('Campus.defaultIdentity')) ?>
                        <?php endif ?>
                    </dd>
                </dl>
            </section>

            <section aria-labelledby="campus-endereco" class="rounded-md border border-border bg-surface shadow-xs p-space-6">
                <h2 id="campus-endereco" class="mb-space-4 text-heading"><?= esc(lang('Institucional.sectionAddress')) ?></h2>
                <?= view('institucional/_endereco_ver', ['registro' => $campus], ['saveData' => false]) ?>
            </section>

            <?php if (! empty($campus['foto'])) : ?>
                <figure class="rounded-md border border-border bg-surface shadow-xs p-space-4">
                    <img src="<?= esc(arquivo_url((string) $campus['foto']), 'attr') ?>" alt="<?= esc(lang('Campus.photoAlt', [$nome]), 'attr') ?>" class="w-full rounded-sm object-cover">
                </figure>
            <?php endif ?>

            <?php if (! empty($campus['mapa'])) : ?>
                <section aria-labelledby="campus-mapa" class="rounded-md border border-border bg-surface shadow-xs p-space-6">
                    <h2 id="campus-mapa" class="mb-space-4 text-heading"><?= esc(lang('Campus.fieldMap')) ?></h2>
                    <?php if (\App\Libraries\Arquivos::ehPdf((string) $campus['mapa'])) : ?>
                        <a href="<?= esc(arquivo_url((string) $campus['mapa']), 'attr') ?>" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center text-body underline">
                            <?= esc(lang('Campus.viewMapPdf')) ?>
                        </a>
                    <?php else : ?>
                        <a href="<?= esc(arquivo_url((string) $campus['mapa']), 'attr') ?>" target="_blank" rel="noopener">
                            <img src="<?= esc(arquivo_url((string) $campus['mapa']), 'attr') ?>" alt="<?= esc(lang('Campus.mapAlt', [$nome]), 'attr') ?>" class="w-full rounded-sm object-contain">
                        </a>
                    <?php endif ?>
                </section>
            <?php endif ?>
        </div>
        <?= ui_recolhivel_fim() ?>

        <section aria-labelledby="campus-predios">
            <div class="mb-space-4 flex flex-wrap items-center justify-between gap-space-4">
                <h2 id="campus-predios" class="text-title"><?= esc(lang('Campus.buildings')) ?></h2>
                <div class="flex flex-wrap items-center gap-space-2">
                    <?= $tabela->botaoExportar() ?>
                    <?php if ($podeEditar) : ?>
                        <?= ui_button(lang('Predios.new'), 'primary', ['href' => site_url("campus/{$id}/predios/novo")]) ?>
                    <?php endif ?>
                </div>
            </div>

            <?= $tabela->renderizar() ?>
        </section>
    </div>
<?= $this->endSection() ?>

<?php

/**
 * Página da disciplina: dados e o curso a que pertence.
 *
 * @var array<string, mixed>       $disciplina
 * @var array<string, mixed>|null  $curso
 * @var array<string, string>      $erros
 * @var bool                       $podeEditar
 */
$id   = (int) $disciplina['id_disciplina'];
$nome = (string) $disciplina['nome'];
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($nome) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [[lang('Disciplinas.title'), site_url('disciplinas')], [$nome, null]]], ['saveData' => false]) ?>

    <p class="text-data tabular-nums text-ink-muted"><?= esc($disciplina['sigla']) ?></p>
    <h1 class="mb-space-6 text-display"><?= esc($nome) ?></h1>

    <?php if ($podeEditar) : ?>
        <div class="mb-space-8 flex flex-wrap gap-space-3">
            <?= ui_button(lang('Ui.edit'), 'edit', ['href' => site_url("disciplinas/{$id}/editar")]) ?>
            <?= ui_button(lang('Ui.delete'), 'delete', ['@click' => "\$dispatch('open-modal', 'excluir-disciplina')"]) ?>
            <?= ui_confirm_delete('excluir-disciplina', lang('Disciplinas.deleteText', [$nome]), site_url("disciplinas/{$id}"), lang('Disciplinas.deleteTitle')) ?>
        </div>
    <?php endif ?>

    <dl class="max-w-3xl rounded-md border border-border bg-surface p-space-4 shadow-xs">
        <dt class="text-data text-ink-muted"><?= esc(lang('Disciplinas.fieldCourse')) ?></dt>
        <dd class="text-body"><?= esc($curso['nome'] ?? '') ?><?= isset($curso['abreviacao']) ? ' · ' . esc($curso['abreviacao']) : '' ?></dd>
    </dl>
<?= $this->endSection() ?>

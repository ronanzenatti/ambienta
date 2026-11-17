<?php

/**
 * Oferta de reativação: o e-mail/CPF informado já pertence a um cadastro excluído.
 *
 * @var array<string, mixed> $removido
 * @var string               $campo    'email' | 'cpf'
 * @var array<string, mixed> $vinculo  perfil inicial digitado no formulário de cadastro
 */
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc(lang('Usuarios.reactivateTitle')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <h1 class="mb-space-4 text-display"><?= esc(lang('Usuarios.reactivateTitle')) ?></h1>

    <div class="mb-space-6 max-w-xl rounded-md border border-border bg-surface shadow-xs p-space-6">
        <?= ui_status_dot('pending', lang('Usuarios.reactivateText', [
            lang('Usuarios.reactivateField.' . $campo),
            (string) $removido['nome'],
            (string) $removido['email'],
        ])) ?>
    </div>

    <form method="post" action="<?= site_url('usuarios/reativar/' . $removido['id_usuario']) ?>" class="flex flex-wrap gap-space-3">
        <?= csrf_field() ?>
        <?php foreach (['perfil_id', 'escopo_tipo', 'campus_id', 'predio_id'] as $chave) : ?>
            <input type="hidden" name="<?= esc($chave, 'attr') ?>" value="<?= esc((string) $vinculo[$chave], 'attr') ?>">
        <?php endforeach ?>
        <?= ui_button(lang('Usuarios.reactivate'), 'primary', ['type' => 'submit']) ?>
        <?= ui_button(lang('Ui.cancel'), 'secondary', ['href' => site_url('usuarios')]) ?>
    </form>
<?= $this->endSection() ?>

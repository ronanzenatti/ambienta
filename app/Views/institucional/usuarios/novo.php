<?php

/**
 * Cadastro de usuário, com o perfil inicial.
 *
 * @var array<string, mixed>  $dados
 * @var array<string, string> $erros
 * @var array{global: bool, campus: bool, predios: bool}  $escopos
 * @var array<string, mixed>  $vinculo
 */
helper('usuarios');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc(lang('Usuarios.formNew')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <h1 class="mb-space-6 text-display"><?= esc(lang('Usuarios.formNew')) ?></h1>

    <form method="post" action="<?= site_url('usuarios') ?>" class="max-w-xl rounded-md border border-border bg-surface shadow-xs p-space-6">
        <?= csrf_field() ?>
        <?= ui_field('nome', lang('Usuarios.fieldName'), 'text', (string) $dados['nome'], $erros['nome'] ?? null, null, ['required' => true, 'maxlength' => '255', 'autocomplete' => 'off']) ?>
        <?= ui_field('email', lang('Usuarios.fieldEmail'), 'email', (string) $dados['email'], $erros['email'] ?? null, null, ['required' => true, 'maxlength' => '255', 'autocomplete' => 'off']) ?>
        <?= ui_field('cpf', lang('Usuarios.fieldCpf'), 'text', (string) $dados['cpf'], $erros['cpf'] ?? null, null, ['inputmode' => 'numeric', 'maxlength' => '14', 'autocomplete' => 'off']) ?>
        <?= ui_field('senha', lang('Usuarios.fieldPassword'), 'password', null, $erros['senha'] ?? null, lang('Usuarios.passwordHelp'), ['autocomplete' => 'new-password', 'minlength' => '8']) ?>
        <?= ui_select('idioma_preferido', lang('Usuarios.fieldLanguage'), usuarios_idiomas(), (string) $dados['idioma_preferido'], $erros['idioma_preferido'] ?? null) ?>

        <fieldset class="mb-space-4 border-t border-border pt-space-4">
            <legend class="text-heading"><?= esc(lang('Usuarios.initialProfile')) ?></legend>
            <?= view('institucional/usuarios/_vinculo_campos', [
                'escopos' => $escopos, 'vinculo' => $vinculo, 'erros' => $erros,
                'obrigatorio' => ! $escopos['global'],
            ], ['saveData' => false]) ?>
        </fieldset>

        <div class="flex flex-wrap gap-space-3">
            <?= ui_button(lang('Ui.save'), 'primary', ['type' => 'submit']) ?>
            <?= ui_button(lang('Ui.cancel'), 'secondary', ['href' => site_url('usuarios')]) ?>
        </div>
    </form>
<?= $this->endSection() ?>

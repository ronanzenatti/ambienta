<?php

/**
 * Troca rápida de senha de um usuário: na página (senha.php) ou no modal da listagem ($modal).
 *
 * @var array<string, mixed>  $usuario
 * @var array<string, string> $erros
 * @var bool                  $modal
 */
$id      = (int) $usuario['id_usuario'];
$titulo  = lang('Usuarios.passwordTitle');
$enviar  = service('request')->getPost('enviar_email') !== '0';
$radio   = 'size-5 accent-brand';
?>
<form <?= ui_form_attrs(site_url("usuarios/{$id}/senha"), $titulo, $modal) ?> x-data="ambientaSenha()">
    <?= csrf_field() ?>
    <p class="mb-space-4 text-body"><?= esc(lang('Usuarios.passwordIntro', [(string) $usuario['nome'], (string) $usuario['email']])) ?></p>

    <?= ui_field('senha', lang('Usuarios.fieldNewPassword'), 'text', (string) service('request')->getPost('senha'), $erros['senha'] ?? null, lang('Usuarios.newPasswordHelp'), [
        'required' => true, 'minlength' => '8', 'maxlength' => '72', 'autocomplete' => 'off', 'spellcheck' => 'false',
        'x-ref' => 'senha', 'autofocus' => true,
    ]) ?>
    <div class="mb-space-4">
        <?php /* Sem JavaScript o botão não aparece: a senha é digitada. */ ?>
        <?= ui_button(lang('Usuarios.suggestPassword'), 'secondary', ['type' => 'button', 'hidden' => true, ':hidden' => 'false', '@click' => 'propor()']) ?>
    </div>

    <p class="mb-space-4 text-body"><?= esc(lang('Usuarios.mustChangeNotice')) ?></p>

    <fieldset class="mb-space-6">
        <legend class="mb-space-1 block text-body-strong"><?= esc(lang('Usuarios.sendEmailQuestion')) ?></legend>
        <div class="flex flex-wrap gap-x-space-6">
            <label class="inline-flex min-h-11 items-center gap-space-2 text-body">
                <input type="radio" name="enviar_email" value="1" class="<?= $radio ?>"<?= $enviar ? ' checked' : '' ?>>
                <?= esc(lang('Ui.yes')) ?>
            </label>
            <label class="inline-flex min-h-11 items-center gap-space-2 text-body">
                <input type="radio" name="enviar_email" value="0" class="<?= $radio ?>"<?= $enviar ? '' : ' checked' ?>>
                <?= esc(lang('Ui.no')) ?>
            </label>
        </div>
    </fieldset>

    <?= ui_form_buttons(false, site_url('usuarios'), $modal) ?>
</form>

<?php

/**
 * Formulário de novo domínio de e-mail: na página (form.php) ou no modal da listagem ($modal).
 *
 * @var string                $valor
 * @var array<string, string> $erros
 * @var bool                  $modal
 */
?>
<form <?= ui_form_attrs(site_url('dominios'), lang('Dominios.new'), $modal) ?>>
    <?= csrf_field() ?>
    <?= ui_field('dominio', lang('Dominios.fieldDomain'), 'text', $valor, $erros['dominio'] ?? null, null, ['required' => true, 'maxlength' => '255', 'autocomplete' => 'off', 'autocapitalize' => 'none']) ?>
    <?= ui_form_buttons(true, site_url('dominios'), $modal) ?>
</form>

<?php

/**
 * Campos de dados, endereço estruturado e coordenadas, comuns a campus e prédio.
 *
 * @var array<string, mixed>  $registro
 * @var array<string, string> $erros
 */
$v = static fn (string $campo): string => (string) ($registro[$campo] ?? '');
$e = static fn (string $campo): ?string => $erros[$campo] ?? null;
?>
<fieldset class="mb-space-6">
    <legend class="mb-space-2 text-heading"><?= esc(lang('Institucional.sectionData')) ?></legend>
    <?= ui_field('nome', lang('Institucional.fieldName'), 'text', $v('nome'), $e('nome'), null, ['required' => true, 'maxlength' => '255']) ?>
    <div class="grid gap-x-space-4 sm:grid-cols-2">
        <?= ui_field('abreviacao', lang('Institucional.fieldAbbreviation'), 'text', $v('abreviacao'), $e('abreviacao'), null, ['required' => true, 'maxlength' => '20']) ?>
        <?= ui_field('numero', lang('Institucional.fieldNumber'), 'text', $v('numero'), $e('numero'), null, ['maxlength' => '20']) ?>
    </div>
</fieldset>

<fieldset class="mb-space-6">
    <legend class="mb-space-2 text-heading"><?= esc(lang('Institucional.sectionAddress')) ?></legend>
    <div class="grid gap-x-space-4 sm:grid-cols-[3fr_1fr]">
        <?= ui_field('logradouro', lang('Institucional.fieldStreet'), 'text', $v('logradouro'), $e('logradouro'), null, ['maxlength' => '255', 'autocomplete' => 'address-line1']) ?>
        <?= ui_field('numero_endereco', lang('Institucional.fieldStreetNumber'), 'text', $v('numero_endereco'), $e('numero_endereco'), null, ['maxlength' => '20']) ?>
    </div>
    <div class="grid gap-x-space-4 sm:grid-cols-2">
        <?= ui_field('complemento', lang('Institucional.fieldComplement'), 'text', $v('complemento'), $e('complemento'), null, ['maxlength' => '100', 'autocomplete' => 'address-line2']) ?>
        <?= ui_field('bairro', lang('Institucional.fieldDistrict'), 'text', $v('bairro'), $e('bairro'), null, ['maxlength' => '100']) ?>
    </div>
    <div class="grid gap-x-space-4 sm:grid-cols-[3fr_1fr_2fr]">
        <?= ui_field('cidade', lang('Institucional.fieldCity'), 'text', $v('cidade'), $e('cidade'), null, ['maxlength' => '100', 'autocomplete' => 'address-level2']) ?>
        <?= ui_field('uf', lang('Institucional.fieldState'), 'text', $v('uf'), $e('uf'), lang('Institucional.stateHelp'), ['maxlength' => '2', 'autocomplete' => 'address-level1', 'autocapitalize' => 'characters']) ?>
        <?= ui_field('cep', lang('Institucional.fieldPostalCode'), 'text', $v('cep'), $e('cep'), null, ['maxlength' => '9', 'inputmode' => 'numeric', 'autocomplete' => 'postal-code']) ?>
    </div>
</fieldset>

<fieldset class="mb-space-6">
    <legend class="mb-space-2 text-heading"><?= esc(lang('Institucional.sectionLocation')) ?></legend>
    <p class="mb-space-2 text-caption text-ink-muted"><?= esc(lang('Institucional.coordsHelp')) ?></p>
    <div class="grid gap-x-space-4 sm:grid-cols-2">
        <?= ui_field('latitude', lang('Institucional.fieldLatitude'), 'text', $v('latitude'), $e('latitude'), null, ['inputmode' => 'decimal']) ?>
        <?= ui_field('longitude', lang('Institucional.fieldLongitude'), 'text', $v('longitude'), $e('longitude'), null, ['inputmode' => 'decimal']) ?>
    </div>
</fieldset>

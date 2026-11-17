<?php

/**
 * Campos de um vínculo perfil + escopo (usado ao criar o usuário e ao atribuir outro perfil).
 * O escopo oferecido já vem limitado ao que quem administra pode conceder; perfis, campus e prédios vêm do
 * banco sob demanda (fontes perfis, campi e predios, no escopo de usuarios.acesso editor).
 *
 * @var array{global: bool, campus: bool, predios: bool}                            $escopos
 * @var array{perfil_id: int, escopo_tipo: string, campus_id: int, predio_id: int}  $vinculo
 * @var array<string, string>                                                      $erros
 * @var bool                                                                       $obrigatorio
 */
$tipos = [];
if ($escopos['global']) {
    $tipos['global'] = lang('Usuarios.scopeTypeGlobal');
}
if ($escopos['campus']) {
    $tipos['campus'] = lang('Usuarios.scopeTypeCampus');
}
if ($escopos['predios']) {
    $tipos['predio'] = lang('Usuarios.scopeTypePredio');
}

$escopoUsuarios = ['chave' => 'usuarios.acesso', 'nivel' => 'editor'];
$tipoInicial    = isset($tipos[$vinculo['escopo_tipo']]) ? $vinculo['escopo_tipo'] : (string) (array_key_first($tipos) ?? 'global');
?>
<div x-data="{ tipo: '<?= esc($tipoInicial, 'js') ?>' }">
    <?= ui_select_fonte('perfil_id', lang('Usuarios.fieldProfile'), 'perfis', [], $vinculo['perfil_id'] ?: null, lang('Ui.select'), $erros['perfil_id'] ?? null, null, null, $obrigatorio ? ['required' => true] : []) ?>

    <?php if ($tipos !== []) : ?>
        <?= ui_select('escopo_tipo', lang('Usuarios.fieldScopeType'), $tipos, $tipoInicial, null, null, ['x-model' => 'tipo']) ?>

        <?php if ($escopos['campus']) : ?>
            <div x-show="tipo === 'campus'">
                <?= ui_select_fonte('campus_id', lang('Usuarios.fieldCampus'), 'campi', $escopoUsuarios, $vinculo['campus_id'] ?: null, lang('Ui.select')) ?>
            </div>
        <?php endif ?>

        <?php if ($escopos['predios']) : ?>
            <div x-show="tipo === 'predio'">
                <?= ui_select_fonte('predio_id', lang('Usuarios.fieldPredio'), 'predios', $escopoUsuarios, $vinculo['predio_id'] ?: null, lang('Ui.select')) ?>
            </div>
        <?php endif ?>

        <?php if (! $escopos['campus'] && ! $escopos['predios']) : ?>
            <p class="mb-space-4 text-caption text-ink-muted"><?= esc(lang('Usuarios.noScopes')) ?></p>
        <?php endif ?>
    <?php endif ?>
</div>

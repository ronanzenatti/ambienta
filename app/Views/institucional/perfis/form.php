<?php

/**
 * Perfil e matriz de permissões: nível por módulo (sem acesso / leitor / editor) e permissões
 * específicas (caixas de marcação).
 *
 * @var array<string, mixed>                          $perfil
 * @var array<string, string>                         $matriz   chave => leitor|editor
 * @var array<string, string>                         $erros
 * @var array<string, list<array<string, mixed>>>     $catalogo permissões agrupadas por módulo
 */
$super = (bool) ($perfil['super_perfil'] ?? false);
$novo  = ! isset($perfil['id_perfil']);
$acao  = $novo ? site_url('perfis') : site_url('perfis/' . $perfil['id_perfil']);
$niveis = ['' => lang('Perfis.accessNone'), 'leitor' => lang('Perfis.accessReader'), 'editor' => lang('Perfis.accessEditor')];
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc(lang($novo ? 'Perfis.formNew' : 'Perfis.formEdit')) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <h1 class="mb-space-6 text-display"><?= esc(lang($novo ? 'Perfis.formNew' : 'Perfis.formEdit')) ?></h1>

    <?php if ($super) : ?>
        <?= ui_alert('pending', lang('Perfis.superNote')) ?>
        <?= ui_button(lang('Ui.back'), 'secondary', ['href' => site_url('perfis')]) ?>
    <?php else : ?>
        <form method="post" action="<?= $acao ?>" class="max-w-2xl rounded-md border border-border bg-surface shadow-xs p-space-6">
            <?= csrf_field() ?>
            <?= ui_field('nome', lang('Perfis.fieldName'), 'text', (string) $perfil['nome'], $erros['nome'] ?? null, null, ['required' => true, 'maxlength' => '100']) ?>

            <fieldset class="mb-space-6">
                <legend class="mb-space-2 text-heading"><?= esc(lang('Perfis.moduleAccess')) ?></legend>
                <?php foreach ($catalogo as $permissoes) : ?>
                    <?php foreach ($permissoes as $permissao) : ?>
                        <?php if (\App\Libraries\CatalogoPermissoes::temNivel($permissao['chave'])) : ?>
                            <?= ui_select(
                                'acesso[' . $permissao['chave'] . ']',
                                $permissao['nome'],
                                $niveis,
                                $matriz[$permissao['chave']] ?? ''
                            ) ?>
                        <?php endif ?>
                    <?php endforeach ?>
                <?php endforeach ?>
            </fieldset>

            <fieldset class="mb-space-6">
                <legend class="text-heading"><?= esc(lang('Perfis.specific')) ?></legend>
                <p class="mb-space-2 text-caption text-ink-muted"><?= esc(lang('Perfis.specificHelp')) ?></p>
                <?php foreach ($catalogo as $permissoes) : ?>
                    <?php foreach ($permissoes as $permissao) : ?>
                        <?php if (! \App\Libraries\CatalogoPermissoes::temNivel($permissao['chave'])) : ?>
                            <div class="flex min-h-11 items-center gap-space-3">
                                <input type="checkbox" id="esp-<?= esc($permissao['chave'], 'attr') ?>" name="especifica[]"
                                       value="<?= esc($permissao['chave'], 'attr') ?>" <?= isset($matriz[$permissao['chave']]) ? 'checked' : '' ?>
                                       role="switch" class="toggle">
                                <label for="esp-<?= esc($permissao['chave'], 'attr') ?>" class="text-body"><?= esc($permissao['nome']) ?></label>
                            </div>
                        <?php endif ?>
                    <?php endforeach ?>
                <?php endforeach ?>
            </fieldset>

            <div class="flex flex-wrap gap-space-3">
                <?= ui_button(lang('Ui.save'), $novo ? 'primary' : 'edit', ['type' => 'submit']) ?>
                <?= ui_button(lang('Ui.cancel'), 'secondary', ['href' => site_url('perfis')]) ?>
            </div>
        </form>
    <?php endif ?>
<?= $this->endSection() ?>

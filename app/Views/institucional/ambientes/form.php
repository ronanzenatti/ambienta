<?php

/**
 * Cadastro e alteração de ambiente.
 *
 * @var array<string, mixed>  $predio
 * @var array<string, mixed>  $campus
 * @var array<string, mixed>  $ambiente
 * @var bool                  $temCategorias há categorias de ambiente cadastradas
 * @var array<string, string> $erros
 */
$novo   = ! isset($ambiente['id_ambiente']);
$acao   = $novo ? site_url('predios/' . $predio['id_predio'] . '/ambientes') : site_url('ambientes/' . $ambiente['id_ambiente']);
$voltar = $novo ? site_url('predios/' . $predio['id_predio']) : site_url('ambientes/' . $ambiente['id_ambiente']);
$titulo = lang($novo ? 'Ambientes.new' : 'Ambientes.edit');
$v      = static fn (string $campo): string => (string) ($ambiente[$campo] ?? '');
$e      = static fn (string $campo): ?string => $erros[$campo] ?? null;

$confirmacao = $ambiente['exige_confirmacao_reserva'] ?? null;
$confirmacao = $confirmacao === null || $confirmacao === '' ? '' : (string) (int) $confirmacao;
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [
        [lang('Campus.title'), site_url('campus')],
        [campus_rotulo($campus), site_url('campus/' . $campus['id_campus'])],
        [(string) $predio['nome'], site_url('predios/' . $predio['id_predio'])],
        [$titulo, null],
    ]], ['saveData' => false]) ?>

    <h1 class="mb-space-6 text-display"><?= esc($titulo) ?></h1>

    <?php if (! $temCategorias) : ?>
        <?= ui_alert('pending', lang('Ambientes.noCategories')) ?>
        <?= ui_button(lang('Institucional.navCategories'), 'secondary', ['href' => site_url('categorias-ambiente')]) ?>
    <?php else : ?>
        <form method="post" action="<?= $acao ?>" enctype="multipart/form-data" class="max-w-3xl rounded-md border border-border bg-surface shadow-xs p-space-6">
            <?= csrf_field() ?>

            <div class="grid gap-x-space-4 sm:grid-cols-2">
                <?= ui_field('numero', lang('Institucional.fieldNumber'), 'text', $v('numero'), $e('numero'), null, ['required' => true, 'maxlength' => '20']) ?>
                <?= ui_field('abreviacao', lang('Institucional.fieldAbbreviation'), 'text', $v('abreviacao'), $e('abreviacao'), null, ['required' => true, 'maxlength' => '20']) ?>
            </div>
            <?= ui_field('nome', lang('Institucional.fieldName'), 'text', $v('nome'), $e('nome'), null, ['required' => true, 'maxlength' => '255']) ?>
            <?= ui_select_fonte('categoria_ambiente_id', lang('Ambientes.fieldCategory'), 'categorias-ambiente', [], $v('categoria_ambiente_id'), lang('Ui.select'), $e('categoria_ambiente_id'), null, null, ['required' => true]) ?>

            <div class="mb-space-4">
                <label for="campo-descricao" class="mb-space-1 block text-body-strong"><?= esc(lang('Institucional.fieldDescription')) ?></label>
                <textarea id="campo-descricao" name="descricao" rows="3"
                          <?= $e('descricao') !== null ? 'aria-invalid="true" aria-describedby="campo-descricao-erro"' : '' ?>
                          class="w-full rounded-sm border border-border-strong bg-surface px-space-3 py-space-2 text-body"><?= esc($v('descricao')) ?></textarea>
                <?php if ($e('descricao') !== null) : ?>
                    <p id="campo-descricao-erro" class="mt-space-1 text-data text-signal-critical"><?= esc($e('descricao')) ?></p>
                <?php endif ?>
            </div>

            <div class="grid gap-x-space-4 sm:grid-cols-2">
                <?= ui_field('capacidade', lang('Ambientes.fieldCapacity'), 'number', $v('capacidade'), $e('capacidade'), null, ['required' => true, 'min' => '0', 'max' => '65535', 'step' => '1', 'inputmode' => 'numeric']) ?>
                <?= ui_field('tamanho_m2', lang('Ambientes.fieldSize'), 'text', $v('tamanho_m2'), $e('tamanho_m2'), lang('Ambientes.sizeHelp'), ['inputmode' => 'decimal']) ?>
            </div>

            <?= ui_select('exige_confirmacao_reserva', lang('Ambientes.fieldConfirmation'), [
                ''  => lang('Ambientes.confirmInherit'),
                '1' => lang('Ambientes.confirmYes'),
                '0' => lang('Ambientes.confirmNo'),
            ], $confirmacao, $e('exige_confirmacao_reserva')) ?>

            <?= ui_file(
                'foto',
                lang('Institucional.fieldPhoto'),
                'image/png,image/jpeg,image/webp',
                empty($ambiente['foto']) ? null : arquivo_url((string) $ambiente['foto']),
                true,
                lang('Ambientes.photoAlt', [$v('nome')]),
                $e('foto'),
                lang('Institucional.imageHelp')
            ) ?>


            <?= ui_checkbox('ativo', lang('Ambientes.fieldActive'), (bool) ($ambiente['ativo'] ?? true)) ?>

            <div class="flex flex-wrap gap-space-3">
                <?= ui_button(lang('Ui.save'), $novo ? 'primary' : 'edit', ['type' => 'submit']) ?>
                <?= ui_button(lang('Ui.cancel'), 'secondary', ['href' => $voltar]) ?>
            </div>
        </form>
    <?php endif ?>
<?= $this->endSection() ?>

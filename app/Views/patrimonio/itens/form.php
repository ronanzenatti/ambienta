<?php

/**
 * Cadastro e alteração de item. O item tem dados próprios (nome, marca, especificações, categoria): vir de um
 * modelo só copia os dados no cadastro, e depois o item segue por conta própria (ex.: PC que teve o SSD trocado).
 * O número de patrimônio é o que torna o item um patrimônio; patrimoniável sem número fica pendente.
 *
 * @var array<string, mixed>  $item
 * @var array<string, string> $erros
 * @var bool                  $temCategorias
 */
$novo          = ! isset($item['id_item']);
$id            = (int) ($item['id_item'] ?? 0);
$titulo        = lang($novo ? 'Patrimonio.itemNew' : 'Patrimonio.itemEdit');
$acao          = $novo ? site_url('patrimonio/itens') : site_url("patrimonio/itens/{$id}");
$voltar        = $novo ? site_url('patrimonio/itens') : site_url("patrimonio/itens/{$id}");
$v             = static fn (string $campo): string => (string) ($item[$campo] ?? '');
$e             = static fn (string $campo): ?string => $erros[$campo] ?? null;
$temNumero     = ! $novo && $item['numero_patrimonio'] !== null;
$patrimoniavel = (bool) ($item['patrimoniavel'] ?? true);
$emTransito    = ! $novo && ($item['status'] ?? '') === 'em_transito';
$trilha        = [[lang('Patrimonio.itemsTitle'), site_url('patrimonio/itens')]];

if (! $novo) {
    $trilha[] = [item_numero($item), site_url("patrimonio/itens/{$id}")];
}

$trilha[] = [$titulo, null];
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => $trilha], ['saveData' => false]) ?>

    <h1 class="mb-space-6 text-display"><?= esc($titulo) ?></h1>

    <?php if (! $temCategorias) : ?>
        <?= ui_alert('pending', lang('Patrimonio.noCategories')) ?>
    <?php else : ?>
        <form method="post" action="<?= $acao ?>" class="max-w-3xl rounded-md border border-border bg-surface shadow-xs p-space-6">
            <?= csrf_field() ?>

            <?= ui_field('nome', lang('Institucional.fieldName'), 'text', $v('nome'), $e('nome'), lang('Patrimonio.itemNameHelp'), ['required' => true, 'maxlength' => '255']) ?>
            <div class="grid gap-x-space-4 sm:grid-cols-2">
                <?= ui_field('marca', lang('Patrimonio.fieldBrand'), 'text', $v('marca'), $e('marca'), null, ['maxlength' => '100']) ?>
                <?= ui_select_fonte('categoria_item_id', lang('Patrimonio.fieldCategory'), 'categorias-item', [], $v('categoria_item_id'), lang('Ui.select'), $e('categoria_item_id'), null, null, ['required' => true]) ?>
            </div>
            <?= ui_textarea('especificacoes', lang('Patrimonio.fieldSpecs'), $v('especificacoes'), $e('especificacoes'), lang('Patrimonio.itemSpecsHelp'), 4) ?>

            <?php if ($temNumero) : ?>
                <input type="hidden" name="patrimoniavel" value="1">
                <?= ui_field('numero_patrimonio', lang('Patrimonio.fieldNumber'), 'text', $v('numero_patrimonio'), $e('numero_patrimonio'), lang('Patrimonio.numberHelp'), ['required' => true, 'maxlength' => '30', 'autocomplete' => 'off']) ?>
            <?php else : ?>
                <div x-data="{ patrimonial: <?= $patrimoniavel ? 'true' : 'false' ?> }"
                     @change="if ($event.target.name === 'patrimoniavel') patrimonial = $event.target.checked">
                <?= ui_checkbox('patrimoniavel', lang('Patrimonio.fieldAssetControlled'), $patrimoniavel) ?>
                <?php if ($e('patrimoniavel') !== null) : ?>
                    <p class="-mt-space-2 mb-space-4 text-data text-signal-critical"><?= esc($e('patrimoniavel')) ?></p>
                <?php endif ?>
                <p class="-mt-space-2 mb-space-4 text-caption text-ink-muted"><?= esc(lang('Patrimonio.assetControlledHelp')) ?></p>
                <?= ui_field('numero_patrimonio', lang('Patrimonio.fieldNumber'), 'text', $v('numero_patrimonio'), $e('numero_patrimonio'), lang('Patrimonio.numberOptionalHelp'), ['maxlength' => '30', 'autocomplete' => 'off']) ?>
                <?= ui_field('quantidade', lang('Patrimonio.fieldQuantity'), 'number', $v('quantidade') === '' ? '1' : $v('quantidade'), $e('quantidade'), lang('Patrimonio.quantityHelp'), ['min' => '1', 'step' => '1', 'inputmode' => 'numeric', ':disabled' => 'patrimonial', 'x-effect' => "if (patrimonial) \$el.value = 1"] + ($emTransito ? ['readonly' => true] : [])) ?>
                </div>
            <?php endif ?>

            <?php if ($novo) : ?>
                <?= ui_select_fonte('local', lang('Patrimonio.fieldLocation'), 'locais', ['chave' => 'patrimonio.acesso', 'nivel' => 'editor'], $v('local'), lang('Ui.select'), $e('local'), null, null, ['required' => true]) ?>
            <?php endif ?>

            <?= ui_form_buttons($novo, $voltar, false) ?>
        </form>
    <?php endif ?>
<?= $this->endSection() ?>

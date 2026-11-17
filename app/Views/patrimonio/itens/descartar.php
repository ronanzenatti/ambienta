<?php

/**
 * Pedido de descarte (baixa) de um item: motivo da lista fixa e justificativa. Vai para aprovação.
 *
 * @var array<string, mixed>      $item
 * @var array<string, mixed>      $dados
 * @var array<string, string>     $erros
 */
$id      = (int) $item['id_item'];
$numero  = item_numero($item);
$parcial = ! (bool) $item['patrimoniavel'] && (int) $item['quantidade'] > 1;
$titulo = lang('Patrimonio.requestDisposal');
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?><?= esc($titulo) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
    <?= view('institucional/_trilha', ['trilha' => [
        [lang('Patrimonio.itemsTitle'), site_url('patrimonio/itens')],
        [$numero, site_url("patrimonio/itens/{$id}")],
        [$titulo, null],
    ]], ['saveData' => false]) ?>

    <h1 class="mb-space-2 text-display"><?= esc($titulo) ?></h1>
    <p class="mb-space-6 max-w-2xl text-body text-ink-muted"><?= esc(lang('Patrimonio.requestDisposalIntro', [item_rotulo($item)])) ?></p>

    <form method="post" action="<?= site_url("patrimonio/itens/{$id}/descartar") ?>" class="max-w-2xl rounded-md border border-border bg-surface shadow-xs p-space-6">
        <?= csrf_field() ?>
        <?php if ($parcial) : ?>
            <?= ui_field('quantidade', lang('Patrimonio.fieldQuantity'), 'number', (string) ($dados['quantidade'] ?? ''), $erros['quantidade'] ?? null, lang('Patrimonio.disposeQuantityHelp', [(int) $item['quantidade']]), ['min' => '1', 'max' => (string) (int) $item['quantidade'], 'step' => '1', 'inputmode' => 'numeric']) ?>
        <?php endif ?>
        <?= ui_select_fonte('motivo_descarte_id', lang('Patrimonio.fieldReason'), 'motivos-descarte', [], $dados['motivo_descarte_id'] ?? null, lang('Ui.select'), $erros['motivo_descarte_id'] ?? null, null, null, ['required' => true]) ?>
        <?= ui_textarea('justificativa', lang('Patrimonio.fieldJustification'), (string) ($dados['justificativa'] ?? ''), $erros['justificativa'] ?? null, null, 4) ?>
        <div class="flex flex-wrap gap-space-3">
            <?= ui_button(lang('Patrimonio.sendRequest'), 'primary', ['type' => 'submit']) ?>
            <?= ui_button(lang('Ui.cancel'), 'secondary', ['href' => site_url("patrimonio/itens/{$id}")]) ?>
        </div>
    </form>
<?= $this->endSection() ?>

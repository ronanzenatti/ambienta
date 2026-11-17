<?php

/**
 * Envio de um item a outro local, com protocolo: quem recebe confirma na tela da movimentação.
 *
 * @var array<string, mixed>      $item
 * @var string                    $origem
 * @var list<string>              $exceto        onde o item já está ("tipo:id"), fora da lista de destinos
 * @var array<string, mixed>      $dados
 * @var bool                      $disponivel    item não está em trânsito
 * @var array<string, string>     $erros
 */
$id      = (int) $item['id_item'];
$numero  = item_numero($item);
$parcial = ! (bool) $item['patrimoniavel'] && (int) $item['quantidade'] > 1;
$titulo = lang('Patrimonio.moveTitle');
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
    <p class="mb-space-6 text-body text-ink-muted"><?= esc(item_rotulo($item)) ?></p>

    <?php if (\App\Models\Patrimonio\ItemModel::pendente($item)) : ?>
        <?= ui_alert('pending', lang('Patrimonio.moveItemPending')) ?>
    <?php elseif (! $disponivel) : ?>
        <?= ui_alert('pending', lang('Patrimonio.moveItemUnavailable')) ?>
    <?php else : ?>
        <form method="post" action="<?= site_url("patrimonio/itens/{$id}/movimentar") ?>" class="max-w-2xl rounded-md border border-border bg-surface shadow-xs p-space-6">
            <?= csrf_field() ?>
            <p class="mb-space-4 text-body"><span class="text-body-strong"><?= esc(lang('Patrimonio.fieldOrigin')) ?>:</span> <?= esc($origem) ?></p>
            <?php if ($parcial) : ?>
                <?= ui_field('quantidade', lang('Patrimonio.fieldQuantity'), 'number', (string) ($dados['quantidade'] ?? ''), $erros['quantidade'] ?? null, lang('Patrimonio.moveQuantityHelp', [(int) $item['quantidade']]), ['min' => '1', 'max' => (string) (int) $item['quantidade'], 'step' => '1', 'inputmode' => 'numeric']) ?>
            <?php endif ?>
            <?= ui_select_fonte('destino', lang('Patrimonio.fieldDestination'), 'locais-destino', ['exceto' => $exceto], $dados['destino'] ?? null, lang('Ui.select'), $erros['destino'] ?? null, null, null, ['required' => true]) ?>
            <?= ui_select_fonte('destinatario_id', lang('Patrimonio.fieldRecipient'), 'destinatarios', [], $dados['destinatario_id'] ?? null, lang('Ui.select'), $erros['destinatario_id'] ?? null, lang('Patrimonio.recipientHelp'), null, ['required' => true]) ?>
            <div class="flex flex-wrap gap-space-3">
                <?= ui_button(lang('Patrimonio.send'), 'primary', ['type' => 'submit']) ?>
                <?= ui_button(lang('Ui.cancel'), 'secondary', ['href' => site_url("patrimonio/itens/{$id}")]) ?>
            </div>
        </form>
    <?php endif ?>
<?= $this->endSection() ?>

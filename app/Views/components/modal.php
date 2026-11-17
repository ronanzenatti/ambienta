<?php
/**
 * Modal. Sem fechar no backdrop: só Cancelar/Fechar ou Esc.
 * Focus trap via @alpinejs/focus (x-trap); o foco volta ao gatilho ao fechar.
 *
 * @var string      $id
 * @var string      $title
 * @var string      $body         HTML confiável
 * @var string      $footer       HTML confiável (botões de ação extras)
 * @var string      $closeLabel
 * @var string|null $confirmWord  Palavra que habilita a ação destrutiva (padrão CONFIRMO)
 * @var bool        $amplo        Painel largo, com rolagem própria
 */
$confirmArg = $confirmWord === null ? 'null' : "'" . esc($confirmWord, 'js') . "'";
?>
<div x-data="ambientaModal('<?= $id ?>', <?= $confirmArg ?>)"
     @open-modal.window="receber($event.detail)"
     @close-modal.window="if ($event.detail === '<?= $id ?>') close()"
     @keydown.escape.window="if (open) close()">
    <div x-show="open" x-cloak class="fixed inset-0 z-[70] flex items-center justify-center bg-ink/50 p-space-4">
        <?php /* Nenhum @click no backdrop, de propósito. */ ?>
        <div role="dialog" aria-modal="true" aria-labelledby="<?= $id ?>-title"
             x-trap.noscroll.inert="open"
             class="w-full <?= $amplo ? 'max-h-[90vh] max-w-6xl overflow-y-auto' : 'max-w-lg' ?> rounded-md border border-border bg-surface shadow-xs p-space-6 shadow-lg sm:p-space-8">
            <h2 id="<?= $id ?>-title" class="mb-space-4 text-title"><?= esc($title) ?></h2>
            <div class="mb-space-6 text-body"><?= $body ?></div>
            <div class="flex flex-wrap justify-end gap-space-2">
                <?= ui_button($closeLabel, 'secondary', ['@click' => 'close()']) ?>
                <?= $footer ?>
            </div>
        </div>
    </div>
</div>

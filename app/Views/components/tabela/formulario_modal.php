<?php

/**
 * Modal dos formulários curtos de uma listagem (cadastrar e alterar). Abre pelo evento
 * 'abrir-formulario' (ambientaAbrirFormulario no link "Novo …"/"Alterar", que sem JS abre a página do
 * formulário), carrega "<url>?modal=1" e envia por fetch. Salvou: fecha e a tabela recarrega; erro de
 * validação (422): o formulário volta com os erros e o modal continua aberto.
 *
 * Como todo modal do sistema, não fecha no backdrop: só por Cancelar/Fechar ou Esc.
 */

?>
<div x-data="ambientaFormulario()" @abrir-formulario.window="abrir($event.detail)"
     @keydown.escape.window="if (open) fechar()">
    <div x-show="open" x-cloak class="fixed inset-0 z-[70] flex items-center justify-center bg-ink/50 p-space-4">
        <?php /* Nenhum @click no backdrop, de propósito. */ ?>
        <div role="dialog" aria-modal="true" aria-labelledby="formulario-modal-titulo" :aria-busy="carregando ? 'true' : 'false'"
             x-trap.noscroll.inert="open"
             class="max-h-[calc(100vh-2rem)] w-full max-w-2xl overflow-y-auto rounded-md border border-border bg-surface p-space-6 shadow-lg sm:p-space-8">
            <div class="mb-space-4 flex items-start justify-between gap-space-4">
                <h2 id="formulario-modal-titulo" class="text-title" x-text="titulo || '<?= esc(lang('Tabela.loading'), 'js') ?>'"></h2>
                <button type="button" @click="fechar()" aria-label="<?= esc(lang('Ui.close'), 'attr') ?>"
                        class="-mr-space-2 -mt-space-2 inline-flex size-11 shrink-0 items-center justify-center rounded-sm text-ink-muted hover:bg-paper hover:text-ink">
                    <?= ui_icon('fechar') ?>
                </button>
            </div>
            <div x-show="erro" x-cloak><?= ui_alert('critical', lang('Tabela.formError')) ?></div>
            <div x-ref="corpo" @submit.prevent="enviar($event.target)"></div>
        </div>
    </div>
</div>

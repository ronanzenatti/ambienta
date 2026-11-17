<?php

/**
 * Corpo do modal de resultado da troca de senha. Roda dentro do ambientaModal: `dados` traz o `texto`
 * (acesso pronto para copiar) e `email` ('enviado' | 'falhou' | null), vindos da resposta do servidor.
 */

?>
<div class="space-y-space-4">
    <template x-if="dados.email === 'enviado'">
        <?= ui_alert('confirmed', lang('Usuarios.emailSent')) ?>
    </template>
    <template x-if="dados.email === 'falhou'">
        <?= ui_alert('critical', lang('Usuarios.emailFailed')) ?>
    </template>

    <p class="text-body"><?= esc(lang('Usuarios.mustChangeNotice')) ?></p>

    <div>
        <label for="senha-resultado-texto" class="mb-space-1 block text-body-strong"><?= esc(lang('Usuarios.accessTextLabel')) ?></label>
        <textarea id="senha-resultado-texto" readonly rows="5" x-text="dados.texto ?? ''" @focus="$el.select()"
                  class="w-full rounded-sm border border-border-strong bg-surface px-space-3 py-space-2 text-body"></textarea>
    </div>
    <p x-show="copiado" x-cloak role="status" class="text-data"><?= esc(lang('Usuarios.copied')) ?></p>
</div>

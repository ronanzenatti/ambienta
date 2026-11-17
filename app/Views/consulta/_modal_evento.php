<?php

/**
 * Corpo do modal de detalhes de um agendamento. Roda dentro do escopo do modal (ambientaModal), cujo `dados.chave`
 * aponta o agendamento no store 'consulta'; os valores já vêm montados, no idioma, por GradeConsulta (`d`), e a
 * ordem e os rótulos vêm do store.
 */

?>
<div x-data="{ get e() { return $store.consulta.eventos[dados.chave] ?? null; } }">
    <template x-if="e">
        <div>
            <p class="mb-space-4 text-heading" x-text="e.l2"></p>
            <dl class="grid gap-x-space-6 gap-y-space-3 sm:grid-cols-[auto_1fr]">
                <template x-for="chave in $store.consulta.ordem.filter((c) => e.d[c] !== undefined)" :key="chave">
                    <div class="contents">
                        <dt class="text-body-strong" x-text="$store.consulta.rotulos[chave]"></dt>
                        <dd class="text-body" x-text="e.d[chave]"></dd>
                    </div>
                </template>
            </dl>
        </div>
    </template>
</div>

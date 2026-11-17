<?php

/**
 * Agenda da semana de um ambiente: uma coluna por dia, régua vertical de 07h a 23h e cada agendamento
 * posicionado e dimensionado pelo horário (64px por hora). Montada no navegador, a partir do store 'consulta',
 * dentro de um x-data ambientaSala (modal da linha do tempo e linha expandida do resumo por sala).
 * Linha contínua a cada hora e tracejada a cada 30 minutos. Agendamentos sobrepostos dividem a largura da coluna.
 */

?>
<template x-if="sala">
    <div>
        <p class="text-heading"><span x-text="sala.numero"></span> — <span x-text="sala.nome"></span></p>
        <p class="mb-space-4 text-data text-ink-muted" x-text="sala.predio"></p>

        <div class="overflow-x-auto">
            <div class="flex min-w-[44rem] text-caption">
                <div class="w-12 shrink-0">
                    <div class="h-10"></div>
                    <div class="relative h-[1024px]">
                        <template x-for="h in horas" :key="h">
                            <span class="absolute right-space-1 -translate-y-1/2 tabular-nums text-ink-muted" :style="`top:${(h - 7) / 16 * 100}%`" x-text="String(h).padStart(2, '0') + 'h'"></span>
                        </template>
                    </div>
                </div>
                <template x-for="d in dias" :key="d.chave">
                    <div class="min-w-0 flex-1 border-l border-border">
                        <div class="flex h-10 flex-col items-center justify-center border-b border-border">
                            <span class="text-body-strong" x-text="d.rotulo"></span>
                            <span class="tabular-nums text-ink-muted" x-text="d.data"></span>
                        </div>
                        <div class="relative h-[1024px]">
                            <template x-for="h in horas" :key="h">
                                <div class="absolute inset-x-0 border-t border-border" :style="`top:${(h - 7) / 16 * 100}%`"></div>
                            </template>
                            <template x-for="h in horas" :key="'m' + h">
                                <div class="absolute inset-x-0 border-t border-dashed border-border" aria-hidden="true" :style="`top:${(h + 0.5 - 7) / 16 * 100}%`"></div>
                            </template>
                            <template x-for="e in d.eventos" :key="e.k">
                                <button type="button" aria-haspopup="dialog" :data-consulta-evento="e.k"
                                        class="consulta-bloco consulta-bloco-sala absolute"
                                        :class="e.tipo === 'aula' ? 'consulta-bloco-aula' : 'consulta-bloco-pontual'"
                                        :style="estilo(e)">
                                    <span x-text="e.l1"></span>
                                    <span><span x-text="e.pre"></span><strong class="font-bold" x-text="e.l2.slice(e.pre.length)"></strong></span>
                                    <span x-text="e.l3"></span>
                                    <span class="sr-only" x-text="e.tipo === 'aula' ? '' : '(' + tipos.pontual + ')'"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

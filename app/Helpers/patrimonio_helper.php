<?php

/**
 * Apresentação de itens: o número de patrimônio é o que torna um item um patrimônio. Patrimoniável sem número
 * está pendente (não pode ser movido, alocado nem contado como disponível); item sem patrimônio (marcador,
 * apagador) não tem número e é contado por quantidade.
 */

if (! function_exists('item_numero')) {
    /**
     * Texto da identificação de um item: o número, "Aguardando número" ou "Sem patrimônio".
     *
     * @param array<string, mixed> $item precisa de numero_patrimonio e patrimoniavel
     */
    function item_numero(array $item): string
    {
        return match (true) {
            $item['numero_patrimonio'] !== null => (string) $item['numero_patrimonio'],
            (bool) $item['patrimoniavel']       => lang('Patrimonio.numberPending'),
            default                             => lang('Patrimonio.noAsset'),
        };
    }
}

if (! function_exists('item_rotulo')) {
    /**
     * "Número · Nome" (ou "Nome" quando não há número); item sem patrimônio com mais de uma unidade leva "(×N)".
     *
     * @param array<string, mixed> $item numero_patrimonio, patrimoniavel, nome e (opcional) quantidade
     */
    function item_rotulo(array $item): string
    {
        $quantidade = (int) ($item['quantidade'] ?? 1);
        $sufixo     = $quantidade > 1 ? ' (×' . $quantidade . ')' : '';

        return $item['numero_patrimonio'] === null
            ? $item['nome'] . $sufixo
            : $item['numero_patrimonio'] . ' · ' . $item['nome'];
    }
}

if (! function_exists('item_aviso_pendente')) {
    /** Aviso padrão de patrimoniável sem número (ponto amarelo + texto, nunca só a cor). */
    function item_aviso_pendente(): string
    {
        return ui_alert('pending', lang('Patrimonio.pendingNumberWarning'));
    }
}

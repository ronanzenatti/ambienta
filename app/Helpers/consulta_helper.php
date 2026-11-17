<?php

/**
 * Peças da consulta pública (app/Views/consulta): o bloco de um agendamento e o rótulo de hora da régua.
 */

if (! function_exists('consulta_bloco')) {
    /**
     * Bloco de um agendamento: período, "sigla do curso - disciplina" (ou o título da reserva) e primeiro nome
     * de quem ministra/reservou. Botão que abre o modal de detalhes (o JS escuta o clique em data-consulta-evento
     * e busca o agendamento no store 'consulta'). Aula do semestre: faixa de cor à esquerda; reserva pontual:
     * borda tracejada (classes em resources/css/app.css; a diferença nunca depende só da cor: a reserva pontual
     * também leva o texto "Reserva pontual" para leitor de tela).
     *
     * @param array{k: string, tipo: string, l1: string, l2: string, l3: string} $evento
     * @param string $estilo style inline (variáveis --i, --d e --f da posição, na linha do tempo)
     */
    function consulta_bloco(array $evento, string $classe = '', string $estilo = ''): string
    {
        $aula    = $evento['tipo'] === 'aula';
        $classes = 'consulta-bloco ' . ($aula ? 'consulta-bloco-aula' : 'consulta-bloco-pontual') . ' ' . $classe;

        // esc() de HTML (não 'attr'): os valores vão entre aspas duplas e o 'attr' trocaria cada espaço por entidade.
        return '<button type="button" aria-haspopup="dialog" data-consulta-evento="' . esc($evento['k']) . '"'
            . ' class="' . esc(trim($classes)) . '"'
            . ($estilo === '' ? '' : ' style="' . esc($estilo) . '"') . '>'
            . '<span>' . esc($evento['l1']) . '</span>'
            . '<span>' . esc($evento['l2']) . '</span>'
            . '<span>' . esc($evento['l3']) . '</span>'
            . ($aula ? '' : '<span class="sr-only">(' . esc(lang('Consulta.typeOneOffShort')) . ')</span>')
            . '</button>';
    }
}

if (! function_exists('consulta_hora')) {
    /** Minutos desde 00:00 como rótulo curto: 780 → "13h", 810 → "13h30". */
    function consulta_hora(int $minutos): string
    {
        return intdiv($minutos, 60) . 'h' . ($minutos % 60 === 0 ? '' : sprintf('%02d', $minutos % 60));
    }
}

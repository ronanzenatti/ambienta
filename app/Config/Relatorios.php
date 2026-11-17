<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Relatórios (REQUISITOS §12).
 */
class Relatorios extends BaseConfig
{
    /**
     * Janela de funcionamento de cada dia da semana, base das "horas disponíveis" da taxa de ocupação
     * (dia fora da lista = fechado). Feriados do campus e bloqueios do ambiente são descontados.
     *
     * @var array<string, array{0: string, 1: string}> dia (como em alocacoes_semestrais.dia_semana) => [início, fim]
     */
    public array $janela = [
        'segunda' => ['07:00', '23:00'],
        'terca'   => ['07:00', '23:00'],
        'quarta'  => ['07:00', '23:00'],
        'quinta'  => ['07:00', '23:00'],
        'sexta'   => ['07:00', '23:00'],
        'sabado'  => ['07:00', '13:00'],
    ];

    /** Maior período aceito num relatório (dias): limita o custo do cálculo de ocupação. */
    public int $periodoMaximoDias = 731;
}

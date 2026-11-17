<?php

namespace App\Commands;

use App\Libraries\Agendamento\Feriados;
use App\Libraries\Agendamento\Recusa;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Sincroniza os feriados nacionais com a API pública (BrasilAPI). Sem argumento: o ano atual e o seguinte.
 * Agendar no cron da hospedagem, ex. uma vez por semana:
 *   0 6 * * 1 cd /caminho/do/ambienta && php spark feriados:sincronizar
 */
class SincronizarFeriados extends BaseCommand
{
    protected $group       = 'Ambienta';
    protected $name        = 'feriados:sincronizar';
    protected $description = 'Busca os feriados nacionais na API pública e grava os que faltam.';
    protected $usage       = 'feriados:sincronizar [ano]';

    /** @param array<int|string, string|null> $params */
    public function run(array $params): int
    {
        $ano  = isset($params[0]) && ctype_digit((string) $params[0])
            ? [(int) $params[0]]
            : [(int) date('Y'), (int) date('Y') + 1];
        $erro = false;

        foreach ($ano as $a) {
            try {
                $resultado = (new Feriados(db_connect()))->sincronizar($a, service('curlrequest'));
                CLI::write(sprintf(
                    '%d: %d feriados recebidos, %d novos.',
                    $a,
                    $resultado['recebidos'],
                    $resultado['novos']
                ));
            } catch (Recusa $recusa) {
                CLI::error(sprintf('%d: %s', $a, $recusa->texto()));
                $erro = true;
            }
        }

        return $erro ? EXIT_ERROR : EXIT_SUCCESS;
    }
}

<?php

namespace App\Commands;

use App\Libraries\Patrimonio\LembreteAtrasos;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Lembrete diário de patrimônio em atraso. Agendar uma vez por dia no cron da hospedagem, ex.:
 *   0 7 * * * cd /caminho/do/ambienta && php spark patrimonio:lembretes
 */
class LembretesPatrimonio extends BaseCommand
{
    protected $group       = 'Ambienta';
    protected $name        = 'patrimonio:lembretes';
    protected $description = 'Envia o e-mail diário das movimentações de patrimônio com prazo de resposta vencido.';

    /** @param array<int|string, string|null> $params */
    public function run(array $params): int
    {
        $resultado = (new LembreteAtrasos(db_connect()))->enviar(service('email'));

        CLI::write(sprintf(
            'Movimentações em atraso: %d. Devoluções de extras em atraso: %d. E-mails enviados: %d. Falhas: %d.',
            $resultado['movimentacoes'],
            $resultado['extras'],
            $resultado['emails'],
            $resultado['falhas']
        ));

        return $resultado['falhas'] === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }
}

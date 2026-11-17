<?php

namespace App\Commands;

use App\Libraries\Notificacoes\EnvioEmails;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Fila de e-mail das notificações. Agendar no cron da hospedagem a cada 5 minutos, ex.:
 *   *\/5 * * * * cd /caminho/do/ambienta && php spark notificacoes:enviar
 */
class EnviarNotificacoes extends BaseCommand
{
    protected $group       = 'Ambienta';
    protected $name        = 'notificacoes:enviar';
    protected $description = 'Envia por e-mail as notificações ainda não enviadas (um e-mail por evento).';

    /** @param array<int|string, string|null> $params */
    public function run(array $params): int
    {
        $resultado = (new EnvioEmails(db_connect()))->enviar(service('email'));

        CLI::write(sprintf('E-mails enviados: %d. Falhas: %d.', $resultado['enviadas'], $resultado['falhas']));

        return $resultado['falhas'] === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }
}

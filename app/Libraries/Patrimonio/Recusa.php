<?php

namespace App\Libraries\Patrimonio;

use RuntimeException;

/**
 * Operação de patrimônio recusada por regra de negócio (item em trânsito, descarte pendente...).
 * A mensagem é a chave de idioma do motivo, para o controller mostrar no idioma do usuário.
 */
final class Recusa extends RuntimeException
{
}

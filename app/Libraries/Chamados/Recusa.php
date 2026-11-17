<?php

namespace App\Libraries\Chamados;

use RuntimeException;

/**
 * Operação de requisitos/chamados recusada por regra de negócio (transição de status inválida...).
 * A mensagem é a chave de idioma do motivo, para o controller mostrar no idioma do usuário.
 */
final class Recusa extends RuntimeException
{
}

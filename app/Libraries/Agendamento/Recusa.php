<?php

namespace App\Libraries\Agendamento;

use RuntimeException;

/**
 * Operação de agendamento recusada por regra de negócio (antecedência, feriado, conflito...). A mensagem é a
 * chave de idioma do motivo; $argumentos completam o texto, para o controller mostrar no idioma do usuário.
 */
final class Recusa extends RuntimeException
{
    /** @param list<string|int> $argumentos */
    public function __construct(string $chave, public readonly array $argumentos = [])
    {
        parent::__construct($chave);
    }

    public function texto(): string
    {
        $texto = lang($this->getMessage(), $this->argumentos);

        return is_string($texto) ? $texto : $this->getMessage();
    }
}

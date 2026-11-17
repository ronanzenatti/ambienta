<?php

namespace App\Libraries;

/**
 * CPF: normalização (só dígitos, como fica no banco) e validação dos dígitos verificadores.
 */
final class Cpf
{
    public static function normalizar(string $valor): string
    {
        return preg_replace('/\D+/', '', $valor) ?? '';
    }

    public static function valido(string $valor): bool
    {
        $cpf = self::normalizar($valor);

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            return false;
        }

        for ($posicao = 9; $posicao < 11; $posicao++) {
            $soma = 0;
            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $cpf[$i] * ($posicao + 1 - $i);
            }

            $digito = ((10 * $soma) % 11) % 10;

            if ((int) $cpf[$posicao] !== $digito) {
                return false;
            }
        }

        return true;
    }
}

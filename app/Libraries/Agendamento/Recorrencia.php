<?php

namespace App\Libraries\Agendamento;

use DateTimeImmutable;

/**
 * Datas das ocorrências de uma série (diária, semanal ou mensal), terminando por data ou por número de
 * ocorrências. Mensal repete o mesmo dia do mês; mês sem esse dia (ex.: 31) fica de fora, sem contar.
 */
final class Recorrencia
{
    public const TIPOS = ['diaria', 'semanal', 'mensal'];

    /** Teto de ocorrências de uma série (uma requisição, uma chamada de procedure por ocorrência). */
    public const MAXIMO = 200;

    /**
     * @return list<DateTimeImmutable> início de cada ocorrência (a primeira é $inicio)
     */
    public static function datas(
        DateTimeImmutable $inicio,
        string $tipo,
        ?DateTimeImmutable $dataFim,
        ?int $numero
    ): array {
        if (! in_array($tipo, self::TIPOS, true)) {
            throw new Recusa('Agendamento.recurrenceTypeInvalid');
        }

        if ($dataFim === null && ($numero === null || $numero < 1)) {
            throw new Recusa('Agendamento.recurrenceEndRequired');
        }

        $limite = min($numero ?? self::MAXIMO, self::MAXIMO);
        $ultimo = $dataFim?->setTime(23, 59, 59);
        $datas  = [];
        $dia    = (int) $inicio->format('j');

        for ($passo = 0; count($datas) < $limite; $passo++) {
            $data = self::passo($inicio, $tipo, $passo, $dia);

            if ($ultimo !== null && $data !== null && $data > $ultimo) {
                break;
            }

            if ($data !== null) {
                $datas[] = $data;
            }

            if ($passo > self::MAXIMO * 31) {
                break;
            }
        }

        return $datas;
    }

    private static function passo(DateTimeImmutable $inicio, string $tipo, int $passo, int $dia): ?DateTimeImmutable
    {
        if ($tipo === 'diaria') {
            return $inicio->modify("+{$passo} day");
        }

        if ($tipo === 'semanal') {
            return $inicio->modify('+' . ($passo * 7) . ' day');
        }

        $mes = (int) $inicio->format('n') - 1 + $passo;
        $ano = (int) $inicio->format('Y') + intdiv($mes, 12);
        $mes = $mes % 12 + 1;

        return checkdate($mes, $dia, $ano) ? $inicio->setDate($ano, $mes, $dia) : null;
    }
}

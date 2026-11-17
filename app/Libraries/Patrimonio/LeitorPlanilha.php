<?php

namespace App\Libraries\Patrimonio;

use Config\Patrimonio;
use DateTimeInterface;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Leitura das planilhas de números de patrimônio (Excel): primeira aba, a 1ª linha é o cabeçalho, no máximo
 * Config\Patrimonio::$limiteImportacao linhas de dados.
 */
final class LeitorPlanilha
{
    /**
     * Linhas de dados: número da linha na planilha => valores (texto) das colunas pedidas, na ordem pedida.
     *
     * @param list<int> $colunas índices (a partir de 0) das colunas lidas
     *
     * @return array<int, list<string>>
     */
    public static function ler(string $arquivo, array $colunas): array
    {
        $limite = config(Patrimonio::class)->limiteImportacao;
        $reader = new Reader();
        $reader->open($arquivo);
        $linhas = [];

        try {
            foreach ($reader->getSheetIterator() as $aba) {
                foreach ($aba->getRowIterator() as $indice => $linha) {
                    if ($indice === 1) {
                        continue;
                    }

                    if (count($linhas) >= $limite) {
                        break;
                    }

                    $valores = $linha->toArray();
                    $linhas[(int) $indice] = array_map(
                        static fn (int $coluna): string => self::texto($valores[$coluna] ?? null),
                        $colunas
                    );
                }

                break;
            }
        } finally {
            $reader->close();
        }

        return $linhas;
    }

    public static function texto(mixed $valor): string
    {
        return match (true) {
            $valor === null                              => '',
            is_float($valor) && floor($valor) === $valor => (string) (int) $valor,
            $valor instanceof DateTimeInterface          => $valor->format('Y-m-d'),
            is_scalar($valor)                            => trim((string) $valor),
            default                                      => '',
        };
    }
}

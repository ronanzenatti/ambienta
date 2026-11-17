<?php

namespace App\Libraries\Patrimonio;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Throwable;

/**
 * Importa a planilha de números de patrimônio preenchida (a mesma exportada pela tela "Números de
 * patrimônio": 1ª coluna = id do item, 4ª = número) e atualiza os itens já lançados. Cada linha é independente:
 * número já usado (inclusive por item descartado: nunca é reaproveitado), item inexistente, fora do escopo ou que
 * já tem número é rejeitado sozinho, sem interromper o restante; o resultado lista as rejeitadas.
 */
final class ImportadorNumeros
{
    public const COLUNA_ID     = 0;
    public const COLUNA_NUMERO = 3;

    public const TAMANHO_MAXIMO = 30;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param callable(array<string, mixed>): bool $podeEditar item dentro do escopo de edição do usuário?
     *
     * @return array{gravados: int, rejeitados: list<array{linha: int, numero: string, motivo: string}>}|null
     *         null quando o arquivo não é uma planilha legível
     */
    public function importar(string $arquivo, callable $podeEditar): ?array
    {
        try {
            $linhas = LeitorPlanilha::ler($arquivo, [self::COLUNA_ID, self::COLUNA_NUMERO]);
        } catch (Throwable) {
            return null;
        }

        $gravados   = 0;
        $rejeitados = [];
        $vistos     = [];

        foreach ($linhas as $numeroLinha => [$id, $numero]) {
            if ($numero === '') {
                continue;
            }

            $motivo = $this->motivoRecusa($id, $numero, $vistos, $podeEditar);
            $vistos[mb_strtolower($numero)] = true;

            if ($motivo === null) {
                $motivo = $this->gravar((int) $id, $numero);
            }

            if ($motivo === null) {
                $gravados++;
            } else {
                $rejeitados[] = ['linha' => $numeroLinha, 'numero' => $numero, 'motivo' => $motivo];
            }
        }

        return ['gravados' => $gravados, 'rejeitados' => $rejeitados];
    }

    /**
     * @param array<string, true>                  $vistos números já lidos nesta planilha (minúsculas)
     * @param callable(array<string, mixed>): bool $podeEditar
     */
    private function motivoRecusa(string $id, string $numero, array $vistos, callable $podeEditar): ?string
    {
        if (mb_strlen($numero) > self::TAMANHO_MAXIMO) {
            return 'Patrimonio.importTooLong';
        }

        if (isset($vistos[mb_strtolower($numero)])) {
            return 'Patrimonio.importDuplicateInFile';
        }

        $item = ctype_digit($id) ? $this->db->table('itens')
            ->where('id_item', (int) $id)
            ->where('deletado_em', null)
            ->get()
            ->getRowArray() : null;

        if ($item === null || ! $podeEditar($item)) {
            return 'Patrimonio.importItemInvalid';
        }

        if ($item['numero_patrimonio'] !== null) {
            return 'Patrimonio.importItemHasNumber';
        }

        if (! (bool) $item['patrimoniavel']) {
            return 'Patrimonio.importItemNotAsset';
        }

        return $this->numeroEmUso($numero) ? 'Patrimonio.numberInUse' : null;
    }

    /** Com withDeleted de propósito: número de item descartado também não volta a ser usado. */
    public function numeroEmUso(string $numero): bool
    {
        return $this->db->table('itens')->where('numero_patrimonio', $numero)->countAllResults() > 0;
    }

    /** Grava o número; a UNIQUE do banco decide se outra pessoa gravou o mesmo número no meio do caminho. */
    private function gravar(int $id, string $numero): ?string
    {
        try {
            $this->db->query(
                'UPDATE itens SET numero_patrimonio = ?
                 WHERE id_item = ? AND numero_patrimonio IS NULL AND patrimoniavel = 1 AND deletado_em IS NULL',
                [$numero, $id]
            );
        } catch (DatabaseException) {
            return 'Patrimonio.numberInUse';
        }

        return $this->db->affectedRows() === 1 ? null : 'Patrimonio.importItemHasNumber';
    }
}

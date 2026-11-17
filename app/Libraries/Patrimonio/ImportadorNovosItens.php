<?php

namespace App\Libraries\Patrimonio;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Throwable;

/**
 * Importa a planilha de itens novos de um modelo (a em branco exportada pela tela "Números de patrimônio":
 * 1ª coluna = id do modelo, 3ª = local "tipo:id", 5ª = número). Cada linha com número cria um item patrimonial
 * já numerado, com os dados copiados do modelo, no local da linha. Cada linha é independente: modelo inexistente
 * ou sem controle por patrimônio, local inexistente ou fora do escopo de edição, número em uso (inclusive de item
 * descartado) ou repetido na planilha são rejeitados sozinhos, sem interromper o restante.
 */
final class ImportadorNovosItens
{
    public const COLUNA_MODELO = 0;
    public const COLUNA_LOCAL  = 2;
    public const COLUNA_NUMERO = 4;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param callable(array<string, mixed>): bool $podeEditar usuário é editor no local (ambiente_id/almoxarifado_id)?
     *
     * @return array{gravados: int, rejeitados: list<array{linha: int, numero: string, motivo: string}>}|null
     *         null quando o arquivo não é uma planilha legível
     */
    public function importar(string $arquivo, callable $podeEditar): ?array
    {
        try {
            $linhas = LeitorPlanilha::ler($arquivo, [self::COLUNA_MODELO, self::COLUNA_LOCAL, self::COLUNA_NUMERO]);
        } catch (Throwable) {
            return null;
        }

        $locais     = new Locais($this->db);
        $numeros    = new ImportadorNumeros($this->db);
        $gestor     = new GestorItens($this->db);
        $gravados   = 0;
        $rejeitados = [];
        $vistos     = [];

        foreach ($linhas as $numeroLinha => [$modeloId, $localValor, $numero]) {
            if ($numero === '') {
                continue;
            }

            $modelo = ctype_digit($modeloId) ? $this->db->table('modelos_itens')
                ->where('id_modelo_item', (int) $modeloId)
                ->where('deletado_em', null)
                ->get()
                ->getRowArray() : null;
            $local  = $locais->interpretar($localValor);

            $motivo = match (true) {
                mb_strlen($numero) > ImportadorNumeros::TAMANHO_MAXIMO => 'Patrimonio.importTooLong',
                isset($vistos[mb_strtolower($numero)])                 => 'Patrimonio.importDuplicateInFile',
                $modelo === null                                       => 'Patrimonio.importModelInvalid',
                ! (bool) $modelo['patrimoniavel']                      => 'Patrimonio.importModelNotAsset',
                $local === null || ! $podeEditar($local)               => 'Patrimonio.importLocationInvalid',
                $numeros->numeroEmUso($numero)                         => 'Patrimonio.numberInUse',
                default                                                => null,
            };
            $vistos[mb_strtolower($numero)] = true;

            if ($motivo === null) {
                $motivo = $this->gravar($gestor, $modelo, $local, $numero);
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
     * Cria o item numerado; a UNIQUE do banco decide se outra pessoa gravou o mesmo número no meio do caminho.
     *
     * @param array<string, mixed>                                                                $modelo
     * @param array{tipo: string, ambiente_id: ?int, almoxarifado_id: ?int}                       $local
     */
    private function gravar(GestorItens $gestor, array $modelo, array $local, string $numero): ?string
    {
        $this->db->transStart();

        try {
            $gestor->criar(GestorItens::moldeDoModelo($modelo), $local, 1, $numero);
            $this->db->transComplete();
        } catch (DatabaseException) {
            $this->db->transRollback();

            return 'Patrimonio.numberInUse';
        }

        return $this->db->transStatus() ? null : 'Patrimonio.numberInUse';
    }
}

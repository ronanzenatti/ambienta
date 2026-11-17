<?php

namespace App\Libraries\Patrimonio;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Onde um bem está: num ambiente (prédio → campus) ou no almoxarifado de um campus. O escopo de
 * permissão de patrimônio segue o local: almoxarifado conta pelo campus; ambiente, pelo prédio e campus.
 *
 * Nos formulários, o local é um valor só ("almoxarifado:3", "ambiente:12"), para uma única lista de escolha.
 *
 * @phpstan-type Local array{
 *     tipo: string, id: int, campus_id: int, predio_id: ?int, ambiente_id: ?int, almoxarifado_id: ?int
 * }
 */
final class Locais
{
    public const ALMOXARIFADO = 'almoxarifado';
    public const AMBIENTE     = 'ambiente';

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * Junta as tabelas do local a uma consulta, com aliases "<p>_alx", "<p>_amb", "<p>_pr" e "<p>_ca".
     */
    public static function juntar(
        BaseBuilder $builder,
        string $p,
        string $colunaAmbiente,
        string $colunaAlmoxarifado
    ): BaseBuilder {
        return $builder
            ->join("almoxarifados AS {$p}_alx", "{$p}_alx.id_almoxarifado = {$colunaAlmoxarifado}", 'left', false)
            ->join("ambientes AS {$p}_amb", "{$p}_amb.id_ambiente = {$colunaAmbiente}", 'left', false)
            ->join("predios AS {$p}_pr", "{$p}_pr.id_predio = {$p}_amb.predio_id", 'left', false)
            ->join(
                "campus AS {$p}_ca",
                "{$p}_ca.id_campus = COALESCE({$p}_alx.campus_id, {$p}_pr.campus_id)",
                'left',
                false
            );
    }

    /** Texto do local (tela, busca e exportação), a partir das tabelas de juntar(). */
    public static function rotuloSql(string $p): string
    {
        return "CASE WHEN {$p}_alx.id_almoxarifado IS NOT NULL"
            . " THEN CONCAT({$p}_alx.nome, ' · ', {$p}_ca.abreviacao)"
            . " ELSE CONCAT({$p}_amb.numero, ' ', {$p}_amb.nome, ' · ', {$p}_pr.abreviacao, ' · ', {$p}_ca.abreviacao)"
            . ' END';
    }

    /**
     * Condição SQL do escopo sobre as tabelas de juntar(); null = sem restrição (escopo global).
     *
     * @param array{global: bool, campus: list<int>, predio: list<int>} $escopo
     */
    public static function condicaoEscopo(array $escopo, string $p): ?string
    {
        if ($escopo['global']) {
            return null;
        }

        $partes = [];

        if ($escopo['campus'] !== []) {
            $partes[] = "{$p}_ca.id_campus IN (" . implode(', ', array_map('intval', $escopo['campus'])) . ')';
        }

        if ($escopo['predio'] !== []) {
            $partes[] = "{$p}_amb.predio_id IN (" . implode(', ', array_map('intval', $escopo['predio'])) . ')';
        }

        return $partes === [] ? '1 = 0' : '(' . implode(' OR ', $partes) . ')';
    }

    /**
     * Local escolhido num formulário ("tipo:id"), se existir e estiver ativo.
     *
     * @return Local|null
     */
    public function interpretar(string $valor): ?array
    {
        if (preg_match('/^(almoxarifado|ambiente):(\d+)$/', $valor, $partes) !== 1) {
            return null;
        }

        return $partes[1] === self::ALMOXARIFADO
            ? $this->local(null, (int) $partes[2])
            : $this->local((int) $partes[2], null);
    }

    /**
     * Local de um registro com as colunas ambiente_id/almoxarifado_id (item, estoque).
     *
     * @return Local|null
     */
    public function local(?int $ambienteId, ?int $almoxarifadoId): ?array
    {
        if ($almoxarifadoId !== null) {
            $linha = $this->db->table('almoxarifados')
                ->select('campus_id')
                ->where('id_almoxarifado', $almoxarifadoId)
                ->where('deletado_em', null)
                ->get()
                ->getRowArray();

            return $linha === null ? null : [
                'tipo'            => self::ALMOXARIFADO,
                'id'              => $almoxarifadoId,
                'campus_id'       => (int) $linha['campus_id'],
                'predio_id'       => null,
                'ambiente_id'     => null,
                'almoxarifado_id' => $almoxarifadoId,
            ];
        }

        if ($ambienteId === null) {
            return null;
        }

        $linha = $this->db->table('ambientes AS am')
            ->select('am.predio_id, p.campus_id')
            ->join('predios AS p', 'p.id_predio = am.predio_id')
            ->where('am.id_ambiente', $ambienteId)
            ->where('am.deletado_em', null)
            ->get()
            ->getRowArray();

        return $linha === null ? null : [
            'tipo'            => self::AMBIENTE,
            'id'              => $ambienteId,
            'campus_id'       => (int) $linha['campus_id'],
            'predio_id'       => (int) $linha['predio_id'],
            'ambiente_id'     => $ambienteId,
            'almoxarifado_id' => null,
        ];
    }

    /** Rótulo de um local, no mesmo formato de opcoes() (soft-deletados incluídos: vale para o histórico). */
    public function rotulo(?int $ambienteId, ?int $almoxarifadoId): string
    {
        if ($almoxarifadoId !== null) {
            $linha = $this->db->query(
                'SELECT a.nome,
                        IF(LENGTH(TRIM(c.abreviacao)) > 0, TRIM(c.abreviacao), c.nome) AS campus FROM almoxarifados a
                 JOIN campus c ON c.id_campus = a.campus_id WHERE a.id_almoxarifado = ?',
                [$almoxarifadoId]
            )->getRowArray();

            return $linha === null ? '' : $linha['nome'] . ' — ' . $linha['campus'];
        }

        $linha = $this->db->query(
            'SELECT am.numero, am.nome, p.nome AS predio,
                    IF(LENGTH(TRIM(c.abreviacao)) > 0, TRIM(c.abreviacao), c.nome) AS campus FROM ambientes am
             JOIN predios p ON p.id_predio = am.predio_id JOIN campus c ON c.id_campus = p.campus_id
             WHERE am.id_ambiente = ?',
            [(int) $ambienteId]
        )->getRowArray();

        return $linha === null
            ? ''
            : $linha['campus'] . ' › ' . $linha['predio'] . ' › ' . $linha['numero'] . ' ' . $linha['nome'];
    }
}

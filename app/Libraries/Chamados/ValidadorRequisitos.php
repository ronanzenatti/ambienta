<?php

namespace App\Libraries\Chamados;

use CodeIgniter\Database\BaseConnection;

/**
 * Confere se um ambiente atende ao requisito de um professor para uma disciplina: capacidade mínima,
 * quantidade de itens por categoria e softwares instalados. Só sinaliza: quem aloca (Fase 6) mostra as
 * pendências e decide; nada aqui bloqueia.
 *
 * Itens contados no ambiente: patrimoniais em uso (não descartados) e o estoque não patrimonial, pela
 * categoria do modelo.
 *
 * @phpstan-type Pendencia array{tipo: string, nome: string, exigido: int, disponivel: int}
 */
final class ValidadorRequisitos
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * Pendências do requisito do professor na disciplina; sem requisito cadastrado, nada a apontar.
     *
     * @return list<Pendencia>
     */
    public function verificar(int $professorId, int $disciplinaId, int $ambienteId): array
    {
        $requisito = $this->db->table('requisitos_disciplina')
            ->select('id_requisito_disciplina')
            ->where('professor_id', $professorId)
            ->where('disciplina_id', $disciplinaId)
            ->where('deletado_em', null)
            ->get()
            ->getRowArray();

        return $requisito === null
            ? []
            : $this->verificarRequisito((int) $requisito['id_requisito_disciplina'], $ambienteId);
    }

    /** @return list<Pendencia> */
    public function verificarRequisito(int $requisitoId, int $ambienteId): array
    {
        $requisito = $this->db->table('requisitos_disciplina')
            ->select('capacidade_minima')
            ->where('id_requisito_disciplina', $requisitoId)
            ->where('deletado_em', null)
            ->get()
            ->getRowArray();

        $ambiente = $this->db->table('ambientes')
            ->select('capacidade')
            ->where('id_ambiente', $ambienteId)
            ->where('deletado_em', null)
            ->get()
            ->getRowArray();

        if ($requisito === null || $ambiente === null) {
            return [];
        }

        $pendencias = [];
        $exigida    = (int) $requisito['capacidade_minima'];
        $capacidade = (int) $ambiente['capacidade'];

        if ($capacidade < $exigida) {
            $pendencias[] = ['tipo' => 'capacidade', 'nome' => '', 'exigido' => $exigida, 'disponivel' => $capacidade];
        }

        return array_merge(
            $pendencias,
            $this->itensFaltando($requisitoId, $ambienteId),
            $this->softwaresFaltando($requisitoId, $ambienteId)
        );
    }

    /**
     * Quantas exigências o requisito tem: a capacidade (se houver), cada categoria de item e cada software
     * aprovado (pendente e reprovado ficam de fora).
     */
    public function totalExigencias(int $requisitoId): int
    {
        $requisito = $this->db->table('requisitos_disciplina')
            ->select('capacidade_minima')
            ->where('id_requisito_disciplina', $requisitoId)
            ->where('deletado_em', null)
            ->get()
            ->getRowArray();
        $itens     = $this->db->table('requisitos_disciplina_itens')
            ->where('requisito_disciplina_id', $requisitoId)
            ->where('deletado_em', null)
            ->countAllResults();
        $softwares = $this->db->table('requisitos_disciplina_softwares rs')
            ->join('softwares s', "s.id_software = rs.software_id AND s.status = 'aprovado'")
            ->where('rs.requisito_disciplina_id', $requisitoId)
            ->where('rs.deletado_em', null)
            ->countAllResults();

        return ((int) ($requisito['capacidade_minima'] ?? 0) > 0 ? 1 : 0) + $itens + $softwares;
    }

    /** @return list<Pendencia> */
    private function itensFaltando(int $requisitoId, int $ambienteId): array
    {
        $linhas = $this->db->query(
            'SELECT ci.nome, ri.quantidade,
                    (SELECT COALESCE(SUM(i.quantidade), 0) FROM itens i
                     WHERE i.ambiente_id = ? AND i.status = \'em_uso\' AND i.deletado_em IS NULL
                       AND (i.patrimoniavel = 0 OR i.numero_patrimonio IS NOT NULL)
                       AND i.categoria_item_id = ri.categoria_item_id) AS disponivel
             FROM requisitos_disciplina_itens ri
             JOIN categorias_itens ci ON ci.id_categoria_item = ri.categoria_item_id
             WHERE ri.requisito_disciplina_id = ? AND ri.deletado_em IS NULL
             ORDER BY ci.nome',
            [$ambienteId, $requisitoId]
        )->getResultArray();

        $pendencias = [];

        foreach ($linhas as $linha) {
            if ((int) $linha['disponivel'] < (int) $linha['quantidade']) {
                $pendencias[] = [
                    'tipo'       => 'item',
                    'nome'       => (string) $linha['nome'],
                    'exigido'    => (int) $linha['quantidade'],
                    'disponivel' => (int) $linha['disponivel'],
                ];
            }
        }

        return $pendencias;
    }

    /** @return list<Pendencia> */
    private function softwaresFaltando(int $requisitoId, int $ambienteId): array
    {
        $linhas = $this->db->query(
            'SELECT s.nome, s.versao FROM requisitos_disciplina_softwares rs
             JOIN softwares s ON s.id_software = rs.software_id AND s.status = \'aprovado\'
             WHERE rs.requisito_disciplina_id = ? AND rs.deletado_em IS NULL
               AND NOT EXISTS (SELECT 1 FROM ambientes_softwares a
                               WHERE a.ambiente_id = ? AND a.software_id = rs.software_id AND a.deletado_em IS NULL)
             ORDER BY s.nome, s.versao',
            [$requisitoId, $ambienteId]
        )->getResultArray();

        return array_map(static fn (array $linha): array => [
            'tipo'       => 'software',
            'nome'       => trim($linha['nome'] . ' ' . ($linha['versao'] ?? '')),
            'exigido'    => 1,
            'disponivel' => 0,
        ], $linhas);
    }
}

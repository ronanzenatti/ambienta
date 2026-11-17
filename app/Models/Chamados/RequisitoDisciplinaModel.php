<?php

namespace App\Models\Chamados;

use App\Models\Institucional\ModeloInstitucional;

/**
 * Requisito de disciplina: por professor e disciplina (não por curso, para não haver ambiguidade quando a
 * mesma disciplina é oferecida em cursos diferentes). Um por par entre os ativos.
 */
class RequisitoDisciplinaModel extends ModeloInstitucional
{
    protected $table         = 'requisitos_disciplina';
    protected $primaryKey    = 'id_requisito_disciplina';
    protected $allowedFields = ['professor_id', 'disciplina_id', 'capacidade_minima'];

    public function existe(int $professorId, int $disciplinaId, ?int $ignorarId = null): bool
    {
        $consulta = $this->where('professor_id', $professorId)->where('disciplina_id', $disciplinaId);

        if ($ignorarId !== null) {
            $consulta->where('id_requisito_disciplina !=', $ignorarId);
        }

        return $consulta->countAllResults() > 0;
    }

    /**
     * Requisito com os nomes do professor e da disciplina.
     *
     * @return array<string, mixed>|null
     */
    public function completo(int $id): ?array
    {
        return $this->select('requisitos_disciplina.*, u.nome AS professor, d.nome AS disciplina, d.sigla')
            ->join('usuarios u', 'u.id_usuario = requisitos_disciplina.professor_id')
            ->join('disciplinas d', 'd.id_disciplina = requisitos_disciplina.disciplina_id')
            ->where('requisitos_disciplina.id_requisito_disciplina', $id)
            ->first();
    }

    /** Exclui o requisito e seus itens e softwares (soft delete, na mesma transação). */
    public function excluirComDetalhes(int $id): void
    {
        $this->db->transStart();

        foreach (['requisitos_disciplina_itens', 'requisitos_disciplina_softwares'] as $tabela) {
            $this->db->table($tabela)
                ->where('requisito_disciplina_id', $id)
                ->where('deletado_em', null)
                ->update(['deletado_em' => date('Y-m-d H:i:s')]);
        }

        $this->delete($id);

        $this->db->transComplete();
    }
}

<?php

namespace App\Libraries\Agendamento;

use CodeIgniter\Database\BaseConnection;

/**
 * Vínculos professor-disciplina por semestre (professores_disciplinas). A alocação semestral exige o vínculo;
 * a importação copia os vínculos de um semestre para outro sem duplicar os que já existem.
 */
final class Vinculos
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function existe(int $semestreId, int $disciplinaId, int $professorId): bool
    {
        return $this->db->table('professores_disciplinas')
            ->where('semestre_id', $semestreId)
            ->where('disciplina_id', $disciplinaId)
            ->where('professor_id', $professorId)
            ->where('deletado_em', null)
            ->countAllResults() > 0;
    }

    public function criar(int $semestreId, int $disciplinaId, int $professorId): int
    {
        $this->db->table('professores_disciplinas')->insert([
            'semestre_id'   => $semestreId,
            'disciplina_id' => $disciplinaId,
            'professor_id'  => $professorId,
        ]);

        return (int) $this->db->insertID();
    }

    /** Alocações não canceladas que dependem do vínculo. */
    public function alocacoesDependentes(int $semestreId, int $disciplinaId, int $professorId): int
    {
        return $this->db->table('alocacoes_semestrais')
            ->where('semestre_id', $semestreId)
            ->where('disciplina_id', $disciplinaId)
            ->where('professor_id', $professorId)
            ->where('status <>', 'cancelada')
            ->where('deletado_em', null)
            ->countAllResults();
    }

    /**
     * Copia os vínculos do semestre de origem (disciplinas e professores ainda ativos) para o de destino.
     * Com $cursos, copia só os das disciplinas desses cursos (o responsável não importa o que não gere).
     *
     * @param list<int>|null $cursos null = todos
     *
     * @return array{copiados: int, existentes: int}
     *
     * @throws Recusa
     */
    public function importar(int $origemId, int $destinoId, ?array $cursos = null): array
    {
        if ($origemId === $destinoId) {
            throw new Recusa('Agendamento.linkImportSame');
        }

        $consulta = $this->db->table('professores_disciplinas pd')
            ->select('pd.disciplina_id, pd.professor_id')
            ->join('disciplinas d', 'd.id_disciplina = pd.disciplina_id AND d.deletado_em IS NULL')
            ->join('usuarios u', 'u.id_usuario = pd.professor_id AND u.deletado_em IS NULL AND u.ativo = 1')
            ->where('pd.semestre_id', $origemId)
            ->where('pd.deletado_em', null);

        if ($cursos !== null) {
            $consulta->whereIn('d.curso_id', $cursos === [] ? [0] : $cursos);
        }

        $linhas = $consulta->get()->getResultArray();

        $copiados   = 0;
        $existentes = 0;

        foreach ($linhas as $linha) {
            if ($this->existe($destinoId, (int) $linha['disciplina_id'], (int) $linha['professor_id'])) {
                $existentes++;

                continue;
            }

            $this->criar($destinoId, (int) $linha['disciplina_id'], (int) $linha['professor_id']);
            $copiados++;
        }

        return ['copiados' => $copiados, 'existentes' => $existentes];
    }
}

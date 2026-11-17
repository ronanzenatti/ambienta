<?php

namespace App\Libraries\Academico;

use App\Libraries\Autorizacao;
use CodeIgniter\Database\BaseConnection;

/**
 * Quem enxerga e quem gere as atribuições de aulas (professores_disciplinas):
 * - academico.atribuicoes leitor vê todas; editor gere as de qualquer curso;
 * - o responsável de um curso (cursos.responsavel_id) gere as atribuições das disciplinas dele, sem precisar
 *   da permissão;
 * - os demais veem só as próprias (como professor).
 */
final class GestaoAtribuicoes
{
    public const CHAVE = 'academico.atribuicoes';

    private ?bool $responsavel = null;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Autorizacao $autorizacao,
        private readonly int $usuarioId
    ) {
    }

    public function veTodas(): bool
    {
        return $this->autorizacao->pode(self::CHAVE);
    }

    public function gereTodos(): bool
    {
        return $this->autorizacao->pode(self::CHAVE, Autorizacao::EDITOR);
    }

    /** Pode cadastrar, importar ou excluir atribuições de algum curso? */
    public function gereAlgum(): bool
    {
        return $this->gereTodos() || $this->ehResponsavelDeAlgum();
    }

    public function gereCurso(int $cursoId): bool
    {
        if ($this->gereTodos()) {
            return true;
        }

        return $this->usuarioId > 0 && $this->db->table('cursos')
            ->where('id_curso', $cursoId)
            ->where('responsavel_id', $this->usuarioId)
            ->where('deletado_em', null)
            ->countAllResults() > 0;
    }

    /**
     * Cursos cujas atribuições o usuário gere.
     *
     * @return list<int>|null null = todos os cursos
     */
    public function cursosGeridos(): ?array
    {
        if ($this->gereTodos()) {
            return null;
        }

        $linhas = $this->db->table('cursos')
            ->select('id_curso')
            ->where('responsavel_id', $this->usuarioId)
            ->where('deletado_em', null)
            ->get()
            ->getResultArray();

        return array_map(static fn (array $l): int => (int) $l['id_curso'], $linhas);
    }

    private function ehResponsavelDeAlgum(): bool
    {
        return $this->responsavel ??= $this->usuarioId > 0 && $this->db->table('cursos')
            ->where('responsavel_id', $this->usuarioId)
            ->where('deletado_em', null)
            ->countAllResults() > 0;
    }
}

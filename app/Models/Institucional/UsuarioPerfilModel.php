<?php

namespace App\Models\Institucional;

use App\Libraries\Auditoria\Auditavel;
use CodeIgniter\Model;

/**
 * Vínculo usuário-perfil com escopo (global, campus ou prédio): tabela usuarios_perfis.
 */
class UsuarioPerfilModel extends Model
{
    use Auditavel;

    protected $table          = 'usuarios_perfis';
    protected $primaryKey     = 'id_usuario_perfil';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $dateFormat     = 'datetime';
    protected $createdField   = 'criado_em';
    protected $updatedField   = 'alterado_em';
    protected $deletedField   = 'deletado_em';
    protected $allowedFields  = ['usuario_id', 'perfil_id', 'escopo_tipo', 'campus_id', 'predio_id'];

    /**
     * Vínculos ativos com o nome do perfil e do campus/prédio (para exibir e para checar escopo).
     *
     * @return list<array<string, mixed>>
     */
    public function doUsuario(int $usuarioId): array
    {
        return $this->db->query(
            'SELECT up.id_usuario_perfil, up.perfil_id, up.escopo_tipo, up.campus_id, up.predio_id,
                    p.nome AS perfil_nome, p.super_perfil,
                    IF(LENGTH(TRIM(c.abreviacao)) > 0, TRIM(c.abreviacao), c.nome) AS campus_nome,
                    pr.nome AS predio_nome, pr.campus_id AS predio_campus_id
             FROM usuarios_perfis up
             JOIN perfis p ON p.id_perfil = up.perfil_id AND p.deletado_em IS NULL
             LEFT JOIN campus c ON c.id_campus = up.campus_id
             LEFT JOIN predios pr ON pr.id_predio = up.predio_id
             WHERE up.usuario_id = ? AND up.deletado_em IS NULL
             ORDER BY p.nome, up.id_usuario_perfil',
            [$usuarioId]
        )->getResultArray();
    }

    /**
     * Resumo "Perfil (escopo)" por usuário, para a listagem (com o necessário para conferir, por linha,
     * se quem consulta administra o usuário).
     *
     * @param list<int> $usuarioIds
     *
     * @return array<int, list<array<string, mixed>>> id do usuário => vínculos
     */
    public function resumoPorUsuarios(array $usuarioIds): array
    {
        if ($usuarioIds === []) {
            return [];
        }

        $linhas = $this->db->table('usuarios_perfis up')
            ->select('up.usuario_id, up.escopo_tipo, up.campus_id, up.predio_id, p.nome AS perfil_nome, p.super_perfil')
            ->select("IF(LENGTH(TRIM(c.abreviacao)) > 0, TRIM(c.abreviacao), c.nome) AS campus_nome", false)
            ->select('pr.nome AS predio_nome')
            ->join('perfis p', 'p.id_perfil = up.perfil_id AND p.deletado_em IS NULL')
            ->join('campus c', 'c.id_campus = up.campus_id', 'left')
            ->join('predios pr', 'pr.id_predio = up.predio_id', 'left')
            ->whereIn('up.usuario_id', $usuarioIds)
            ->where('up.deletado_em', null)
            ->orderBy('p.nome')
            ->get()
            ->getResultArray();

        $porUsuario = [];
        foreach ($linhas as $linha) {
            $porUsuario[(int) $linha['usuario_id']][] = $linha;
        }

        return $porUsuario;
    }

    public function existeVinculo(int $usuarioId, int $perfilId, string $escopo, ?int $campusId, ?int $predioId): bool
    {
        $consulta = $this->where(['usuario_id' => $usuarioId, 'perfil_id' => $perfilId, 'escopo_tipo' => $escopo]);

        $campusId === null ? $consulta->where('campus_id', null) : $consulta->where('campus_id', $campusId);
        $predioId === null ? $consulta->where('predio_id', null) : $consulta->where('predio_id', $predioId);

        return $consulta->countAllResults() > 0;
    }

    /** Soft delete de todos os vínculos do usuário (exclusão e anonimização). */
    public function removerDoUsuario(int $usuarioId): void
    {
        // Um a um (por id), para cada remoção entrar no log de auditoria.
        foreach ($this->where('usuario_id', $usuarioId)->findColumn($this->primaryKey) ?? [] as $id) {
            $this->delete((int) $id);
        }
    }
}

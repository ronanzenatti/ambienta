<?php

namespace App\Models\Institucional;

use App\Libraries\Auditoria\Auditavel;
use CodeIgniter\Model;

class UsuarioModel extends Model
{
    use Auditavel;

    protected $table          = 'usuarios';
    protected $primaryKey     = 'id_usuario';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $dateFormat     = 'datetime';
    protected $createdField   = 'criado_em';
    protected $updatedField   = 'alterado_em';
    protected $deletedField   = 'deletado_em';
    protected $allowedFields  = [
        'nome', 'email', 'cpf', 'senha_hash', 'google_id', 'avatar', 'idioma_preferido',
        'ativo', 'ultimo_acesso_em', 'anonimizado_em', 'token_ical', 'trocar_senha', 'deletado_em',
    ];

    /** Colunas em que a unicidade vale só entre registros ativos (colunas *_unico do schema). */
    public const CAMPOS_UNICOS = ['email', 'cpf', 'google_id'];

    /** @return array<string, mixed>|null */
    public function ativoPorEmail(string $email): ?array
    {
        return $this->where('email', $email)->first();
    }

    /** @return array<string, mixed>|null */
    public function ativoPorCpf(string $cpf): ?array
    {
        return $this->where('cpf', $cpf)->first();
    }

    /** @return array<string, mixed>|null */
    public function ativoPorGoogleId(string $googleId): ?array
    {
        return $this->where('google_id', $googleId)->first();
    }

    /**
     * Registro soft-deletado (e não anonimizado) que ocupa o valor: base da oferta de reativação.
     * Anonimizado nunca volta: seus dados pessoais já foram apagados.
     *
     * @return array<string, mixed>|null
     */
    public function removidoPor(string $campo, string $valor): ?array
    {
        $this->assertCampoUnico($campo);

        return $this->withDeleted()
            ->where($campo, $valor)
            ->where('deletado_em IS NOT NULL')
            ->where('anonimizado_em', null)
            ->orderBy('id_usuario', 'DESC')
            ->first();
    }

    /** Já existe um registro ATIVO com o valor (ignorando um id, ao editar). */
    public function existeAtivo(string $campo, string $valor, ?int $ignorarId = null): bool
    {
        $this->assertCampoUnico($campo);

        $consulta = $this->where($campo, $valor);

        if ($ignorarId !== null) {
            $consulta->where('id_usuario !=', $ignorarId);
        }

        return $consulta->countAllResults() > 0;
    }

    /**
     * Usuário por id, mesmo soft-deletado (telas de reativação e anonimização).
     *
     * @return array<string, mixed>|null
     */
    public function incluindoRemovido(int $id): ?array
    {
        return $this->withDeleted()->find($id);
    }

    /**
     * Quantos usuários ativos têm um perfil superperfil em escopo global, sem contar $exceto.
     * Serve para nunca deixar o sistema sem administrador.
     */
    public function contarSupersAtivos(?int $exceto = null): int
    {
        $sql = "SELECT COUNT(DISTINCT u.id_usuario) AS total
                FROM usuarios u
                JOIN usuarios_perfis up ON up.usuario_id = u.id_usuario AND up.deletado_em IS NULL
                JOIN perfis p ON p.id_perfil = up.perfil_id AND p.deletado_em IS NULL AND p.super_perfil = 1
                WHERE u.deletado_em IS NULL AND u.ativo = 1 AND up.escopo_tipo = 'global'";
        $binds = [];

        if ($exceto !== null) {
            $sql    .= ' AND u.id_usuario <> ?';
            $binds[] = $exceto;
        }

        return (int) $this->db->query($sql, $binds)->getRow()->total;
    }

    /**
     * Usuário tem ao menos um vínculo dentro do escopo (campus, prédio ou prédio de um campus do escopo).
     * Condição SQL pronta (ids convertidos para inteiro), usada pela listagem de usuários.
     *
     * @param array{global: bool, campus: list<int>, predio: list<int>} $escopo
     */
    public function condicaoEscopo(array $escopo): string
    {
        $campus = implode(',', array_map('intval', $escopo['campus']));
        $predio = implode(',', array_map('intval', $escopo['predio']));

        $partes = [];

        if ($campus !== '') {
            $partes[] = "(up.escopo_tipo = 'campus' AND up.campus_id IN ({$campus}))";
            $partes[] = "(up.escopo_tipo = 'predio' AND pr.campus_id IN ({$campus}))";
        }

        if ($predio !== '') {
            $partes[] = "(up.escopo_tipo = 'predio' AND up.predio_id IN ({$predio}))";
        }

        if ($partes === []) {
            return '1 = 0';
        }

        return 'usuarios.id_usuario IN (
            SELECT up.usuario_id FROM usuarios_perfis up
            LEFT JOIN predios pr ON pr.id_predio = up.predio_id
            WHERE up.deletado_em IS NULL AND (' . implode(' OR ', $partes) . '))';
    }

    private function assertCampoUnico(string $campo): void
    {
        if (! in_array($campo, self::CAMPOS_UNICOS, true)) {
            throw new \InvalidArgumentException("Campo sem unicidade própria: {$campo}");
        }
    }
}

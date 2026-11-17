<?php

namespace App\Models\Institucional;

use App\Libraries\Auditoria\Auditavel;
use App\Libraries\Auditoria\Auditoria;
use CodeIgniter\Model;

class PerfilModel extends Model
{
    use Auditavel;

    protected $table          = 'perfis';
    protected $primaryKey     = 'id_perfil';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $dateFormat     = 'datetime';
    protected $createdField   = 'criado_em';
    protected $updatedField   = 'alterado_em';
    protected $deletedField   = 'deletado_em';
    protected $allowedFields  = ['nome', 'super_perfil'];

    public function existeNome(string $nome, ?int $ignorarId = null): bool
    {
        $consulta = $this->where('nome', $nome);

        if ($ignorarId !== null) {
            $consulta->where('id_perfil !=', $ignorarId);
        }

        return $consulta->countAllResults() > 0;
    }

    /** Vínculos ativos de usuários que ainda usam o perfil (impede a exclusão). */
    public function contarVinculos(int $perfilId): int
    {
        return $this->db->table('usuarios_perfis')
            ->where('perfil_id', $perfilId)
            ->where('deletado_em', null)
            ->countAllResults();
    }

    /**
     * Permissões do perfil no formato chave => tipo de acesso ('leitor'|'editor').
     *
     * @return array<string, string>
     */
    public function permissoes(int $perfilId): array
    {
        $linhas = $this->db->query(
            'SELECT pe.chave, pp.tipo_acesso
             FROM perfis_permissoes pp
             JOIN permissoes pe ON pe.id_permissao = pp.permissao_id AND pe.deletado_em IS NULL
             WHERE pp.perfil_id = ? AND pp.deletado_em IS NULL',
            [$perfilId]
        )->getResultArray();

        return array_column($linhas, 'tipo_acesso', 'chave');
    }

    /**
     * Catálogo de permissões, agrupado por módulo.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function catalogoPorModulo(): array
    {
        $linhas = $this->db->table('permissoes')
            ->select('chave, nome, modulo')
            ->where('deletado_em', null)
            ->orderBy('modulo')
            ->orderBy('chave')
            ->get()
            ->getResultArray();

        $porModulo = [];
        foreach ($linhas as $linha) {
            $porModulo[$linha['modulo']][] = $linha;
        }

        return $porModulo;
    }

    /**
     * Substitui a matriz do perfil: cria, altera ou remove (soft delete) as linhas de perfis_permissoes.
     *
     * @param array<string, string> $matriz chave => 'leitor'|'editor' (chave ausente = sem acesso)
     */
    public function sincronizarPermissoes(int $perfilId, array $matriz): void
    {
        $permissoes = $this->db->table('permissoes')
            ->select('id_permissao, chave')
            ->where('deletado_em', null)
            ->get()
            ->getResultArray();
        $catalogo   = array_column($permissoes, 'id_permissao', 'chave');
        $atuais = $this->db->table('perfis_permissoes')
            ->where('perfil_id', $perfilId)
            ->where('deletado_em', null)
            ->get()
            ->getResultArray();
        $porPermissao = array_column($atuais, null, 'permissao_id');
        $agora        = date('Y-m-d H:i:s');
        $auditoria    = new Auditoria($this->db);

        $this->db->transStart();

        foreach ($catalogo as $chave => $permissaoId) {
            $novo  = $matriz[$chave] ?? null;
            $atual = $porPermissao[$permissaoId] ?? null;

            if ($novo === null && $atual !== null) {
                $id = (int) $atual['id_perfil_permissao'];
                $this->db->table('perfis_permissoes')
                    ->where('id_perfil_permissao', $id)
                    ->update(['deletado_em' => $agora]);
                $auditoria->registrarDepois('perfis_permissoes', $id, $atual);
            } elseif ($novo !== null && $atual === null) {
                $this->db->table('perfis_permissoes')->insert([
                    'perfil_id' => $perfilId, 'permissao_id' => $permissaoId, 'tipo_acesso' => $novo,
                ]);
                $auditoria->registrarDepois('perfis_permissoes', (int) $this->db->insertID(), null);
            } elseif ($novo !== null && $atual['tipo_acesso'] !== $novo) {
                $id = (int) $atual['id_perfil_permissao'];
                $this->db->table('perfis_permissoes')
                    ->where('id_perfil_permissao', $id)
                    ->update(['tipo_acesso' => $novo]);
                $auditoria->registrarDepois('perfis_permissoes', $id, $atual);
            }
        }

        $this->db->transComplete();
    }
}

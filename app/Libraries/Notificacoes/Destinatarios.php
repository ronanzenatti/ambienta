<?php

namespace App\Libraries\Notificacoes;

use CodeIgniter\Database\BaseConnection;

/**
 * Quem deve ser avisado de algo que pede ação de um papel (aprovar descarte, confirmar reserva, decidir
 * conflito, resolver chamado): os usuários ativos cujo perfil dá a permissão num escopo que cobre o local.
 *
 * Perfis comuns primeiro: o superperfil (Direção, acesso a todos os campi) só é avisado quando ninguém mais
 * no local tem a permissão, para a caixa dele não receber o dia a dia de todos os campi.
 */
final class Destinatarios
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param string $nivel 'editor' (padrão; permissões específicas só existem como editor) ou 'leitor'
     *
     * @return list<int>
     */
    public function comPermissao(string $chave, ?int $campusId, ?int $predioId = null, string $nivel = 'editor'): array
    {
        $niveis = $nivel === 'leitor' ? ['leitor', 'editor'] : ['editor'];

        $linhas = $this->db->query(
            "SELECT DISTINCT up.usuario_id, p.super_perfil
             FROM usuarios_perfis up
             JOIN perfis p ON p.id_perfil = up.perfil_id AND p.deletado_em IS NULL
             JOIN usuarios u ON u.id_usuario = up.usuario_id AND u.deletado_em IS NULL AND u.ativo = 1
             LEFT JOIN perfis_permissoes pp ON pp.perfil_id = p.id_perfil AND pp.deletado_em IS NULL
             LEFT JOIN permissoes pe ON pe.id_permissao = pp.permissao_id AND pe.deletado_em IS NULL
             WHERE up.deletado_em IS NULL
               AND (p.super_perfil = 1 OR (pe.chave = ? AND pp.tipo_acesso IN ?))
               AND (up.escopo_tipo = 'global'
                    OR (up.escopo_tipo = 'campus' AND up.campus_id = ?)
                    OR (up.escopo_tipo = 'predio' AND up.predio_id = ?))",
            [$chave, $niveis, $campusId ?? 0, $predioId ?? 0]
        )->getResultArray();

        $comuns = [];
        $super  = [];

        foreach ($linhas as $linha) {
            if ((bool) $linha['super_perfil']) {
                $super[] = (int) $linha['usuario_id'];
            } else {
                $comuns[] = (int) $linha['usuario_id'];
            }
        }

        return array_values(array_unique($comuns !== [] ? $comuns : $super));
    }

    /**
     * Quem tem a permissão (editor) em qualquer escopo, para o que não pertence a um local (ex.: softwares).
     * Perfis comuns primeiro; o superperfil só quando ninguém mais a tem.
     *
     * @return list<int>
     */
    public function comPermissaoEmQualquerEscopo(string $chave): array
    {
        $linhas = $this->db->query(
            "SELECT DISTINCT up.usuario_id, p.super_perfil
             FROM usuarios_perfis up
             JOIN perfis p ON p.id_perfil = up.perfil_id AND p.deletado_em IS NULL
             JOIN usuarios u ON u.id_usuario = up.usuario_id AND u.deletado_em IS NULL AND u.ativo = 1
             LEFT JOIN perfis_permissoes pp ON pp.perfil_id = p.id_perfil AND pp.deletado_em IS NULL
                  AND pp.tipo_acesso = 'editor'
             LEFT JOIN permissoes pe ON pe.id_permissao = pp.permissao_id AND pe.deletado_em IS NULL
             WHERE up.deletado_em IS NULL AND (p.super_perfil = 1 OR pe.chave = ?)",
            [$chave]
        )->getResultArray();

        $comuns = [];
        $super  = [];

        foreach ($linhas as $linha) {
            if ((bool) $linha['super_perfil']) {
                $super[] = (int) $linha['usuario_id'];
            } else {
                $comuns[] = (int) $linha['usuario_id'];
            }
        }

        return array_values(array_unique($comuns !== [] ? $comuns : $super));
    }

    /** @return list<int> quem tem a permissão no ambiente (pelo prédio e campus dele) */
    public function noAmbiente(string $chave, int $ambienteId, string $nivel = 'editor'): array
    {
        $local = $this->db->query(
            'SELECT a.predio_id, p.campus_id FROM ambientes a JOIN predios p ON p.id_predio = a.predio_id
             WHERE a.id_ambiente = ?',
            [$ambienteId]
        )->getRow();

        if ($local === null) {
            return [];
        }

        return $this->comPermissao($chave, (int) $local->campus_id, (int) $local->predio_id, $nivel);
    }

    /** @return list<int> quem tem a permissão onde o item está (ambiente ou almoxarifado) */
    public function doItem(string $chave, int $itemId): array
    {
        $item = $this->db->query(
            'SELECT i.ambiente_id, x.campus_id AS campus_almoxarifado
             FROM itens i LEFT JOIN almoxarifados x ON x.id_almoxarifado = i.almoxarifado_id
             WHERE i.id_item = ?',
            [$itemId]
        )->getRow();

        if ($item === null) {
            return [];
        }

        return $item->ambiente_id !== null
            ? $this->noAmbiente($chave, (int) $item->ambiente_id)
            : $this->comPermissao($chave, (int) $item->campus_almoxarifado);
    }
}

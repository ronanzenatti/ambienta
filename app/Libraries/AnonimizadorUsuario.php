<?php

namespace App\Libraries;

use App\Models\Institucional\UsuarioModel;
use App\Models\Institucional\UsuarioPerfilModel;

/**
 * Anonimização LGPD: troca os dados pessoais do usuário por "Usuário removido (LGPD)" sem apagar o
 * registro, para o histórico de movimentações, reservas e chamados continuar apontando para ele.
 *
 * O e-mail é NOT NULL e único, então recebe um valor sintético por id, sem domínio real.
 * É irreversível (não há reativação de anonimizado) e derruba os vínculos de perfil.
 */
class AnonimizadorUsuario
{
    public const NOME = 'Usuário removido (LGPD)';

    public function __construct(
        private readonly UsuarioModel $usuarios = new UsuarioModel(),
        private readonly UsuarioPerfilModel $vinculos = new UsuarioPerfilModel(),
    ) {
    }

    public static function emailAnonimo(int $usuarioId): string
    {
        return "removido-{$usuarioId}@anonimizado.invalid";
    }

    /**
     * @return bool false se o usuário não existe ou já foi anonimizado
     */
    public function anonimizar(int $usuarioId): bool
    {
        $usuario = $this->usuarios->incluindoRemovido($usuarioId);

        if ($usuario === null || $usuario['anonimizado_em'] !== null) {
            return false;
        }

        $agora = date('Y-m-d H:i:s');
        $db    = db_connect();

        $db->transStart();

        $this->usuarios->withDeleted()->update($usuarioId, [
            'nome'             => self::NOME,
            'email'            => self::emailAnonimo($usuarioId),
            'cpf'              => null,
            'senha_hash'       => null,
            'google_id'        => null,
            'token_ical'       => null,
            'avatar'           => null,
            'ativo'            => 0,
            'anonimizado_em'   => $agora,
            'deletado_em'      => $usuario['deletado_em'] ?? $agora,
        ]);
        $this->vinculos->removerDoUsuario($usuarioId);

        $db->transComplete();

        return $db->transStatus();
    }
}

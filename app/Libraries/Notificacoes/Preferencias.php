<?php

namespace App\Libraries\Notificacoes;

use CodeIgniter\Database\BaseConnection;
use InvalidArgumentException;

/**
 * Preferências de notificação (preferencias_notificacao, uma linha ativa por usuário). Sem linha, o e-mail está
 * ativo. Desativar exige o termo de ciência sobre o risco de perder avisos: grava a versão aceita e quando
 * (o CHECK do schema recusa e-mail desativado sem termo). As notificações no sistema nunca se desativam.
 */
final class Preferencias
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function emailAtivo(int $usuarioId): bool
    {
        $linha = $this->linha($usuarioId);

        return $linha === null || (bool) $linha['canal_email_ativo'];
    }

    /** @return array<string, mixed>|null */
    public function linha(int $usuarioId): ?array
    {
        return $this->db->table('preferencias_notificacao')
            ->where('usuario_id', $usuarioId)
            ->where('deletado_em', null)
            ->get()
            ->getRowArray();
    }

    public function desativarEmail(int $usuarioId, string $versaoTermo): void
    {
        if (trim($versaoTermo) === '') {
            throw new InvalidArgumentException('Desativar o e-mail exige a versão do termo de ciência.');
        }

        $this->gravar($usuarioId, [
            'canal_email_ativo'       => 0,
            'termo_ciencia_versao'    => $versaoTermo,
            'termo_ciencia_aceito_em' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Reativar é livre; o registro do último termo aceito continua na linha. */
    public function ativarEmail(int $usuarioId): void
    {
        $this->gravar($usuarioId, ['canal_email_ativo' => 1]);
    }

    /** @param array<string, mixed> $dados */
    private function gravar(int $usuarioId, array $dados): void
    {
        $linha = $this->linha($usuarioId);

        if ($linha === null) {
            $this->db->table('preferencias_notificacao')->insert(['usuario_id' => $usuarioId] + $dados);

            return;
        }

        $this->db->table('preferencias_notificacao')
            ->where('id_preferencia_notificacao', (int) $linha['id_preferencia_notificacao'])
            ->update($dados);
    }
}

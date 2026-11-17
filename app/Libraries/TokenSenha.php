<?php

namespace App\Libraries;

/**
 * Token de redefinição de senha, sem tabela: "<id>.<expira>.<assinatura>", com HMAC-SHA256 sobre o
 * id, a expiração e o hash de senha ATUAL do usuário. Por isso o link:
 *  - expira sozinho;
 *  - vale uma única vez (ao trocar a senha o hash muda e a assinatura deixa de conferir);
 *  - não pode ser forjado sem a chave da aplicação (encryption.key).
 */
class TokenSenha
{
    public const VALIDADE_MINUTOS = 60;

    public function __construct(private readonly string $chave)
    {
        if ($chave === '') {
            throw new \RuntimeException('encryption.key não configurada: rode `php spark key:generate`.');
        }
    }

    public function gerar(int $usuarioId, ?string $senhaHash, ?int $agora = null): string
    {
        $expira = ($agora ?? time()) + self::VALIDADE_MINUTOS * 60;

        return "{$usuarioId}.{$expira}." . $this->assinatura($usuarioId, $expira, $senhaHash);
    }

    /** Id do usuário a que o token se refere (ainda não validado), para buscar o hash atual. */
    public static function usuarioDoToken(string $token): ?int
    {
        return preg_match('/^(\d+)\.\d+\.[a-f0-9]{64}$/', $token, $partes) === 1 ? (int) $partes[1] : null;
    }

    public function valido(string $token, ?string $senhaHashAtual, ?int $agora = null): bool
    {
        if (preg_match('/^(\d+)\.(\d+)\.([a-f0-9]{64})$/', $token, $partes) !== 1) {
            return false;
        }

        [, $id, $expira, $assinatura] = $partes;

        if ((int) $expira < ($agora ?? time())) {
            return false;
        }

        return hash_equals($this->assinatura((int) $id, (int) $expira, $senhaHashAtual), $assinatura);
    }

    private function assinatura(int $usuarioId, int $expira, ?string $senhaHash): string
    {
        return hash_hmac('sha256', $usuarioId . '|' . $expira . '|' . ($senhaHash ?? ''), $this->chave);
    }
}

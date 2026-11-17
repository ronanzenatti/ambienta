<?php

use App\Libraries\TokenSenha;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TokenSenhaTest extends CIUnitTestCase
{
    private const HASH = '$2y$10$hash-atual-da-senha';

    public function testTokenRecemGeradoEValido(): void
    {
        $tokens = new TokenSenha('chave-de-teste');
        $token  = $tokens->gerar(7, self::HASH);

        $this->assertTrue($tokens->valido($token, self::HASH));
        $this->assertSame(7, TokenSenha::usuarioDoToken($token));
    }

    public function testExpiraDepoisDoPrazo(): void
    {
        $tokens = new TokenSenha('chave-de-teste');
        $agora  = 1_000_000;
        $token  = $tokens->gerar(7, self::HASH, $agora);

        $this->assertTrue($tokens->valido($token, self::HASH, $agora + TokenSenha::VALIDADE_MINUTOS * 60));
        $this->assertFalse($tokens->valido($token, self::HASH, $agora + TokenSenha::VALIDADE_MINUTOS * 60 + 1));
    }

    public function testDeixaDeValerQuandoASenhaMuda(): void
    {
        $tokens = new TokenSenha('chave-de-teste');
        $token  = $tokens->gerar(7, self::HASH);

        $this->assertFalse($tokens->valido($token, '$2y$10$outra-senha'));
    }

    public function testUsuarioSemSenhaAindaPodeDefinirUma(): void
    {
        $tokens = new TokenSenha('chave-de-teste');
        $token  = $tokens->gerar(7, null);

        $this->assertTrue($tokens->valido($token, null));
        $this->assertFalse($tokens->valido($token, self::HASH));
    }

    public function testNaoValidaComOutraChaveNemComTokenAdulterado(): void
    {
        $token = (new TokenSenha('chave-a'))->gerar(7, self::HASH);

        $this->assertFalse((new TokenSenha('chave-b'))->valido($token, self::HASH));

        // Trocar o id do usuário no token invalida a assinatura.
        $adulterado = preg_replace('/^7\./', '8.', $token);
        $this->assertFalse((new TokenSenha('chave-a'))->valido((string) $adulterado, self::HASH));
    }

    public function testFormatoInvalidoEhRecusado(): void
    {
        $tokens = new TokenSenha('chave-de-teste');

        foreach (['', 'abc', '7.123', '7.123.xyz', "7.123.'; DROP TABLE usuarios;--"] as $lixo) {
            $this->assertFalse($tokens->valido($lixo, self::HASH));
            $this->assertNull(TokenSenha::usuarioDoToken($lixo));
        }
    }

    public function testExigeChaveConfigurada(): void
    {
        $this->expectException(RuntimeException::class);

        new TokenSenha('');
    }
}

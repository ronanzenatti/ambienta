<?php

use App\Libraries\Cpf;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class CpfTest extends CIUnitTestCase
{
    public function testNormalizarMantemSoDigitos(): void
    {
        $this->assertSame('52998224725', Cpf::normalizar('529.982.247-25'));
        $this->assertSame('', Cpf::normalizar('abc'));
    }

    public function testAceitaCpfValidoComOuSemMascara(): void
    {
        $this->assertTrue(Cpf::valido('52998224725'));
        $this->assertTrue(Cpf::valido('529.982.247-25'));
    }

    /**
     * @dataProvider invalidos
     */
    public function testRecusaCpfInvalido(string $cpf): void
    {
        $this->assertFalse(Cpf::valido($cpf));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidos(): array
    {
        return [
            'digito verificador errado' => ['52998224726'],
            'poucos digitos'            => ['1234567890'],
            'digitos demais'            => ['529982247250'],
            'todos iguais'              => ['11111111111'],
            'vazio'                     => [''],
            'letras'                    => ['abcdefghijk'],
        ];
    }
}

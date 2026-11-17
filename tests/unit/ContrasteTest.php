<?php

use App\Libraries\Contraste;
use App\Libraries\TokensDesign;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Contraste WCAG 2.1 e a validação da identidade visual de campus. As razões conferidas aqui são as
 * documentadas no próprio tokens.json, então a conta e os tokens se validam mutuamente.
 *
 * @internal
 */
final class ContrasteTest extends CIUnitTestCase
{
    public function testExtremosDaEscala(): void
    {
        $this->assertEqualsWithDelta(21.0, Contraste::razao('#FFFFFF', '#000000'), 0.001);
        $this->assertEqualsWithDelta(1.0, Contraste::razao('#1E3A5F', '#1E3A5F'), 0.001);
        $this->assertEqualsWithDelta(Contraste::razao('#000000', '#FFFFFF'), Contraste::razao('#FFFFFF', '#000000'), 0.0001);
    }

    public function testRazoesDocumentadasNosTokens(): void
    {
        $t = static fn (string $nome): string => TokensDesign::cor($nome);

        // tokens.json: "ink sobre action-edit tem contraste 7.12:1 (AAA); branco sobre action-edit falha (2.2:1)".
        $this->assertEqualsWithDelta(7.12, Contraste::razao($t('ink'), $t('action-edit')), 0.01);
        $this->assertEqualsWithDelta(2.2, Contraste::razao($t('brand-ink'), $t('action-edit')), 0.05);
        // "Contraste 11.5:1 sobre o brand padrão" e excluir: "brand-ink (branco), contraste 6.5:1".
        $this->assertEqualsWithDelta(11.5, Contraste::razao($t('brand-ink'), $t('brand')), 0.1);
        $this->assertEqualsWithDelta(6.5, Contraste::razao($t('brand-ink'), $t('signal-critical')), 0.1);
    }

    public function testTokensResolvemReferencias(): void
    {
        $this->assertSame(TokensDesign::cor('brand'), TokensDesign::cor('focus-ring'));
        $this->assertSame('#FFFFFF', Contraste::corDoTexto(Contraste::TEXTO_CLARO));
        $this->assertSame(TokensDesign::cor('ink'), Contraste::corDoTexto(Contraste::TEXTO_ESCURO));
    }

    public function testIdentidadeQueAtendeNaoTemFalha(): void
    {
        $this->assertNull(Contraste::avaliarIdentidade(TokensDesign::cor('brand'), Contraste::TEXTO_CLARO));
        $this->assertNull(Contraste::avaliarIdentidade('#F2C94C', Contraste::TEXTO_ESCURO));
    }

    public function testQuandoSoAOutraOpcaoAtendeElaEhSugerida(): void
    {
        $falha = Contraste::avaliarIdentidade('#C99A2E', Contraste::TEXTO_CLARO);

        $this->assertNotNull($falha);
        $this->assertSame('outra', $falha['motivo']);
        $this->assertSame(Contraste::TEXTO_ESCURO, $falha['sugerida']);
        $this->assertLessThan(Contraste::MINIMO_TEXTO, $falha['razao']);
        $this->assertGreaterThanOrEqual(Contraste::MINIMO_TEXTO, $falha['razaoSugerida']);
    }

    public function testCorQueNenhumTextoAtendeEhRecusadaComAsDuasRazoes(): void
    {
        // Cinza médio: branco fica abaixo de 4.5 e o ink também.
        $falha = Contraste::avaliarIdentidade('#777777', Contraste::TEXTO_CLARO);

        $this->assertNotNull($falha);
        $this->assertSame('nenhuma', $falha['motivo']);
        $this->assertLessThan(Contraste::MINIMO_TEXTO, $falha['claro']);
        $this->assertLessThan(Contraste::MINIMO_TEXTO, $falha['escuro']);
    }

    public function testFormatacaoTruncaParaNuncaArredondarParaCima(): void
    {
        $this->assertSame('4,49', Contraste::formatar(4.4999));
        $this->assertSame('4.50', Contraste::formatar(4.5, '.'));
        $this->assertSame('21,00', Contraste::formatar(21.0));
    }

    public function testSoAceitaHexDeSeisDigitos(): void
    {
        $this->assertTrue(Contraste::corValida('#1e3a5f'));
        foreach (['1E3A5F', '#1E3A5', '#1E3A5FF', 'red', '#1E3A5G', '#1E3A5F;x:y', ''] as $invalida) {
            $this->assertFalse(Contraste::corValida($invalida), $invalida);
        }

        $this->expectException(InvalidArgumentException::class);
        Contraste::luminancia('red');
    }
}

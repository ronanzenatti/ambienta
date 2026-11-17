<?php

use App\Libraries\Arquivos;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Libraries\ArquivosDeTeste;

/**
 * Envio de logo, foto e mapa: tipo conferido pelo conteúdo, limite de tamanho, nome aleatório e
 * remoção restrita aos arquivos gravados pelo próprio sistema.
 *
 * @internal
 */
final class ArquivosTest extends CIUnitTestCase
{
    private ArquivosDeTeste $arquivos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->arquivos = new ArquivosDeTeste();
    }

    protected function tearDown(): void
    {
        $this->arquivos->limpar();

        parent::tearDown();
    }

    public function testImagemValidaEhGravadaComNomeAleatorio(): void
    {
        $resultado = $this->arquivos->guardar(ArquivosDeTeste::enviado('png', '../../index.php'), 'campus');

        $this->assertIsArray($resultado);
        $this->assertArrayHasKey('caminho', $resultado);
        $this->assertMatchesRegularExpression('#^campus/[0-9a-f]{32}\.png$#', $resultado['caminho']);
        $this->assertFileExists($this->arquivos->raiz() . '/' . $resultado['caminho']);

        $jpg = $this->arquivos->guardar(ArquivosDeTeste::enviado('jpg', 'foto.png'), 'campus');
        $this->assertStringEndsWith('.jpg', $jpg['caminho'] ?? '', 'a extensão vem do conteúdo, não do nome enviado');
    }

    public function testConteudoQueNaoEhImagemEhRecusadoMesmoComNomeDeImagem(): void
    {
        $resultado = $this->arquivos->guardar(ArquivosDeTeste::enviado('falso', 'logo.png'), 'campus');

        $this->assertSame(['erro' => 'Institucional.fileTypeImage'], $resultado);
        $this->assertDirectoryDoesNotExist($this->arquivos->raiz() . '/campus');
    }

    public function testPdfSoEhAceitoNoMapa(): void
    {
        $this->assertSame(
            ['erro' => 'Institucional.fileTypeImage'],
            $this->arquivos->guardar(ArquivosDeTeste::enviado('pdf', 'mapa.pdf'), 'campus', Arquivos::IMAGEM)
        );

        $mapa = $this->arquivos->guardar(ArquivosDeTeste::enviado('pdf', 'mapa.pdf'), 'campus', Arquivos::MAPA);
        $this->assertMatchesRegularExpression('#^campus/[0-9a-f]{32}\.pdf$#', $mapa['caminho'] ?? '');

        $imagemNoMapa = $this->arquivos->guardar(ArquivosDeTeste::enviado('png'), 'campus', Arquivos::MAPA);
        $this->assertStringEndsWith('.png', $imagemNoMapa['caminho'] ?? '');

        $this->assertSame(
            ['erro' => 'Institucional.fileTypeMap'],
            $this->arquivos->guardar(ArquivosDeTeste::enviado('falso', 'mapa.pdf'), 'campus', Arquivos::MAPA)
        );
    }

    public function testImagemAcimaDoLimiteEhRecusada(): void
    {
        $grande = ArquivosDeTeste::enviado('grande');
        $this->assertGreaterThan(Arquivos::LIMITES['png'], filesize($grande->getTempName()));

        $this->assertSame(['erro' => 'Institucional.fileTooBig'], $this->arquivos->guardar($grande, 'campus'));
    }

    public function testCampoVazioOuComErroDoPhp(): void
    {
        $this->assertNull($this->arquivos->guardar(null, 'campus'));
        $this->assertNull($this->arquivos->guardar(new UploadedFile('', '', '', 0, UPLOAD_ERR_NO_FILE), 'campus'));
        $this->assertSame(
            ['erro' => 'Institucional.fileTooBig'],
            $this->arquivos->guardar(new UploadedFile('', 'x.png', 'image/png', 0, UPLOAD_ERR_INI_SIZE), 'campus')
        );
    }

    public function testSemUploadHttpDeVerdadeNadaEhGravado(): void
    {
        // A classe de produção exige is_uploaded_file(): um arquivo local qualquer não passa.
        $producao = new Arquivos($this->arquivos->raiz());

        $this->assertSame(['erro' => 'Institucional.fileFailed'], $producao->guardar(ArquivosDeTeste::enviado('png'), 'campus'));
    }

    public function testRemoverSoApagaArquivoGravadoPeloSistema(): void
    {
        $caminho = $this->arquivos->guardar(ArquivosDeTeste::enviado('png'), 'campus')['caminho'] ?? '';
        $fora    = $this->arquivos->raiz() . '/segredo.txt';
        file_put_contents($fora, 'x');

        $this->arquivos->remover('../segredo.txt');
        $this->arquivos->remover('campus/../segredo.txt');
        $this->assertFileExists($fora);

        $this->arquivos->remover($caminho);
        $this->assertFileDoesNotExist($this->arquivos->raiz() . '/' . $caminho);
    }
}

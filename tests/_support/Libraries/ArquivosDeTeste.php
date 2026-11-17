<?php

namespace Tests\Support\Libraries;

use App\Libraries\Arquivos;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Arquivos com upload "de mentira": nos testes não há upload HTTP real (is_uploaded_file falha),
 * então o arquivo temporário é aceito e copiado. Todo o resto (tipo real, tamanho, nome aleatório)
 * é a lógica de produção. Também cria os arquivos de exemplo usados nos testes.
 */
class ArquivosDeTeste extends Arquivos
{
    private string $pasta;

    public function __construct(?string $raiz = null)
    {
        $this->pasta = $raiz ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ambienta-uploads-' . bin2hex(random_bytes(6));

        parent::__construct($this->pasta);
    }

    public function raiz(): string
    {
        return $this->pasta;
    }

    /** Apaga a pasta de uploads do teste. */
    public function limpar(): void
    {
        if (! is_dir($this->pasta)) {
            return;
        }

        $itens = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->pasta, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($itens as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->pasta);
    }

    protected function recebido(UploadedFile $arquivo): bool
    {
        return true;
    }

    protected function mover(UploadedFile $arquivo, string $destino, string $nome): void
    {
        copy($arquivo->getTempName(), $destino . DIRECTORY_SEPARATOR . $nome);
    }

    /**
     * Arquivo temporário de exemplo: 'png', 'jpg', 'pdf', 'falso' (texto) ou 'grande' (PNG acima de 2 MB).
     */
    public static function exemplo(string $tipo): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'amb');

        match ($tipo) {
            'png'    => imagepng(self::imagem(4, 4), $caminho),
            'jpg'    => imagejpeg(self::imagem(4, 4), $caminho),
            'pdf'    => file_put_contents($caminho, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n"),
            'falso'  => file_put_contents($caminho, '<?php echo "não sou imagem";'),
            'grande' => self::pngGrande($caminho),
            default  => throw new \InvalidArgumentException($tipo),
        };

        return $caminho;
    }

    /** UploadedFile apontando para um arquivo de exemplo (nome e tipo "informados" são os do navegador). */
    public static function enviado(string $tipo, string $nomeOriginal = 'arquivo.png'): UploadedFile
    {
        $caminho = self::exemplo($tipo);

        return new UploadedFile($caminho, $nomeOriginal, 'image/png', (int) filesize($caminho), UPLOAD_ERR_OK);
    }

    /**
     * Monta $_FILES como o PHP faz num envio multipart, para as requisições dos testes de feature. O request
     * lê os arquivos do serviço superglobals (que guarda sua cópia de $_FILES), por isso a troca é feita nele.
     * Sem campos, limpa os envios.
     *
     * @param array<string, string> $campos campo => tipo de exemplo
     */
    public static function simularEnvio(array $campos = []): void
    {
        $arquivos = [];

        foreach ($campos as $campo => $tipo) {
            $caminho = self::exemplo($tipo);

            $arquivos[$campo] = [
                'name'     => $campo . ($tipo === 'pdf' ? '.pdf' : '.png'),
                'type'     => $tipo === 'pdf' ? 'application/pdf' : 'image/png',
                'tmp_name' => $caminho,
                'error'    => UPLOAD_ERR_OK,
                'size'     => (int) filesize($caminho),
            ];
        }

        service('superglobals')->setFilesArray($arquivos);
    }

    private static function imagem(int $largura, int $altura): \GdImage
    {
        $imagem = imagecreatetruecolor($largura, $altura);
        imagefill($imagem, 0, 0, (int) imagecolorallocate($imagem, 30, 58, 95));

        return $imagem;
    }

    /** PNG válido de mais de 2 MB: ruído não comprime, então 1000x1000 pixels aleatórios bastam. */
    private static function pngGrande(string $caminho): bool
    {
        $imagem = imagecreatetruecolor(1000, 1000);

        for ($y = 0; $y < 1000; $y++) {
            for ($x = 0; $x < 1000; $x++) {
                imagesetpixel($imagem, $x, $y, random_int(0, 0xFFFFFF));
            }
        }

        imagepng($imagem, $caminho, 0);

        return true;
    }
}

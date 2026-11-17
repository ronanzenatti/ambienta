<?php

namespace App\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Arquivos enviados nos cadastros institucionais (logo, foto e mapa).
 *
 * O tipo é conferido pelo conteúdo (finfo), nunca pela extensão ou pelo tipo informado pelo navegador,
 * e o arquivo é gravado com nome aleatório e extensão escolhida aqui: nada do nome original chega ao disco.
 * SVG não é aceito (pode carregar script). Os arquivos ficam em public/uploads, servidos diretamente;
 * o .htaccess dessa pasta impede a execução de scripts.
 */
class Arquivos
{
    public const IMAGEM = 'imagem';
    public const MAPA   = 'mapa';

    /** @var array<string, string> tipo MIME real => extensão gravada */
    private const IMAGENS = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    private const PDF = ['application/pdf' => 'pdf'];

    /** @var array<string, int> limite em bytes por tipo de arquivo */
    public const LIMITES = [
        'png'  => 2 * 1024 * 1024,
        'jpg'  => 2 * 1024 * 1024,
        'webp' => 2 * 1024 * 1024,
        'pdf'  => 5 * 1024 * 1024,
    ];

    public function __construct(private readonly string $raiz)
    {
    }

    /**
     * Grava o arquivo enviado em <raiz>/<pasta>/. Sem arquivo (campo vazio) devolve null.
     *
     * @return array{caminho: string}|array{erro: string} caminho relativo à raiz, ou a chave de idioma do erro
     */
    public function guardar(?UploadedFile $arquivo, string $pasta, string $uso = self::IMAGEM): ?array
    {
        if ($arquivo === null || $arquivo->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (in_array($arquivo->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return ['erro' => 'Institucional.fileTooBig'];
        }

        if ($arquivo->getError() !== UPLOAD_ERR_OK || ! $this->recebido($arquivo)) {
            return ['erro' => 'Institucional.fileFailed'];
        }

        $aceitos  = $uso === self::MAPA ? self::IMAGENS + self::PDF : self::IMAGENS;
        $mime     = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($arquivo->getTempName());
        $extensao = $aceitos[$mime] ?? null;

        if ($extensao === null || ! $this->conteudoConfere($arquivo->getTempName(), $extensao)) {
            return ['erro' => $uso === self::MAPA ? 'Institucional.fileTypeMap' : 'Institucional.fileTypeImage'];
        }

        if ((int) filesize($arquivo->getTempName()) > self::LIMITES[$extensao]) {
            return ['erro' => 'Institucional.fileTooBig'];
        }

        if (preg_match('/^[a-z]+$/', $pasta) !== 1) {
            throw new \InvalidArgumentException("Pasta de upload inválida: {$pasta}");
        }

        $nome    = bin2hex(random_bytes(16)) . '.' . $extensao;
        $destino = rtrim($this->raiz, '/\\') . DIRECTORY_SEPARATOR . $pasta;

        if (! is_dir($destino)) {
            mkdir($destino, 0755, true);
        }

        $this->mover($arquivo, $destino, $nome);

        return ['caminho' => $pasta . '/' . $nome];
    }

    /** Remove um arquivo gravado por guardar() (troca ou remoção pelo usuário). */
    public function remover(?string $caminho): void
    {
        if ($caminho === null || preg_match('#^[a-z]+/[0-9a-f]{32}\.(png|jpg|webp|pdf)$#', $caminho) !== 1) {
            return;
        }

        $arquivo = rtrim($this->raiz, '/\\') . DIRECTORY_SEPARATOR . $caminho;

        if (is_file($arquivo)) {
            unlink($arquivo);
        }
    }

    public static function ehPdf(?string $caminho): bool
    {
        return $caminho !== null && str_ends_with($caminho, '.pdf');
    }

    /** A imagem precisa abrir como imagem de verdade; o PDF precisa começar com a assinatura %PDF-. */
    private function conteudoConfere(string $caminho, string $extensao): bool
    {
        if ($extensao === 'pdf') {
            return (string) file_get_contents($caminho, false, null, 0, 5) === '%PDF-';
        }

        return @getimagesize($caminho) !== false;
    }

    /** Chegou por upload HTTP de verdade? (Ponto de troca nos testes, que não fazem upload real.) */
    protected function recebido(UploadedFile $arquivo): bool
    {
        return $arquivo->isValid();
    }

    protected function mover(UploadedFile $arquivo, string $destino, string $nome): void
    {
        $arquivo->move($destino, $nome);
    }
}

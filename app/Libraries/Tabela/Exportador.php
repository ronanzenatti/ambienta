<?php

namespace App\Libraries\Tabela;

use App\Libraries\TokensDesign;
use CodeIgniter\HTTP\DownloadResponse;
use Dompdf\Dompdf;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Exporta uma listagem (Tabela) para Excel (OpenSpout, gravação em fluxo, pouca memória) ou PDF (Dompdf,
 * PHP puro). Sai sempre o texto das colunas, nunca o HTML da tela, e na ordem da tela.
 * O escopo de permissão já vem na consulta da Tabela.
 */
final class Exportador
{
    /** O Dompdf monta o documento inteiro em memória: acima disso, só Excel. */
    public const LIMITE_PDF = 5000;

    public function __construct(private readonly Tabela $tabela)
    {
    }

    public function excel(Abrangencia $abrangencia): DownloadResponse
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'amb');

        if ($arquivo === false) {
            throw new \RuntimeException('Não foi possível criar o arquivo temporário da exportação.');
        }

        try {
            $writer = new Writer();
            $writer->openToFile($arquivo);
            $writer->getCurrentSheet()->setName($this->nomePlanilha());
            $writer->addRow(Row::fromValues($this->cabecalhos(), (new Style())->setFontBold()));

            foreach ($this->tabela->linhasExportacao($abrangencia) as $linha) {
                $writer->addRow(Row::fromValues($this->valoresPlanilha($linha)));
            }

            $writer->close();

            $conteudo = (string) file_get_contents($arquivo);
        } finally {
            @unlink($arquivo);
        }

        return $this->baixar('xlsx', $conteudo);
    }

    /** null quando passa do LIMITE_PDF (o controller avisa e sugere o Excel). */
    public function pdf(Abrangencia $abrangencia): ?DownloadResponse
    {
        $total = $this->tabela->contarExportacao($abrangencia);

        if ($total > self::LIMITE_PDF) {
            return null;
        }

        $linhas = [];

        foreach ($this->tabela->linhasExportacao($abrangencia) as $linha) {
            $linhas[] = array_map(
                static fn (Coluna $coluna): string => $coluna->textoDe($linha),
                $this->tabela->colunas()
            );
        }

        $usuario = service('autenticacao')->usuario();

        $html = view('components/tabela/pdf', [
            'tabela'      => $this->tabela,
            'linhas'      => $linhas,
            'abrangencia' => $abrangencia,
            'usuario'     => (string) ($usuario['nome'] ?? ''),
            'cor'         => static fn (string $token): string => TokensDesign::cor($token),
        ], ['saveData' => false]);

        $dompdf = new Dompdf([
            'isRemoteEnabled'      => false,
            'isPhpEnabled'         => false,
            'isJavascriptEnabled'  => false,
            'defaultFont'          => 'DejaVu Sans',
            'tempDir'              => sys_get_temp_dir(),
            'fontCache'            => sys_get_temp_dir(),
        ]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $fonte  = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text(
            $canvas->get_width() - 120,
            $canvas->get_height() - 28,
            lang('Tabela.pdfPage', ['{PAGE_NUM}', '{PAGE_COUNT}']),
            $fonte,
            8,
            self::rgb(TokensDesign::cor('ink-muted'))
        );

        return $this->baixar('pdf', (string) $dompdf->output());
    }

    /** @return list<string> */
    private function cabecalhos(): array
    {
        return array_map(static fn (Coluna $coluna): string => $coluna->rotulo, $this->tabela->colunas());
    }

    /**
     * Texto de cada coluna; inteiros de colunas numéricas saem como número, para somar na planilha.
     *
     * @param array<string, mixed> $linha
     *
     * @return list<int|string>
     */
    private function valoresPlanilha(array $linha): array
    {
        $valores = [];

        foreach ($this->tabela->colunas() as $coluna) {
            $texto     = $coluna->textoDe($linha);
            $valores[] = $coluna->ehNumerica() && preg_match('/^-?\d{1,15}$/', $texto) === 1 ? (int) $texto : $texto;
        }

        return $valores;
    }

    /** Nome de aba do Excel: até 31 caracteres, sem []:*?/\ */
    private function nomePlanilha(): string
    {
        $nome = trim((string) preg_replace('/[\[\]:*?\/\\\\]+/', ' ', $this->tabela->titulo()));

        return mb_substr($nome === '' ? 'Dados' : $nome, 0, 31);
    }

    private function baixar(string $extensao, string $conteudo): DownloadResponse
    {
        $resposta = new DownloadResponse($this->tabela->id() . '-' . date('Ymd-His') . '.' . $extensao, true);
        $resposta->setBinary($conteudo);

        return $resposta;
    }

    /** @return array{0: float, 1: float, 2: float} cor do token no formato do Dompdf (0 a 1) */
    private static function rgb(string $hex): array
    {
        return [
            hexdec(substr($hex, 1, 2)) / 255,
            hexdec(substr($hex, 3, 2)) / 255,
            hexdec(substr($hex, 5, 2)) / 255,
        ];
    }
}

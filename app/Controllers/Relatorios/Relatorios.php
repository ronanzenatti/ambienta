<?php

namespace App\Controllers\Relatorios;

use App\Controllers\BaseController;
use App\Libraries\Relatorios\Filtros;
use App\Tabelas\Relatorios\CancelamentosTabela;
use App\Tabelas\Relatorios\ChamadosRelatorioTabela;
use App\Tabelas\Relatorios\DescartesRelatorioTabela;
use App\Tabelas\Relatorios\InventarioTabela;
use App\Tabelas\Relatorios\ItensPorStatusTabela;
use App\Tabelas\Relatorios\MovimentacoesRelatorioTabela;
use App\Tabelas\Relatorios\OcupacaoTabela;
use App\Tabelas\Relatorios\RelatorioTabela;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Relatórios (REQUISITOS §12), com relatorios.acesso: cada um é uma listagem padrão com filtros de campus,
 * prédio e período, limitada ao escopo dessa permissão (o superperfil vê tudo), e exporta para Excel/PDF.
 */
class Relatorios extends BaseController
{
    private const CHAVE = 'relatorios.acesso';

    /** @var array<string, class-string<RelatorioTabela>> slug => relatório, na ordem da página inicial */
    private const RELATORIOS = [
        'inventario'       => InventarioTabela::class,
        'itens-por-status' => ItensPorStatusTabela::class,
        'movimentacoes'    => MovimentacoesRelatorioTabela::class,
        'descartes'        => DescartesRelatorioTabela::class,
        'ocupacao'         => OcupacaoTabela::class,
        'chamados'         => ChamadosRelatorioTabela::class,
        'cancelamentos'    => CancelamentosTabela::class,
    ];

    private ?Filtros $filtros = null;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);

        helper('institucional');
    }

    public function index(): string
    {
        return view('relatorios/index', ['relatorios' => array_keys(self::RELATORIOS)]);
    }

    public function ver(string $slug): string
    {
        $relatorio = $this->tabela($this->relatorio($slug));

        if ($this->pedidoParcial()) {
            return $relatorio->fragmento();
        }

        return view('relatorios/relatorio', ['tabela' => $relatorio, 'filtros' => $this->filtros()]);
    }

    public function exportar(string $slug): ResponseInterface
    {
        return $this->exportarTabela($this->relatorio($slug));
    }

    private function relatorio(string $slug): RelatorioTabela
    {
        $classe = self::RELATORIOS[$slug] ?? throw PageNotFoundException::forPageNotFound();

        return new $classe($this->filtros());
    }

    private function filtros(): Filtros
    {
        return $this->filtros ??= Filtros::daRequisicao(
            $this->request,
            service('autorizacao')->escopoPara(self::CHAVE),
            db_connect()
        );
    }
}

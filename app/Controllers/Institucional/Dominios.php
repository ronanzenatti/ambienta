<?php

namespace App\Controllers\Institucional;

use App\Controllers\BaseController;
use App\Models\Institucional\DominioEmailPermitidoModel;
use App\Tabelas\Institucional\DominiosTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Lista de domínios de e-mail aceitos no login com Google Workspace (dominios_email_permitidos).
 * Configurável só pelo superperfil (filtro `superperfil` nas rotas), nunca fixa no código.
 */
class Dominios extends BaseController
{
    private const FORMATO = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/';

    private DominioEmailPermitidoModel $dominios;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);

        $this->dominios = new DominioEmailPermitidoModel();
    }

    public function index(): string
    {
        if ($this->pedidoParcial()) {
            return $this->tabela(new DominiosTabela())->fragmento();
        }

        return view('institucional/dominios/index', ['tabela' => $this->tabela(new DominiosTabela())]);
    }

    public function novo(): ResponseInterface|string
    {
        return $this->telaFormulario('institucional/dominios', ['valor' => '']);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela(new DominiosTabela());
    }

    public function criar(): ResponseInterface|RedirectResponse|string
    {
        $dominio = strtolower(trim((string) $this->request->getPost('dominio')));

        $erro = match (true) {
            preg_match(self::FORMATO, $dominio) !== 1 => lang('Dominios.invalid'),
            $this->dominios->existe($dominio)         => lang('Dominios.exists'),
            default                                   => null,
        };

        if ($erro !== null) {
            return $this->telaFormulario('institucional/dominios', ['valor' => $dominio], ['dominio' => $erro], 422);
        }

        $this->dominios->insert(['dominio' => $dominio, 'ativo' => 1]);

        return $this->salvo(site_url('dominios'), lang('Dominios.added'));
    }

    public function alternar(int $id): RedirectResponse
    {
        $dominio = $this->dominios->find($id);

        if ($dominio === null) {
            return redirect()->to(site_url('dominios'))->with('erro', lang('Dominios.notFound'));
        }

        $this->dominios->update($id, ['ativo' => (bool) $dominio['ativo'] ? 0 : 1]);

        return redirect()->to(site_url('dominios'))->with('sucesso', lang('Dominios.toggled'));
    }

    public function excluir(int $id): RedirectResponse
    {
        if ($this->dominios->find($id) === null) {
            return redirect()->to(site_url('dominios'))->with('erro', lang('Dominios.notFound'));
        }

        if (! $this->exclusaoConfirmada()) {
            return redirect()->to(site_url('dominios'))->with('erro', lang('Ui.confirmMismatch'));
        }

        $this->dominios->delete($id);

        return redirect()->to(site_url('dominios'))->with('sucesso', lang('Dominios.deleted'));
    }
}

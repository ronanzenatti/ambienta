<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Exige usuário logado. Quem não está vai para o login e, se a requisição era um GET, volta para
 * a página pedida depois de entrar.
 */
class AuthFilter implements FilterInterface
{
    public const SESSAO_DESTINO = 'url_destino';

    /**
     * @param list<string>|null $arguments
     *
     * @return ResponseInterface|RequestInterface|null
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        if (service('autenticacao')->logado()) {
            return null;
        }

        if (strtolower($request->getMethod()) === 'get') {
            $destino = '/' . ltrim($request->getUri()->getPath(), '/');
            $consulta = $request->getUri()->getQuery();

            session()->set(self::SESSAO_DESTINO, $destino . ($consulta !== '' ? '?' . $consulta : ''));
        }

        return redirect()->to(site_url('entrar'))->with('aviso', lang('Auth.loginRequired'));
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

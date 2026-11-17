<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Áreas restritas ao superperfil (acesso irrestrito): perfis e domínios de e-mail permitidos.
 */
class SuperPerfilFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     *
     * @return ResponseInterface|RequestInterface|null
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $semLogin = (new AuthFilter())->before($request);

        if ($semLogin !== null) {
            return $semLogin;
        }

        return service('autorizacao')->ehSuper() ? null : PermissaoFilter::negar();
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

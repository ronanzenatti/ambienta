<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;

/**
 * Aplica o idioma da sessão (preferido do usuário ou o escolhido no cabeçalho) à requisição.
 * Sem sessão, vale o idioma padrão (pt-BR). Só a interface muda: conteúdo cadastrado não é traduzido.
 */
class IdiomaFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     *
     * @return ResponseInterface|RequestInterface|null
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! $request instanceof IncomingRequest) {
            return null;
        }

        $config = config(App::class);
        $idioma = service('autenticacao')->idioma();

        if ($idioma === null || ! in_array($idioma, $config->supportedLocales, true)) {
            $idioma = $config->defaultLocale;
        }

        $request->setLocale($idioma);
        service('language')->setLocale($idioma);

        return null;
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Quem entrou com uma senha provisória (definida por um administrador, usuarios.trocar_senha) só consegue
 * escolher a própria senha: qualquer outra tela redireciona para senha/trocar. Sair e trocar o idioma
 * continuam livres. Visitante anônimo passa direto (não cria sessão).
 */
class TrocarSenhaFilter implements FilterInterface
{
    public const TELA = 'senha/trocar';

    private const LIVRES = [self::TELA, 'sair', 'idioma'];

    /**
     * @param list<string>|null $arguments
     *
     * @return ResponseInterface|RequestInterface|null
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $usuario = service('autenticacao')->usuario();

        if ($usuario === null || ! (bool) $usuario['trocar_senha']) {
            return null;
        }

        $caminho = $request instanceof IncomingRequest ? trim($request->getPath(), '/') : '';

        if (in_array($caminho, self::LIVRES, true)) {
            return null;
        }

        return redirect()->to(site_url(self::TELA))->with('aviso', lang('Auth.mustChangeNotice'));
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

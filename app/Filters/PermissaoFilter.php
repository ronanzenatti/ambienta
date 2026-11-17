<?php

namespace App\Filters;

use App\Libraries\Autorizacao;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Autorização de rota: `permissao:<chave>` exige a permissão em ALGUM escopo do usuário.
 * O nível segue o método HTTP: GET/HEAD/OPTIONS pedem 'leitor'; os demais, 'editor'.
 * Um segundo argumento (`permissao:<chave>,editor`) fixa o nível.
 *
 * O filtro só barra quem não tem a permissão em lugar nenhum. Conferir se o REGISTRO está dentro do
 * escopo (campus/prédio) é responsabilidade do controller, com Autorizacao::pode() e escopoPara().
 */
class PermissaoFilter implements FilterInterface
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

        $chave = $arguments[0] ?? throw new \InvalidArgumentException('Filtro permissao exige a chave da permissão.');
        // `permissao:<chave>,editor` força o nível (ex.: formulários de criação/edição, que são GET).
        $nivel = $arguments[1] ?? (in_array(strtolower($request->getMethod()), ['get', 'head', 'options'], true)
            ? Autorizacao::LEITOR
            : Autorizacao::EDITOR);

        if (service('autorizacao')->pode($chave, $nivel)) {
            return null;
        }

        return self::negar();
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    public static function negar(): ResponseInterface
    {
        return service('response')->setStatusCode(403)->setBody(view('auth/proibido'));
    }
}

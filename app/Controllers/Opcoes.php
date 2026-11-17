<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Opções dos selects do banco, sob demanda (ui_select_fonte + diretiva x-busca): GET opcoes/<fonte>?q=&pagina=,
 * mais os filtros da fonte. Devolve {itens: [{valor, rotulo}], proxima: url|null}, 20 por página.
 * Quem pode ver cada lista é decidido em App\Libraries\Opcoes\Registro::porNome (403 quando não pode).
 */
class Opcoes extends BaseController
{
    public function index(string $nome): ResponseInterface
    {
        $params = $this->request->getGet();
        $params = is_array($params) ? $params : [];
        $fonte  = service('opcoes')->porNome($nome, $params);

        if ($fonte === null) {
            return $this->response->setStatusCode(403)->setJSON(['itens' => [], 'proxima' => null]);
        }

        $pagina    = max(1, (int) ($params['pagina'] ?? 1));
        $resultado = $fonte->pagina((string) ($params['q'] ?? ''), $pagina);
        $proxima   = $resultado['temMais']
            ? site_url('opcoes/' . $nome) . '?' . http_build_query(['pagina' => $pagina + 1] + $params)
            : null;

        return $this->response->setJSON(['itens' => $resultado['itens'], 'proxima' => $proxima]);
    }
}

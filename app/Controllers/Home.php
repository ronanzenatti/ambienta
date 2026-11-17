<?php

namespace App\Controllers;

use App\Libraries\Painel\Painel;
use CodeIgniter\HTTP\RedirectResponse;

class Home extends BaseController
{
    /** Sem login: abre direto a consulta pública. Logado: o painel da pessoa. */
    public function index(): RedirectResponse|string
    {
        $usuario = service('autenticacao')->usuario();

        if ($usuario === null) {
            return redirect()->to(site_url('consulta'));
        }

        $painel = new Painel(db_connect(), service('autorizacao'), (int) $usuario['id_usuario']);

        return view('painel', [
            'nome'         => (string) $usuario['nome'],
            'hoje'         => $painel->hoje(),
            'semestre'     => $painel->semestre(),
            'atalhos'      => $painel->atalhos(),
            'pendencias'   => $painel->pendencias(),
            'agenda'       => $painel->agenda(),
            'solicitacoes' => $painel->solicitacoes(),
            'dias'         => Painel::DIAS_AGENDA - 1,
        ]);
    }
}

<?php

namespace App\Controllers;

use App\Models\Institucional\UsuarioModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Link do feed iCal pessoal (assinatura só leitura no Google Agenda, Outlook, Apple). O segredo fica em
 * usuarios.token_ical; gerar um novo link troca o segredo, e o link antigo para de funcionar na hora.
 */
class MinhaAgenda extends BaseController
{
    public function index(): string
    {
        $token = $this->usuarios()->select('token_ical')->find($this->usuarioId())['token_ical'] ?? null;

        return view('agendamento/minha_agenda', [
            'feed' => $token === null ? null : site_url('agenda/feed/' . $token . '/agenda.ics'),
        ]);
    }

    public function gerarLink(): RedirectResponse
    {
        $this->usuarios()->update($this->usuarioId(), ['token_ical' => bin2hex(random_bytes(32))]);

        return redirect()->to(site_url('minha-agenda'))->with('sucesso', lang('Agendamento.icalLinkCreated'));
    }

    private function usuarios(): UsuarioModel
    {
        return model(UsuarioModel::class);
    }

    private function usuarioId(): int
    {
        return (int) service('autenticacao')->id();
    }
}

<?php

namespace App\Controllers\Notificacoes;

use App\Controllers\ControladorCadastro;
use App\Libraries\Notificacoes\Mensagem;
use App\Libraries\Notificacoes\Preferencias;
use App\Tabelas\Notificacoes\NotificacoesTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Notificacoes as NotificacoesConfig;

/**
 * Caixa de notificações e preferências do usuário logado (só login: cada um vê só as suas). Abrir uma
 * notificação a marca como lida e leva ao registro de origem.
 */
class Notificacoes extends ControladorCadastro
{
    private const LISTA = 'notificacoes';

    public function index(): string
    {
        $tabela = $this->tabela(new NotificacoesTabela($this->usuarioId()));

        return $this->pedidoParcial() ? $tabela->fragmento() : view('patrimonio/_lista', [
            'tabela' => $tabela,
            'titulo' => lang('Notificacoes.title'),
            'intro'  => lang('Notificacoes.intro'),
            'extra'  => view('notificacoes/_acoes', [], ['saveData' => false]),
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela(new NotificacoesTabela($this->usuarioId()));
    }

    public function abrir(int $id): RedirectResponse
    {
        $db           = db_connect();
        $notificacao  = $db->table('notificacoes')
            ->where('id_notificacao', $id)
            ->where('usuario_id', $this->usuarioId())
            ->where('deletado_em', null)
            ->get()
            ->getRowArray();

        if ($notificacao === null) {
            return redirect()->to(site_url(self::LISTA))->with('erro', lang('Notificacoes.notFound'));
        }

        if (! (bool) $notificacao['lida']) {
            $db->table('notificacoes')
                ->where('id_notificacao', $id)
                ->update(['lida' => 1, 'lida_em' => date('Y-m-d H:i:s')]);
        }

        $destino = Mensagem::url($notificacao['referencia_tipo'], $notificacao['referencia_id']);

        return redirect()->to($destino ?? site_url(self::LISTA));
    }

    public function marcarTodas(): RedirectResponse
    {
        db_connect()->table('notificacoes')
            ->where('usuario_id', $this->usuarioId())
            ->where('lida', 0)
            ->where('deletado_em', null)
            ->update(['lida' => 1, 'lida_em' => date('Y-m-d H:i:s')]);

        return redirect()->to(site_url(self::LISTA))->with('sucesso', lang('Notificacoes.allMarkedRead'));
    }

    public function preferencias(): string
    {
        return $this->telaPreferencias();
    }

    public function salvarPreferencias(): ResponseInterface|RedirectResponse|string
    {
        $preferencias = new Preferencias(db_connect());
        $email        = $this->request->getPost('email') === '1';

        if ($email) {
            $preferencias->ativarEmail($this->usuarioId());
        } elseif ($this->request->getPost('ciencia') !== '1') {
            return $this->response->setStatusCode(422)->setBody(
                $this->telaPreferencias(['ciencia' => lang('Notificacoes.termRequired')])
            );
        } elseif ($preferencias->emailAtivo($this->usuarioId())) {
            $preferencias->desativarEmail($this->usuarioId(), config(NotificacoesConfig::class)->versaoTermo);
        }

        return redirect()->to(site_url('notificacoes/preferencias'))->with('sucesso', lang('Notificacoes.prefsSaved'));
    }

    /** @param array<string, string> $erros */
    private function telaPreferencias(array $erros = []): string
    {
        return view('notificacoes/preferencias', [
            'preferencia' => (new Preferencias(db_connect()))->linha($this->usuarioId()),
            'erros'       => $erros,
        ]);
    }

    private function usuarioId(): int
    {
        return (int) service('autenticacao')->id();
    }
}

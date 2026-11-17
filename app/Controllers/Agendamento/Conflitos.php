<?php

namespace App\Controllers\Agendamento;

use App\Libraries\Agendamento\Recusa;
use App\Libraries\Agendamento\Reservas as ReservasServico;
use App\Libraries\Autorizacao;
use App\Tabelas\Agendamento\ConflitosTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Conflitos entre reserva pontual e alocação semestral (agendamento.autorizar_conflito): a lista dos que
 * aguardam decisão e as duas decisões. Autorizar excepciona a alocação naquele dia; negar cancela a reserva
 * (em modal, com observação opcional, porque desfaz o pedido de outra pessoa).
 */
class Conflitos extends ControladorAgendamento
{
    public function index(): ResponseInterface|string
    {
        if (! $this->autorizacao->pode(self::AUTORIZAR, Autorizacao::EDITOR)) {
            return $this->negar();
        }

        $tabela = $this->tabela($this->novaTabela());

        return $this->pedidoParcial() ? $tabela->fragmento() : view('patrimonio/_lista', [
            'tabela' => $tabela,
            'titulo' => lang('Agendamento.conflictsTitle'),
            'intro'  => lang('Agendamento.conflictsIntro'),
        ]);
    }

    public function exportar(): ResponseInterface
    {
        if (! $this->autorizacao->pode(self::AUTORIZAR, Autorizacao::EDITOR)) {
            return $this->negar();
        }

        return $this->exportarTabela($this->novaTabela());
    }

    public function autorizar(int $id): RedirectResponse|ResponseInterface
    {
        [$conflito, $recusa] = $this->carregar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $destino = redirect()->to($this->destinoLocal(
            $this->request->getPost('voltar'),
            '/agendamento/reservas/' . $conflito['reserva_pontual_id']
        ));

        try {
            (new ReservasServico(db_connect()))
                ->decidirConflito($id, $this->usuarioId(), true, $this->texto('observacao'));
        } catch (Recusa $recusa) {
            return $destino->with('erro', $recusa->texto());
        }

        return $destino->with('sucesso', lang('Agendamento.conflictAuthorized'));
    }

    public function negacao(int $id): ResponseInterface|RedirectResponse|string
    {
        [$conflito, $recusa] = $this->carregar($id);

        return $recusa ?? $this->formularioNegacao($conflito, []);
    }

    public function negarConflito(int $id): ResponseInterface|RedirectResponse|string
    {
        [$conflito, $recusa] = $this->carregar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $dados = ['observacao' => $this->texto('observacao'), 'voltar' => $this->texto('voltar')];

        try {
            (new ReservasServico(db_connect()))->decidirConflito($id, $this->usuarioId(), false, $dados['observacao']);
        } catch (Recusa $recusa) {
            return $this->formularioNegacao($conflito, $dados, ['observacao' => $recusa->texto()], 422);
        }

        $destino  = site_url(ltrim($this->destinoLocal($dados['voltar'], '/agendamento/conflitos'), '/'));
        $mensagem = lang('Agendamento.conflictDenied');

        if ($this->pedidoModal()) {
            session()->setFlashdata('sucesso', $mensagem);
        }

        return $this->salvo($destino, $mensagem);
    }

    private function novaTabela(): ConflitosTabela
    {
        return new ConflitosTabela($this->escopo(Autorizacao::EDITOR, self::AUTORIZAR));
    }

    /**
     * Conflito pendente num ambiente onde o usuário pode decidir.
     *
     * @return array{0: array<string, mixed>, 1: ResponseInterface|RedirectResponse|null}
     */
    private function carregar(int $id): array
    {
        $conflito = db_connect()->query(
            "SELECT c.id_conflito_agendamento, c.reserva_pontual_id, r.ambiente_id, r.titulo,
                    r.data_hora_inicio, r.data_hora_fim
             FROM conflitos_agendamento c
             JOIN reservas_pontuais r ON r.id_reserva_pontual = c.reserva_pontual_id
             WHERE c.id_conflito_agendamento = ? AND c.deletado_em IS NULL",
            [$id]
        )->getRowArray();

        if ($conflito === null) {
            $lista = redirect()->to(site_url('agendamento/conflitos'));

            return [[], $lista->with('erro', lang('Agendamento.conflictAlreadyDecided'))];
        }

        $pode = $this->podeNoAmbiente((int) $conflito['ambiente_id'], Autorizacao::EDITOR, self::AUTORIZAR);

        return [$conflito, $pode ? null : $this->negar()];
    }

    /**
     * @param array<string, mixed>  $conflito
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    private function formularioNegacao(
        array $conflito,
        array $dados,
        array $erros = [],
        int $status = 200
    ): ResponseInterface|string {
        $dados['voltar'] ??= $this->destinoLocal((string) $this->request->getGet('voltar'), '/agendamento/conflitos');

        return $this->telaFormulario('agendamento/conflitos/negar', [
            'conflito' => $conflito,
            'dados'    => $dados,
        ], $erros, $status);
    }
}

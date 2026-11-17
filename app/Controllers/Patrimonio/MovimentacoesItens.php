<?php

namespace App\Controllers\Patrimonio;

use App\Libraries\Autorizacao;
use App\Libraries\Patrimonio\Locais;
use App\Libraries\Patrimonio\Movimentacoes;
use App\Libraries\Patrimonio\Recusa;
use App\Tabelas\Patrimonio\MovimentacoesTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Movimentações de itens: lista, a fila "aguardando meu recebimento" e a página da movimentação, que é a
 * tela de confirmação de recebimento (protocolo) para o destinatário. O envio nasce na página do item.
 *
 * - confirmar: só o destinatário, com patrimonio.confirmar_recebimento no local de destino;
 * - cancelar um envio pendente (engano): o remetente ou um editor de patrimônio na origem.
 *
 * @phpstan-import-type Local from Locais
 */
class MovimentacoesItens extends ControladorPatrimonio
{
    private const LISTA = 'patrimonio/movimentacoes';

    public function index(): string
    {
        $tabela = $this->tabela(new MovimentacoesTabela($this->escopo(), $this->usuarioId()));

        return $this->pedidoParcial() ? $tabela->fragmento() : view('patrimonio/_lista', [
            'tabela' => $tabela,
            'titulo' => lang('Patrimonio.movementsTitle'),
            'intro'  => lang('Patrimonio.movementsIntro'),
            'extra'  => ui_button(lang('Patrimonio.toReceiveTitle'), 'secondary', [
                'href' => site_url(self::LISTA . '/a-receber'),
            ]),
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela(new MovimentacoesTabela($this->escopo(), $this->usuarioId()));
    }

    public function aReceber(): string
    {
        $tabela = $this->tabela($this->tabelaAReceber());

        return $this->pedidoParcial() ? $tabela->fragmento() : view('patrimonio/_lista', [
            'tabela' => $tabela,
            'titulo' => lang('Patrimonio.toReceiveTitle'),
            'intro'  => lang('Patrimonio.toReceiveIntro'),
        ]);
    }

    public function exportarAReceber(): ResponseInterface
    {
        return $this->exportarTabela($this->tabelaAReceber());
    }

    public function ver(int $id): ResponseInterface|RedirectResponse|string
    {
        [$mov, $recusa] = $this->carregar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $db   = db_connect();
        $item = $db->table('itens i')
            ->select('i.id_item, i.numero_patrimonio, i.patrimoniavel, i.nome, i.quantidade, m.foto')
            ->join('modelos_itens m', 'm.id_modelo_item = i.modelo_item_id', 'left')
            ->where('i.id_item', $mov['item_id'])
            ->get()
            ->getRowArray();

        $nomes = $db->table('usuarios')
            ->select('id_usuario, nome')
            ->whereIn('id_usuario', [$mov['remetente_id'], $mov['destinatario_id']])
            ->get()
            ->getResultArray();
        $nomes = array_column($nomes, 'nome', 'id_usuario');

        $pendente = $mov['status'] === 'pendente';

        return view('patrimonio/movimentacoes/ver', [
            'mov'          => $mov,
            'item'         => $item,
            'origem'       => $this->locais->rotulo(
                self::id($mov['origem_ambiente_id']),
                self::id($mov['origem_almoxarifado_id'])
            ),
            'destino'      => $this->locais->rotulo(
                self::id($mov['destino_ambiente_id']),
                self::id($mov['destino_almoxarifado_id'])
            ),
            'remetente'    => (string) ($nomes[$mov['remetente_id']] ?? ''),
            'destinatario' => (string) ($nomes[$mov['destinatario_id']] ?? ''),
            'atrasada'     => $pendente && strtotime((string) $mov['data_limite_confirmacao']) < time(),
            'podeConfirmar' => $pendente && $this->podeConfirmar($mov),
            'podeCancelar' => $pendente && $this->podeCancelar($mov),
        ]);
    }

    public function confirmar(int $id): ResponseInterface|RedirectResponse
    {
        [$mov, $recusa] = $this->carregar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        if (! $this->podeConfirmar($mov)) {
            return $this->negar();
        }

        $pagina = redirect()->to(site_url(self::LISTA . "/{$id}"));

        try {
            (new Movimentacoes(db_connect()))->confirmar($id);
        } catch (Recusa $recusa) {
            return $pagina->with('erro', lang($recusa->getMessage()));
        }

        return $pagina->with('sucesso', lang('Patrimonio.movementConfirmed'));
    }

    public function cancelar(int $id): ResponseInterface|RedirectResponse
    {
        [$mov, $recusa] = $this->carregar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        if (! $this->podeCancelar($mov)) {
            return $this->negar();
        }

        if (! $this->exclusaoConfirmada()) {
            return redirect()->to(site_url(self::LISTA . "/{$id}"))->with('erro', lang('Ui.confirmMismatch'));
        }

        try {
            (new Movimentacoes(db_connect()))->cancelar($id);
        } catch (Recusa $recusa) {
            return redirect()->to(site_url(self::LISTA . "/{$id}"))->with('erro', lang($recusa->getMessage()));
        }

        return redirect()->to(site_url('patrimonio/itens/' . $mov['item_id']))
            ->with('sucesso', lang('Patrimonio.movementCancelled'));
    }

    private function tabelaAReceber(): MovimentacoesTabela
    {
        return new MovimentacoesTabela($this->escopo(), $this->usuarioId(), aReceber: true);
    }

    /**
     * Movimentação que o usuário pode ver (envolvido, ou origem/destino no escopo), e a recusa, se houver.
     *
     * @return array{0: array<string, mixed>, 1: ResponseInterface|RedirectResponse|null}
     */
    private function carregar(int $id): array
    {
        $mov = db_connect()->table('movimentacoes_itens')
            ->where('id_movimentacao_item', $id)
            ->where('deletado_em', null)
            ->get()
            ->getRowArray();

        if ($mov === null) {
            return [[], redirect()->to(site_url(self::LISTA))->with('erro', lang('Patrimonio.movementNotFound'))];
        }

        $usuario    = $this->usuarioId();
        $envolvido  = in_array($usuario, [(int) $mov['remetente_id'], (int) $mov['destinatario_id']], true);
        $noEscopo   = $this->podeNoLocal($this->origem($mov)) || $this->podeNoLocal($this->destino($mov));

        return [$mov, $envolvido || $noEscopo ? null : $this->negar()];
    }

    /** @param array<string, mixed> $mov */
    private function podeConfirmar(array $mov): bool
    {
        return (int) $mov['destinatario_id'] === $this->usuarioId()
            && $this->podeNoLocal($this->destino($mov), Autorizacao::EDITOR, 'patrimonio.confirmar_recebimento');
    }

    /** @param array<string, mixed> $mov */
    private function podeCancelar(array $mov): bool
    {
        return (int) $mov['remetente_id'] === $this->usuarioId()
            || $this->podeNoLocal($this->origem($mov), Autorizacao::EDITOR);
    }

    /**
     * @param array<string, mixed> $mov
     *
     * @return Local|null
     */
    private function origem(array $mov): ?array
    {
        return $this->locais->local(self::id($mov['origem_ambiente_id']), self::id($mov['origem_almoxarifado_id']));
    }

    /**
     * @param array<string, mixed> $mov
     *
     * @return Local|null
     */
    private function destino(array $mov): ?array
    {
        return $this->locais->local(self::id($mov['destino_ambiente_id']), self::id($mov['destino_almoxarifado_id']));
    }

    private static function id(mixed $valor): ?int
    {
        return $valor === null ? null : (int) $valor;
    }
}

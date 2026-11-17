<?php

namespace App\Controllers\Patrimonio;

use App\Libraries\Autorizacao;
use App\Libraries\Patrimonio\Descartes;
use App\Libraries\Patrimonio\Recusa;
use App\Tabelas\Patrimonio\DescartesTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Descartes: lista e página da solicitação, onde quem tem patrimonio.aprovar_descarte no local do item
 * aprova ou rejeita. Aprovar dá baixa no item (soft delete), por isso passa pelo modal CONFIRMO.
 * O pedido nasce na página do item.
 */
class DescartesItens extends ControladorPatrimonio
{
    private const LISTA = 'patrimonio/descartes';

    public function index(): string
    {
        $tabela = $this->tabela(new DescartesTabela($this->escopo()));

        return $this->pedidoParcial() ? $tabela->fragmento() : view('patrimonio/_lista', [
            'tabela' => $tabela,
            'titulo' => lang('Patrimonio.disposalsTitle'),
            'intro'  => lang('Patrimonio.disposalsIntro'),
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela(new DescartesTabela($this->escopo()));
    }

    public function ver(int $id): ResponseInterface|RedirectResponse|string
    {
        [$descarte, $recusa] = $this->carregar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        return view('patrimonio/descartes/ver', [
            'descarte'    => $descarte,
            'local'       => $this->locais->rotulo(
                $descarte['ambiente_id'] === null ? null : (int) $descarte['ambiente_id'],
                $descarte['almoxarifado_id'] === null ? null : (int) $descarte['almoxarifado_id']
            ),
            'podeDecidir' => $descarte['status'] === 'pendente' && $this->podeDecidir($descarte),
        ]);
    }

    public function aprovar(int $id): ResponseInterface|RedirectResponse
    {
        return $this->decidir($id, true);
    }

    public function rejeitar(int $id): ResponseInterface|RedirectResponse
    {
        return $this->decidir($id, false);
    }

    private function decidir(int $id, bool $aprovar): ResponseInterface|RedirectResponse
    {
        [$descarte, $recusa] = $this->carregar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        if (! $this->podeDecidir($descarte)) {
            return $this->negar();
        }

        $pagina = redirect()->to(site_url(self::LISTA . "/{$id}"));

        if ($aprovar && ! $this->exclusaoConfirmada()) {
            return $pagina->with('erro', lang('Ui.confirmMismatch'));
        }

        $descartes = new Descartes(db_connect());

        try {
            $aprovar ? $descartes->aprovar($id, $this->usuarioId()) : $descartes->rejeitar($id, $this->usuarioId());
        } catch (Recusa $recusa) {
            return $pagina->with('erro', lang($recusa->getMessage()));
        }

        return $pagina->with('sucesso', lang($aprovar ? 'Patrimonio.disposalApproved' : 'Patrimonio.disposalRejected'));
    }

    /** @param array<string, mixed> $descarte */
    private function podeDecidir(array $descarte): bool
    {
        return $this->podeNoLocal($this->localDe($descarte), Autorizacao::EDITOR, 'patrimonio.aprovar_descarte');
    }

    /**
     * Solicitação com os dados do item (inclusive já descartado), se o local do item está no escopo.
     *
     * @return array{0: array<string, mixed>, 1: ResponseInterface|RedirectResponse|null}
     */
    private function carregar(int $id): array
    {
        $descarte = db_connect()->table('descartes ds')
            ->select('ds.*, i.numero_patrimonio, i.patrimoniavel, i.nome, i.quantidade')
            ->select('i.ambiente_id, i.almoxarifado_id')
            ->select('i.status AS status_item, mo.nome AS motivo, sol.nome AS solicitante, apr.nome AS aprovador')
            ->join('itens i', 'i.id_item = ds.item_id')
            ->join('motivos_descarte mo', 'mo.id_motivo_descarte = ds.motivo_descarte_id')
            ->join('usuarios sol', 'sol.id_usuario = ds.solicitante_id')
            ->join('usuarios apr', 'apr.id_usuario = ds.aprovador_id', 'left')
            ->where('ds.id_descarte', $id)
            ->where('ds.deletado_em', null)
            ->get()
            ->getRowArray();

        if ($descarte === null) {
            return [[], redirect()->to(site_url(self::LISTA))->with('erro', lang('Patrimonio.disposalNotFound'))];
        }

        return [$descarte, $this->podeNoLocal($this->localDe($descarte)) ? null : $this->negar()];
    }
}

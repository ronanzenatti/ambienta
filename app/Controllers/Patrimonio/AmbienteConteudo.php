<?php

namespace App\Controllers\Patrimonio;

use App\Libraries\Autorizacao;
use App\Libraries\Patrimonio\Locais;
use App\Libraries\Patrimonio\Movimentacoes;
use App\Libraries\Patrimonio\Recusa;
use App\Models\Institucional\AmbienteModel;
use App\Models\Institucional\PredioModel;
use App\Models\Patrimonio\AmbienteSoftwareModel;
use App\Models\Patrimonio\ItemModel;
use App\Tabelas\Patrimonio\AmbienteItensTabela;
use App\Tabelas\Patrimonio\AmbienteSoftwaresTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * O que há dentro de um ambiente, nos dois cartões da página dele: softwares instalados (vínculo direto) e
 * itens patrimoniais (entram e saem por movimentação, com confirmação de quem recebe).
 *
 * Ver os softwares segue a permissão de ver o ambiente; ver os itens exige leitura de patrimônio no prédio;
 * adicionar e remover exige editor de patrimônio no prédio (e, para enviar um item, no local onde ele está).
 */
class AmbienteConteudo extends ControladorPatrimonio
{
    /** @var array<string, mixed> */
    private array $ambiente = [];

    /** @var array<string, mixed> */
    private array $predio = [];

    // --- Softwares ---------------------------------------------------------------------------------------

    public function softwares(int $ambienteId): ResponseInterface|RedirectResponse|string
    {
        $recusa = $this->carregar($ambienteId, 'institucional.acesso', Autorizacao::LEITOR);

        return $recusa ?? $this->fragmento($this->tabelaSoftwares());
    }

    public function exportarSoftwares(int $ambienteId): ResponseInterface|RedirectResponse
    {
        $recusa = $this->carregar($ambienteId, 'institucional.acesso', Autorizacao::LEITOR);

        return $recusa ?? $this->exportarTabela($this->tabelaSoftwares());
    }

    public function novoSoftware(int $ambienteId): ResponseInterface|RedirectResponse|string
    {
        return $this->carregarParaEditar($ambienteId) ?? $this->telaVinculo('softwares');
    }

    public function vincularSoftwares(int $ambienteId): ResponseInterface|RedirectResponse|string
    {
        $recusa = $this->carregarParaEditar($ambienteId);

        if ($recusa !== null) {
            return $recusa;
        }

        $ids   = $this->idsPostados('software_ids');
        $fonte = service('opcoes')->softwares($ambienteId);
        $erro  = null;

        if ($ids === []) {
            $erro = lang('Patrimonio.softwaresNoneSelected');
        } elseif (array_filter($ids, static fn (int $id): bool => ! $fonte->contem($id)) !== []) {
            $erro = lang('Patrimonio.softwareInvalid');
        }

        if ($erro !== null) {
            return $this->telaVinculo('softwares', ['software_ids' => $erro], 422);
        }

        $vinculos = new AmbienteSoftwareModel();

        foreach ($ids as $softwareId) {
            $vinculos->insert(['ambiente_id' => $ambienteId, 'software_id' => $softwareId]);
        }

        return $this->salvo(
            $this->urlAmbiente($ambienteId, 'softwares'),
            lang('Patrimonio.softwaresLinked', [count($ids)])
        );
    }

    public function desvincularSoftware(int $ambienteId, int $vinculoId): ResponseInterface|RedirectResponse
    {
        $recusa = $this->carregarParaEditar($ambienteId);

        if ($recusa !== null) {
            return $recusa;
        }

        $pagina   = redirect()->to($this->urlAmbiente($ambienteId, 'softwares'));
        $vinculos = new AmbienteSoftwareModel();
        $vinculo  = $vinculos->find($vinculoId);

        if ($vinculo === null || (int) $vinculo['ambiente_id'] !== $ambienteId) {
            return $pagina->with('erro', lang('Patrimonio.softwareLinkNotFound'));
        }

        if (! $this->exclusaoConfirmada()) {
            return $pagina->with('erro', lang('Ui.confirmMismatch'));
        }

        $vinculos->delete($vinculoId);

        return $pagina->with('sucesso', lang('Patrimonio.softwareUnlinked'));
    }

    // --- Itens patrimoniais ------------------------------------------------------------------------------

    public function itens(int $ambienteId): ResponseInterface|RedirectResponse|string
    {
        $recusa = $this->carregar($ambienteId, self::CHAVE, Autorizacao::LEITOR);

        return $recusa ?? $this->fragmento($this->tabelaItens());
    }

    public function exportarItens(int $ambienteId): ResponseInterface|RedirectResponse
    {
        $recusa = $this->carregar($ambienteId, self::CHAVE, Autorizacao::LEITOR);

        return $recusa ?? $this->exportarTabela($this->tabelaItens());
    }

    public function novoItem(int $ambienteId): ResponseInterface|RedirectResponse|string
    {
        return $this->carregarParaEditar($ambienteId) ?? $this->telaVinculo('itens');
    }

    /** Um envio (movimentação) por item escolhido; cada um só entra no ambiente quando quem recebe confirmar. */
    public function adicionarItens(int $ambienteId): ResponseInterface|RedirectResponse|string
    {
        $recusa = $this->carregarParaEditar($ambienteId);

        if ($recusa !== null) {
            return $recusa;
        }

        $ids          = $this->idsPostados('item_ids');
        $destinatario = $this->texto('destinatario_id');
        $erros        = [];
        $fonte        = service('opcoes')->itensParaAmbiente(
            $ambienteId,
            $this->autorizacao->escopoPara(self::CHAVE, Autorizacao::EDITOR)
        );

        if ($ids === []) {
            $erros['item_ids'] = lang('Patrimonio.itemsNoneSelected');
        } elseif (array_filter($ids, static fn (int $id): bool => ! $fonte->contem($id)) !== []) {
            $erros['item_ids'] = lang('Patrimonio.itemInvalid');
        }

        $destino = $this->locais->local($ambienteId, null);

        if (! ctype_digit($destinatario) || $destino === null || ! $this->podeReceber((int) $destinatario, $destino)) {
            $erros['destinatario_id'] = lang('Patrimonio.recipientInvalid');
        }

        if ($erros !== []) {
            return $this->telaVinculo('itens', $erros, 422, ['destinatario_id' => $destinatario]);
        }

        $enviados = 0;
        $recusados = [];
        $movimentacoes = new Movimentacoes(db_connect());

        foreach ($ids as $itemId) {
            try {
                $movimentacoes->enviar($itemId, $destino, $this->usuarioId(), (int) $destinatario);
                $enviados++;
            } catch (Recusa $falha) {
                $recusados[] = lang($falha->getMessage());
            }
        }

        if ($enviados === 0) {
            return $this->telaVinculo('itens', ['item_ids' => implode(' ', array_unique($recusados))], 422, [
                'destinatario_id' => $destinatario,
            ]);
        }

        return $this->salvo(
            $this->urlAmbiente($ambienteId, 'itens'),
            lang('Patrimonio.itemsSentToEnvironment', [$enviados])
        );
    }

    /** Devolver ao almoxarifado do campus: movimentação do item para lá (o usuário confirma, se puder). */
    public function devolverItem(int $ambienteId, int $itemId): ResponseInterface|RedirectResponse
    {
        $recusa = $this->carregarParaEditar($ambienteId);

        if ($recusa !== null) {
            return $recusa;
        }

        $pagina = redirect()->to($this->urlAmbiente($ambienteId, 'itens'));
        $item   = (new ItemModel())->find($itemId);

        if ($item === null || (int) $item['ambiente_id'] !== $ambienteId) {
            return $pagina->with('erro', lang('Patrimonio.itemNotFound'));
        }

        if (! $this->exclusaoConfirmada()) {
            return $pagina->with('erro', lang('Ui.confirmMismatch'));
        }

        if (ItemModel::pendente($item)) {
            return $pagina->with('erro', lang('Patrimonio.moveItemPending'));
        }

        $almoxarifado = db_connect()->table('almoxarifados')
            ->select('id_almoxarifado')
            ->where('campus_id', (int) $this->predio['campus_id'])
            ->where('deletado_em', null)
            ->get()
            ->getRowArray();
        $destino = $almoxarifado === null ? null : $this->locais->local(null, (int) $almoxarifado['id_almoxarifado']);

        if ($destino === null) {
            return $pagina->with('erro', lang('Patrimonio.noWarehouse'));
        }

        if (! $this->podeReceber($this->usuarioId(), $destino)) {
            return $pagina->with('erro', lang('Patrimonio.returnNeedsRecipient'));
        }

        try {
            (new Movimentacoes(db_connect()))->enviar($itemId, $destino, $this->usuarioId(), $this->usuarioId());
        } catch (Recusa $recusa) {
            return $pagina->with('erro', lang($recusa->getMessage()));
        }

        return $pagina->with('sucesso', lang('Patrimonio.itemReturned'));
    }

    // --- Apoio -------------------------------------------------------------------------------------------

    private function tabelaSoftwares(): AmbienteSoftwaresTabela
    {
        return $this->tabela(new AmbienteSoftwaresTabela(
            (int) $this->ambiente['id_ambiente'],
            (string) $this->ambiente['nome'],
            $this->podeEditarNoPredio()
        ));
    }

    private function tabelaItens(): AmbienteItensTabela
    {
        return $this->tabela(
            new AmbienteItensTabela((int) $this->ambiente['id_ambiente'], $this->podeEditarNoPredio())
        );
    }

    /** Região trocada da tabela (?parcial=1); sem JavaScript, a página do ambiente. */
    private function fragmento(AmbienteSoftwaresTabela|AmbienteItensTabela $tabela): RedirectResponse|string
    {
        return $this->pedidoParcial()
            ? $tabela->fragmento()
            : redirect()->to(site_url('ambientes/' . $this->ambiente['id_ambiente']));
    }

    /**
     * Carrega ambiente e prédio e confere a permissão neles. null = pode; senão, a resposta de recusa.
     */
    private function carregar(int $ambienteId, string $chave, string $nivel): ResponseInterface|RedirectResponse|null
    {
        $ambiente = (new AmbienteModel())->find($ambienteId);
        $predio   = $ambiente === null ? null : (new PredioModel())->find((int) $ambiente['predio_id']);

        if ($ambiente === null || $predio === null) {
            return redirect()->to(site_url('campus'))->with('erro', lang('Ambientes.notFound'));
        }

        $this->ambiente = $ambiente;
        $this->predio   = $predio;

        return $this->autorizacao->pode($chave, $nivel, (int) $predio['campus_id'], (int) $predio['id_predio'])
            ? null
            : $this->negar();
    }

    private function carregarParaEditar(int $ambienteId): ResponseInterface|RedirectResponse|null
    {
        return $this->carregar($ambienteId, self::CHAVE, Autorizacao::EDITOR);
    }

    private function podeEditarNoPredio(): bool
    {
        return $this->autorizacao->pode(
            self::CHAVE,
            Autorizacao::EDITOR,
            (int) $this->predio['campus_id'],
            (int) $this->predio['id_predio']
        );
    }

    /** @return list<int> ids numéricos, sem repetição, do campo múltiplo postado */
    private function idsPostados(string $campo): array
    {
        $valores = $this->request->getPost($campo);
        $valores = is_array($valores) ? $valores : [];

        return array_values(array_unique(array_map(
            'intval',
            array_filter($valores, static fn ($v): bool => is_string($v) && ctype_digit($v) && $v !== '0')
        )));
    }

    /** Destino do redirecionamento (fora do modal): a página do ambiente, no cartão certo. */
    private function urlAmbiente(int $ambienteId, string $ancora): string
    {
        return site_url("ambientes/{$ambienteId}") . '#ambiente-' . $ancora;
    }

    /**
     * Formulário de adicionar (modal ou página).
     *
     * @param array<string, string> $erros
     * @param array<string, mixed>  $dados
     */
    private function telaVinculo(
        string $tipo,
        array $erros = [],
        int $status = 200,
        array $dados = []
    ): ResponseInterface|string {
        return $this->telaFormulario('patrimonio/ambiente_vinculo', [
            'tipo'     => $tipo,
            'ambiente' => $this->ambiente,
            'predio'   => $this->predio,
            'dados'    => $dados + ['destinatario_id' => $this->usuarioId()],
        ], $erros, $status);
    }
}

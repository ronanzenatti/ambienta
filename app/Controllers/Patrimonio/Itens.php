<?php

namespace App\Controllers\Patrimonio;

use App\Libraries\Autorizacao;
use App\Libraries\Patrimonio\Descartes;
use App\Libraries\Patrimonio\GestorItens;
use App\Libraries\Patrimonio\Movimentacoes;
use App\Libraries\Patrimonio\Recusa;
use App\Models\Patrimonio\CategoriaItemModel;
use App\Models\Patrimonio\ItemModel;
use App\Models\Patrimonio\ModeloItemModel;
use App\Tabelas\Patrimonio\ItensTabela;
use App\Tabelas\Patrimonio\MovimentacoesTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Itens: listagem, cadastro, página do item (com o histórico de movimentações), dados próprios (nome, marca,
 * especificações, categoria, número de patrimônio), envio (movimentação) e pedido de descarte.
 *
 * Item patrimonial = um registro por número de patrimônio. Sem número, o patrimoniável fica pendente: não pode
 * ser movido (nem alocado, nem contado como disponível). Item sem patrimônio (marcador, apagador) é controlado
 * por quantidade. O item só sai da lista pela aprovação do descarte.
 */
class Itens extends ControladorPatrimonio
{
    private const LISTA = 'patrimonio/itens';

    /** Teto de quantidade de um item sem patrimônio (INT UNSIGNED com folga). */
    private const QUANTIDADE_MAXIMA = 1000000;

    private ItemModel $itens;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);

        $this->itens = new ItemModel();
    }

    public function index(): string
    {
        $tabela = $this->tabela(new ItensTabela($this->escopo()));
        $editor = $this->autorizacao->pode(self::CHAVE, Autorizacao::EDITOR);

        return $this->pedidoParcial() ? $tabela->fragmento() : view('patrimonio/_lista', [
            'tabela' => $tabela,
            'titulo' => lang('Patrimonio.itemsTitle'),
            'intro'  => lang('Patrimonio.itemsIntro'),
            'antes'  => item_aviso_pendente(),
            'novo'   => $editor ? [lang('Patrimonio.itemNew'), site_url(self::LISTA . '/novo')] : null,
            'extra'  => $editor
                ? ui_button(lang('Patrimonio.numbersTitle'), 'secondary', ['href' => site_url('patrimonio/numeros')])
                : null,
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela(new ItensTabela($this->escopo()));
    }

    public function novo(): ResponseInterface|RedirectResponse|string
    {
        if (! $this->podeEditarCatalogo()) {
            return $this->negar();
        }

        return $this->formulario(['patrimoniavel' => 1, 'quantidade' => 1]);
    }

    public function criar(): ResponseInterface|RedirectResponse|string
    {
        if (! $this->podeEditarCatalogo()) {
            return $this->negar();
        }

        [$dados, $erros] = $this->dadosPostados(null);
        $local           = $this->locais->interpretar($this->texto('local'));

        if ($local === null || ! $this->podeNoLocal($local, Autorizacao::EDITOR)) {
            $erros['local'] = lang('Patrimonio.locationInvalid');
        }

        if ($erros !== [] || $local === null) {
            return $this->formulario($dados + ['local' => $this->texto('local')], $erros, 422);
        }

        $db = db_connect();
        $db->transStart();
        $ids = (new GestorItens($db))->criar(
            [
                'modelo_item_id' => null,
            ] + array_intersect_key($dados, array_flip(GestorItens::MOLDE)),
            $local,
            (int) $dados['quantidade'],
            $dados['numero_patrimonio']
        );
        $db->transComplete();

        return redirect()->to(site_url(self::LISTA . '/' . $ids[0]))->with('sucesso', lang('Patrimonio.itemSaved'));
    }

    public function ver(int $id): ResponseInterface|RedirectResponse|string
    {
        // Descartado continua consultável (histórico), por isso withDeleted.
        $item  = $this->itens->withDeleted()->find($id);
        $local = $item === null ? null : $this->localDe($item);

        if ($item === null) {
            return redirect()->to(site_url(self::LISTA))->with('erro', lang('Patrimonio.itemNotFound'));
        }

        if (! $this->podeNoLocal($local)) {
            return $this->negar();
        }

        $tabela = $this->tabela(new MovimentacoesTabela($this->escopo(), $this->usuarioId(), itemId: $id));

        if ($this->pedidoParcial()) {
            return $tabela->fragmento();
        }

        $ativo = $item['deletado_em'] === null;

        return view('patrimonio/itens/ver', [
            'item'       => $item,
            'modelo'     => $item['modelo_item_id'] === null
                ? null
                : (new ModeloItemModel())->withDeleted()->find((int) $item['modelo_item_id']),
            'categoria'  => (new CategoriaItemModel())->withDeleted()->find((int) $item['categoria_item_id']),
            'local'      => $this->locais->rotulo(
                $item['ambiente_id'] === null ? null : (int) $item['ambiente_id'],
                $item['almoxarifado_id'] === null ? null : (int) $item['almoxarifado_id']
            ),
            'tabela'     => $tabela,
            'podeEditar' => $ativo && $this->podeNoLocal($local, Autorizacao::EDITOR),
            'descarte'   => (new Descartes(db_connect()))->pendente($id),
        ]);
    }

    public function exportarMovimentacoes(int $id): ResponseInterface|RedirectResponse
    {
        $item = $this->itens->withDeleted()->find($id);

        if ($item === null) {
            return redirect()->to(site_url(self::LISTA))->with('erro', lang('Patrimonio.itemNotFound'));
        }

        if (! $this->podeNoLocal($this->localDe($item))) {
            return $this->negar();
        }

        return $this->exportarTabela(new MovimentacoesTabela($this->escopo(), $this->usuarioId(), itemId: $id));
    }

    public function editar(int $id): ResponseInterface|RedirectResponse|string
    {
        [$item, $recusa] = $this->carregarParaEditar($id);

        return $recusa ?? $this->formulario($item);
    }

    /** Dados do próprio item (o modelo é só o molde de onde veio) e o número, único para sempre. */
    public function atualizar(int $id): ResponseInterface|RedirectResponse|string
    {
        [$item, $recusa] = $this->carregarParaEditar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        [$dados, $erros] = $this->dadosPostados($item);

        if ($erros !== []) {
            return $this->formulario($dados + ['id_item' => $id] + $item, $erros, 422);
        }

        $this->itens->update($id, $dados);

        return redirect()->to(site_url(self::LISTA . "/{$id}"))->with('sucesso', lang('Patrimonio.itemSaved'));
    }

    public function movimentar(int $id): ResponseInterface|RedirectResponse|string
    {
        [$item, $recusa] = $this->carregarParaEditar($id);

        return $recusa ?? $this->telaMovimentar($item);
    }

    public function enviar(int $id): ResponseInterface|RedirectResponse|string
    {
        [$item, $recusa] = $this->carregarParaEditar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $dados = [
            'destino'         => $this->texto('destino'),
            'destinatario_id' => $this->texto('destinatario_id'),
            'quantidade'      => $this->texto('quantidade'),
        ];
        $erros = $this->validar($dados, [
            'destino'         => $this->regra('Patrimonio.fieldDestination', 'required'),
            'destinatario_id' => $this->regra('Patrimonio.fieldRecipient', 'required|is_natural_no_zero'),
        ]);

        $quantidade = $this->quantidadeDoItem($item, $dados['quantidade'], $erros);
        $destino    = $this->locais->interpretar($dados['destino']);

        if (! isset($erros['destino']) && $destino === null) {
            $erros['destino'] = lang('Patrimonio.locationInvalid');
        }

        // Quem recebe precisa poder confirmar no destino; senão o item ficaria em trânsito para sempre.
        if (
            ! isset($erros['destinatario_id']) && $destino !== null
            && ! $this->podeReceber((int) $dados['destinatario_id'], $destino)
        ) {
            $erros['destinatario_id'] = lang('Patrimonio.recipientInvalid');
        }

        if ($erros !== [] || $destino === null) {
            return $this->telaMovimentar($item, $dados, $erros, 422);
        }

        try {
            $movId = (new Movimentacoes(db_connect()))
                ->enviar($id, $destino, $this->usuarioId(), (int) $dados['destinatario_id'], $quantidade);
        } catch (Recusa $recusa) {
            return $this->telaMovimentar($item, $dados, ['destino' => lang($recusa->getMessage())], 422);
        }

        return redirect()->to(site_url("patrimonio/movimentacoes/{$movId}"))
            ->with('sucesso', lang('Patrimonio.movementSent'));
    }

    public function descartar(int $id): ResponseInterface|RedirectResponse|string
    {
        [$item, $recusa] = $this->carregarParaEditar($id);

        return $recusa ?? $this->telaDescarte($item);
    }

    public function solicitarDescarte(int $id): ResponseInterface|RedirectResponse|string
    {
        [$item, $recusa] = $this->carregarParaEditar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $dados = [
            'motivo_descarte_id' => $this->texto('motivo_descarte_id'),
            'justificativa'      => $this->textoOuNulo('justificativa'),
            'quantidade'         => $this->texto('quantidade'),
        ];
        $erros = $this->validar($dados, [
            'motivo_descarte_id' => $this->regra('Patrimonio.fieldReason', 'required|is_natural_no_zero'),
            'justificativa'      => $this->regra('Patrimonio.fieldJustification', 'permit_empty|max_length[5000]'),
        ]);

        $quantidade   = $this->quantidadeDoItem($item, $dados['quantidade'], $erros);
        $motivoValido = service('opcoes')->simples('motivos_descarte', 'id_motivo_descarte')
            ->contem((int) $dados['motivo_descarte_id']);

        if (! isset($erros['motivo_descarte_id']) && ! $motivoValido) {
            $erros['motivo_descarte_id'] = lang('Patrimonio.reasonInvalid');
        }

        if ($erros !== []) {
            return $this->telaDescarte($item, $dados, $erros, 422);
        }

        try {
            $descarteId = (new Descartes(db_connect()))->solicitar(
                $id,
                (int) $dados['motivo_descarte_id'],
                $dados['justificativa'],
                $this->usuarioId(),
                $quantidade
            );
        } catch (Recusa $recusa) {
            return redirect()->to(site_url(self::LISTA . "/{$id}"))->with('erro', lang($recusa->getMessage()));
        }

        return redirect()->to(site_url("patrimonio/descartes/{$descarteId}"))
            ->with('sucesso', lang('Patrimonio.disposalRequested'));
    }

    /**
     * Item ativo em que o usuário é editor de patrimônio (no local onde está), e a recusa, se houver.
     *
     * @return array{0: array<string, mixed>, 1: ResponseInterface|RedirectResponse|null}
     */
    private function carregarParaEditar(int $id): array
    {
        $item = $this->itens->find($id);

        if ($item === null) {
            return [[], redirect()->to(site_url(self::LISTA))->with('erro', lang('Patrimonio.itemNotFound'))];
        }

        return [$item, $this->podeNoLocal($this->localDe($item), Autorizacao::EDITOR) ? null : $this->negar()];
    }

    /**
     * Quantidade a mover ou baixar: só para item sem patrimônio com mais de uma unidade (vazio = tudo). Item
     * patrimonial é sempre uma unidade. Erro de validação entra em $erros['quantidade'].
     *
     * @param array<string, mixed>  $item
     * @param array<string, list<string>|string> $erros
     */
    private function quantidadeDoItem(array $item, string $texto, array &$erros): ?int
    {
        if ((bool) $item['patrimoniavel'] || (int) $item['quantidade'] <= 1 || $texto === '') {
            return null;
        }

        if (! ctype_digit($texto) || (int) $texto < 1 || (int) $texto > (int) $item['quantidade']) {
            $erros['quantidade'] = lang('Patrimonio.quantityInvalid');

            return null;
        }

        return (int) $texto;
    }

    /**
     * Dados do formulário de item. $atual null = cadastro; senão a alteração (número só entra enquanto o item
     * é patrimoniável; quem já tem número continua patrimonial e não o perde).
     *
     * @param array<string, mixed>|null $atual
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function dadosPostados(?array $atual): array
    {
        $temNumero     = $atual !== null && $atual['numero_patrimonio'] !== null;
        $patrimoniavel = $temNumero || $this->request->getPost('patrimoniavel') === '1';
        $numero        = $patrimoniavel ? $this->textoOuNulo('numero_patrimonio') : null;

        $dados = [
            'categoria_item_id' => $this->texto('categoria_item_id'),
            'nome'              => $this->texto('nome'),
            'marca'             => $this->textoOuNulo('marca'),
            'especificacoes'    => $this->textoOuNulo('especificacoes'),
            'patrimoniavel'     => (int) $patrimoniavel,
            'numero_patrimonio' => $numero,
            'quantidade'        => $patrimoniavel ? '1' : $this->texto('quantidade'),
        ];

        $erros = $this->validar($dados, [
            'categoria_item_id' => $this->regra('Patrimonio.fieldCategory', 'required|is_natural_no_zero'),
            'nome'              => $this->regra('Institucional.fieldName', 'required|max_length[255]'),
            'marca'             => $this->regra('Patrimonio.fieldBrand', 'permit_empty|max_length[100]'),
            'especificacoes'    => $this->regra('Patrimonio.fieldSpecs', 'permit_empty|max_length[5000]'),
            'numero_patrimonio' => $this->regra('Patrimonio.fieldNumber', 'permit_empty|max_length[30]'),
            'quantidade'        => $this->regra(
                'Patrimonio.fieldQuantity',
                'required|is_natural_no_zero|less_than_equal_to[' . self::QUANTIDADE_MAXIMA . ']'
            ),
        ]);

        if (
            ! isset($erros['categoria_item_id'])
            && (new CategoriaItemModel())->find((int) $dados['categoria_item_id']) === null
        ) {
            $erros['categoria_item_id'] = lang('Patrimonio.categoryInvalid');
        }

        $idAtual = $atual === null ? null : (int) $atual['id_item'];

        if (! isset($erros['numero_patrimonio']) && $numero !== null && $this->itens->numeroEmUso($numero, $idAtual)) {
            $erros['numero_patrimonio'] = lang('Patrimonio.numberInUse');
        }

        // O número, uma vez dado, não é retirado: o item continua patrimonial.
        if ($temNumero && $numero === null) {
            $erros['numero_patrimonio'] = lang('Patrimonio.numberRequired');
        }

        if (
            $atual !== null && $atual['status'] === 'em_transito'
            && (int) $dados['quantidade'] !== (int) $atual['quantidade']
        ) {
            $erros['quantidade'] = lang('Patrimonio.quantityInTransit');
        }

        // Patrimonial é um registro por número: não vira patrimoniável um item com várias unidades.
        if ($atual !== null && $patrimoniavel && ! (bool) $atual['patrimoniavel'] && (int) $atual['quantidade'] > 1) {
            $erros['patrimoniavel'] = lang('Patrimonio.assetNeedsSingle');
        }

        $dados['quantidade'] = (int) $dados['quantidade'];

        return [$dados, $erros];
    }

    /**
     * @param array<string, mixed>  $item
     * @param array<string, string> $erros
     */
    private function formulario(array $item, array $erros = [], int $status = 200): ResponseInterface|string
    {
        return $this->responder('patrimonio/itens/form', [
            'item'          => $item,
            'temCategorias' => service('opcoes')->simples('categorias_itens', 'id_categoria_item')->existe(),
        ], $erros, $status);
    }

    /**
     * @param array<string, mixed>  $item
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    private function telaMovimentar(
        array $item,
        array $dados = [],
        array $erros = [],
        int $status = 200
    ): ResponseInterface|string {
        $origem = [
            'ambiente'     => $item['ambiente_id'] === null ? null : 'ambiente:' . $item['ambiente_id'],
            'almoxarifado' => $item['almoxarifado_id'] === null ? null : 'almoxarifado:' . $item['almoxarifado_id'],
        ];

        return $this->responder('patrimonio/itens/movimentar', [
            'item'       => $item,
            'origem'     => $this->locais->rotulo(
                $item['ambiente_id'] === null ? null : (int) $item['ambiente_id'],
                $item['almoxarifado_id'] === null ? null : (int) $item['almoxarifado_id']
            ),
            // Destino: qualquer local ativo (transferência entre campi), menos onde o item já está.
            'exceto'     => array_values(array_filter($origem)),
            'dados'      => $dados,
            'disponivel' => in_array($item['status'], ['em_uso', 'almoxarifado'], true),
        ], $erros, $status);
    }

    /**
     * @param array<string, mixed>  $item
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    private function telaDescarte(
        array $item,
        array $dados = [],
        array $erros = [],
        int $status = 200
    ): ResponseInterface|string {
        return $this->responder('patrimonio/itens/descartar', [
            'item'  => $item,
            'dados' => $dados,
        ], $erros, $status);
    }
}

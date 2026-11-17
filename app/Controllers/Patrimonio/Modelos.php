<?php

namespace App\Controllers\Patrimonio;

use App\Libraries\Arquivos;
use App\Libraries\Autorizacao;
use App\Libraries\Patrimonio\GestorItens;
use App\Models\Patrimonio\CategoriaItemModel;
use App\Models\Patrimonio\ModeloItemModel;
use App\Tabelas\Patrimonio\ItensTabela;
use App\Tabelas\Patrimonio\ModelosTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Patrimonio;
use Psr\Log\LoggerInterface;

/**
 * Modelos de item (catálogo global, com foto: formulário em página própria). O modelo é um molde: na página dele,
 * a replicação cria itens num local copiando os dados do modelo. Patrimoniável gera um item por unidade, sem
 * número (aguardando número, preenchido depois um a um ou pela planilha); sem patrimônio soma a quantidade ao
 * item igual do local. Depois de criado, o item segue por conta própria (não acompanha mudanças do modelo).
 */
class Modelos extends ControladorPatrimonio
{
    private const LISTA    = 'patrimonio/modelos';
    private const ARQUIVOS = ['foto' => Arquivos::IMAGEM];

    private ModeloItemModel $modelos;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);

        $this->modelos = new ModeloItemModel();
    }

    public function index(): string
    {
        $tabela = $this->tabela(new ModelosTabela($this->podeEditarCatalogo()));

        return $this->pedidoParcial() ? $tabela->fragmento() : view('patrimonio/_lista', [
            'tabela' => $tabela,
            'titulo' => lang('Patrimonio.modelsTitle'),
            'intro'  => lang('Patrimonio.modelsIntro'),
            'novo'   => $this->podeEditarCatalogo()
                ? [lang('Patrimonio.modelNew'), site_url(self::LISTA . '/novo')]
                : null,
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela(new ModelosTabela($this->podeEditarCatalogo()));
    }

    public function ver(int $id): ResponseInterface|RedirectResponse|string
    {
        return $this->pagina($id);
    }

    /**
     * Página do modelo: dados, replicação e as unidades (ou o estoque) do modelo.
     *
     * @param array<string, string> $erros erros da replicação
     */
    private function pagina(int $id, array $erros = [], int $status = 200): ResponseInterface|RedirectResponse|string
    {
        $modelo = $this->modelos->find($id);

        if ($modelo === null) {
            return redirect()->to(site_url(self::LISTA))->with('erro', lang('Patrimonio.modelNotFound'));
        }

        $tabela = $this->tabela($this->tabelaUnidades($modelo));

        if ($this->pedidoParcial()) {
            return $tabela->fragmento();
        }

        return $this->responder('patrimonio/modelos/ver', [
            'modelo'     => $modelo,
            'categoria'  => (new CategoriaItemModel())->withDeleted()->find((int) $modelo['categoria_item_id']),
            'tabela'     => $tabela,
            'podeEditar' => $this->podeEditarCatalogo(),
            'temLocais'  => service('opcoes')->locais($this->escopo(Autorizacao::EDITOR))->existe(),
            'replicacao' => $this->request->getPost() ?: [],
            'limite'     => config(Patrimonio::class)->limiteReplicacao,
        ], $erros, $status);
    }

    public function exportarUnidades(int $id): ResponseInterface|RedirectResponse
    {
        $modelo = $this->modelos->find($id);

        if ($modelo === null) {
            return redirect()->to(site_url(self::LISTA))->with('erro', lang('Patrimonio.modelNotFound'));
        }

        return $this->exportarTabela($this->tabelaUnidades($modelo));
    }

    public function novo(): ResponseInterface|string
    {
        return $this->formulario(['patrimoniavel' => 1]);
    }

    public function criar(): ResponseInterface|RedirectResponse|string
    {
        [$dados, $erros] = $this->dadosPostados([], null);

        if ($erros !== []) {
            return $this->formulario($dados, $erros, 422);
        }

        $id = $this->modelos->insert($dados);

        return redirect()->to(site_url(self::LISTA . "/{$id}"))->with('sucesso', lang('Patrimonio.modelSaved'));
    }

    public function editar(int $id): ResponseInterface|RedirectResponse|string
    {
        $modelo = $this->modelos->find($id);

        if ($modelo === null) {
            return redirect()->to(site_url(self::LISTA))->with('erro', lang('Patrimonio.modelNotFound'));
        }

        return $this->formulario($modelo);
    }

    public function atualizar(int $id): ResponseInterface|RedirectResponse|string
    {
        $modelo = $this->modelos->find($id);

        if ($modelo === null) {
            return redirect()->to(site_url(self::LISTA))->with('erro', lang('Patrimonio.modelNotFound'));
        }

        [$dados, $erros, $antigos] = $this->dadosPostados($modelo, $id);

        if ($erros !== []) {
            return $this->formulario($dados + ['id_modelo_item' => $id], $erros, 422);
        }

        $this->modelos->update($id, $dados);
        $this->apagarArquivos($antigos);

        return redirect()->to(site_url(self::LISTA . "/{$id}"))->with('sucesso', lang('Patrimonio.modelSaved'));
    }

    public function excluir(int $id): RedirectResponse
    {
        $modelo = $this->modelos->find($id);

        if ($modelo === null) {
            return redirect()->to(site_url(self::LISTA))->with('erro', lang('Patrimonio.modelNotFound'));
        }

        if (! $this->exclusaoConfirmada()) {
            return redirect()->to(site_url(self::LISTA . "/{$id}"))->with('erro', lang('Ui.confirmMismatch'));
        }

        $dependentes = $this->modelos->dependentes($id);

        if ($dependentes !== []) {
            return redirect()->to(site_url(self::LISTA . "/{$id}"))
                ->with('erro', $this->mensagemDependentes($dependentes));
        }

        $this->modelos->delete($id);

        return redirect()->to(site_url(self::LISTA))->with('sucesso', lang('Patrimonio.modelDeleted'));
    }

    /** Gera N unidades do modelo num local em que o usuário é editor de patrimônio. */
    public function replicar(int $id): ResponseInterface|RedirectResponse|string
    {
        $modelo = $this->modelos->find($id);

        if ($modelo === null) {
            return redirect()->to(site_url(self::LISTA))->with('erro', lang('Patrimonio.modelNotFound'));
        }

        $limite = config(Patrimonio::class)->limiteReplicacao;
        $dados  = ['quantidade' => $this->texto('quantidade'), 'local' => $this->texto('local')];
        $erros  = $this->validar($dados, [
            'quantidade' => $this->regra(
                'Patrimonio.fieldQuantity',
                "required|is_natural_no_zero|less_than_equal_to[{$limite}]"
            ),
            'local'      => $this->regra('Patrimonio.fieldLocation', 'required'),
        ]);

        $local = $this->locais->interpretar($dados['local']);

        if (! isset($erros['local']) && ! $this->podeNoLocal($local, Autorizacao::EDITOR)) {
            $erros['local'] = lang('Patrimonio.locationInvalid');
        }

        if ($erros !== [] || $local === null) {
            return $this->pagina($id, $erros, 422);
        }

        $quantidade = (int) $dados['quantidade'];
        $db         = db_connect();

        $db->transStart();
        (new GestorItens($db))->criar(GestorItens::moldeDoModelo($modelo), $local, $quantidade);
        $db->transComplete();

        return redirect()->to(site_url(self::LISTA . "/{$id}"))
            ->with('sucesso', lang('Patrimonio.modelReplicated', [$quantidade]));
    }
    /** @param array<string, mixed> $modelo */
    private function tabelaUnidades(array $modelo): ItensTabela
    {
        return new ItensTabela($this->escopo(), (int) $modelo['id_modelo_item']);
    }
    /**
     * @param array<string, mixed> $atual
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: list<?string>}
     */
    private function dadosPostados(array $atual, ?int $id): array
    {
        $dados = [
            'categoria_item_id' => $this->texto('categoria_item_id'),
            'nome'              => $this->texto('nome'),
            'marca'             => $this->textoOuNulo('marca'),
            'especificacoes'    => $this->textoOuNulo('especificacoes'),
            'patrimoniavel'     => $this->request->getPost('patrimoniavel') === '1' ? 1 : 0,
        ];

        $erros = $this->validar($dados, [
            'categoria_item_id' => $this->regra('Patrimonio.fieldCategory', 'required|is_natural_no_zero'),
            'nome'              => $this->regra('Institucional.fieldName', 'required|max_length[255]'),
            'marca'             => $this->regra('Patrimonio.fieldBrand', 'permit_empty|max_length[100]'),
            'especificacoes'    => $this->regra('Patrimonio.fieldSpecs', 'permit_empty|max_length[5000]'),
        ]);

        if (
            ! isset($erros['categoria_item_id'])
            && (new CategoriaItemModel())->find((int) $dados['categoria_item_id']) === null
        ) {
            $erros['categoria_item_id'] = lang('Patrimonio.categoryInvalid');
        }

        $dados['foto'] = $atual['foto'] ?? null;

        if ($erros !== []) {
            return [$dados, $erros, []];
        }

        [$arquivos, $antigos] = $this->arquivosPostados(self::ARQUIVOS, 'modelos', $atual, $erros);

        return [$arquivos + $dados, $erros, $antigos];
    }

    /**
     * @param array<string, mixed>  $modelo
     * @param array<string, string> $erros
     */
    private function formulario(array $modelo, array $erros = [], int $status = 200): ResponseInterface|string
    {
        return $this->responder('patrimonio/modelos/form', [
            'modelo'     => $modelo,
            'temCategorias' => service('opcoes')->simples('categorias_itens', 'id_categoria_item')->existe(),
        ], $erros, $status);
    }
}

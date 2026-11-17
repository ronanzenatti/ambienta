<?php

namespace App\Controllers\Patrimonio;

use App\Models\Patrimonio\CategoriaItemModel;
use App\Tabelas\Patrimonio\CategoriasItensTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/** Categorias de item (catálogo global). Nome único entre as ativas (nome_unico no schema). */
class CategoriasItens extends ControladorPatrimonio
{
    private const LISTA = 'patrimonio/categorias';

    private CategoriaItemModel $categorias;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);

        $this->categorias = new CategoriaItemModel();
    }

    public function index(): string
    {
        $tabela = $this->tabela(new CategoriasItensTabela($this->podeEditarCatalogo()));

        return $this->pedidoParcial() ? $tabela->fragmento() : view('patrimonio/_lista', [
            'tabela' => $tabela,
            'titulo' => lang('Patrimonio.categoriesTitle'),
            'intro'  => lang('Patrimonio.categoriesIntro'),
            'novo'   => $this->podeEditarCatalogo()
                ? [lang('Patrimonio.categoryNew'), site_url(self::LISTA . '/novo')]
                : null,
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela(new CategoriasItensTabela($this->podeEditarCatalogo()));
    }

    public function novo(): ResponseInterface|string
    {
        return $this->telaFormulario(self::LISTA, ['categoria' => []]);
    }

    public function criar(): ResponseInterface|RedirectResponse|string
    {
        $dados = $this->dadosPostados();
        $erros = $this->validarCategoria($dados, null);

        if ($erros !== []) {
            return $this->telaFormulario(self::LISTA, ['categoria' => $dados], $erros, 422);
        }

        $this->categorias->insert($dados);

        return $this->salvo(site_url(self::LISTA), lang('Patrimonio.categorySaved'));
    }

    public function editar(int $id): ResponseInterface|RedirectResponse|string
    {
        $categoria = $this->categorias->find($id);

        if ($categoria === null) {
            return redirect()->to(site_url(self::LISTA))->with('erro', lang('Patrimonio.categoryNotFound'));
        }

        return $this->telaFormulario(self::LISTA, ['categoria' => $categoria]);
    }

    public function atualizar(int $id): ResponseInterface|RedirectResponse|string
    {
        if ($this->categorias->find($id) === null) {
            return redirect()->to(site_url(self::LISTA))->with('erro', lang('Patrimonio.categoryNotFound'));
        }

        $dados = $this->dadosPostados();
        $erros = $this->validarCategoria($dados, $id);

        if ($erros !== []) {
            $categoria = $dados + ['id_categoria_item' => $id];

            return $this->telaFormulario(self::LISTA, ['categoria' => $categoria], $erros, 422);
        }

        $this->categorias->update($id, $dados);

        return $this->salvo(site_url(self::LISTA), lang('Patrimonio.categorySaved'));
    }

    public function excluir(int $id): RedirectResponse
    {
        $lista = redirect()->to(site_url(self::LISTA));

        if ($this->categorias->find($id) === null) {
            return $lista->with('erro', lang('Patrimonio.categoryNotFound'));
        }

        if (! $this->exclusaoConfirmada()) {
            return $lista->with('erro', lang('Ui.confirmMismatch'));
        }

        $dependentes = $this->categorias->dependentes($id);

        if ($dependentes !== []) {
            return $lista->with('erro', $this->mensagemDependentes($dependentes));
        }

        $this->categorias->delete($id);

        return $lista->with('sucesso', lang('Patrimonio.categoryDeleted'));
    }

    /** @return array{nome: string, descricao: ?string} */
    private function dadosPostados(): array
    {
        return ['nome' => $this->texto('nome'), 'descricao' => $this->textoOuNulo('descricao')];
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array<string, string>
     */
    private function validarCategoria(array $dados, ?int $id): array
    {
        $erros = $this->validar($dados, [
            'nome'      => $this->regra('Institucional.fieldName', 'required|max_length[255]'),
            'descricao' => $this->regra('Institucional.fieldDescription', 'permit_empty|max_length[255]'),
        ]);

        if (! isset($erros['nome']) && $this->categorias->valorEmUso('nome', (string) $dados['nome'], $id)) {
            $erros['nome'] = lang('Patrimonio.categoryNameInUse');
        }

        return $erros;
    }
}

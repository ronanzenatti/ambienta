<?php

namespace App\Controllers\Institucional;

use App\Models\Institucional\CategoriaAmbienteModel;
use App\Tabelas\Institucional\CategoriasTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Categorias de ambiente (catálogo global: sala de aula, laboratório, auditório...). Nome único entre
 * as ativas (nome_unico no schema).
 */
class Categorias extends ControladorInstitucional
{
    private CategoriaAmbienteModel $categorias;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);

        $this->categorias = new CategoriaAmbienteModel();
    }

    public function index(): string
    {
        if ($this->pedidoParcial()) {
            return $this->tabela(new CategoriasTabela($this->podeEditarCatalogo()))->fragmento();
        }

        return view('institucional/categorias/index', [
            'tabela'     => $this->tabela(new CategoriasTabela($this->podeEditarCatalogo())),
            'podeEditar' => $this->podeEditarCatalogo(),
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela(new CategoriasTabela($this->podeEditarCatalogo()));
    }

    public function novo(): ResponseInterface|string
    {
        return $this->telaFormulario('institucional/categorias', ['categoria' => []]);
    }

    public function criar(): ResponseInterface|RedirectResponse|string
    {
        $dados = $this->dadosPostados();
        $erros = $this->validarCategoria($dados, null);

        if ($erros !== []) {
            return $this->telaFormulario('institucional/categorias', ['categoria' => $dados], $erros, 422);
        }

        $this->categorias->insert($dados);

        return $this->salvo(site_url('categorias-ambiente'), lang('Categorias.saved'));
    }

    public function editar(int $id): ResponseInterface|RedirectResponse|string
    {
        $categoria = $this->categorias->find($id);

        if ($categoria === null) {
            return redirect()->to(site_url('categorias-ambiente'))->with('erro', lang('Categorias.notFound'));
        }

        return $this->telaFormulario('institucional/categorias', ['categoria' => $categoria]);
    }

    public function atualizar(int $id): ResponseInterface|RedirectResponse|string
    {
        if ($this->categorias->find($id) === null) {
            return redirect()->to(site_url('categorias-ambiente'))->with('erro', lang('Categorias.notFound'));
        }

        $dados = $this->dadosPostados();
        $erros = $this->validarCategoria($dados, $id);

        if ($erros !== []) {
            $categoria = $dados + ['id_categoria_ambiente' => $id];

            return $this->telaFormulario('institucional/categorias', ['categoria' => $categoria], $erros, 422);
        }

        $this->categorias->update($id, $dados);

        return $this->salvo(site_url('categorias-ambiente'), lang('Categorias.saved'));
    }

    public function excluir(int $id): RedirectResponse
    {
        if ($this->categorias->find($id) === null) {
            return redirect()->to(site_url('categorias-ambiente'))->with('erro', lang('Categorias.notFound'));
        }

        if (! $this->exclusaoConfirmada()) {
            return redirect()->to(site_url('categorias-ambiente'))->with('erro', lang('Ui.confirmMismatch'));
        }

        $dependentes = $this->categorias->dependentes($id);

        if ($dependentes !== []) {
            return redirect()->to(site_url('categorias-ambiente'))
                ->with('erro', $this->mensagemDependentes($dependentes));
        }

        $this->categorias->delete($id);

        return redirect()->to(site_url('categorias-ambiente'))->with('sucesso', lang('Categorias.deleted'));
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
            $erros['nome'] = lang('Categorias.nameInUse');
        }

        return $erros;
    }
}

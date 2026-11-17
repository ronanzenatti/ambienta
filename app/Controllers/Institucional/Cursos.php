<?php

namespace App\Controllers\Institucional;

use App\Models\Institucional\CursoModel;
use App\Tabelas\Institucional\CursosTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Cursos (catálogo global): nome, abreviação, descrição, tipo (extensão, graduação, lato sensu, stricto sensu) e
 * responsável (usuário que gere as atribuições de aulas do curso).
 */
class Cursos extends ControladorAcademico
{
    private CursoModel $cursos;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);

        $this->cursos = new CursoModel();
    }

    public function index(): string
    {
        $tabela = $this->tabela(new CursosTabela($this->podeEditarCatalogo()));

        return $this->pedidoParcial() ? $tabela->fragmento() : view('institucional/cursos/index', [
            'tabela'     => $tabela,
            'podeEditar' => $this->podeEditarCatalogo(),
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela(new CursosTabela($this->podeEditarCatalogo()));
    }

    public function novo(): ResponseInterface|string
    {
        return $this->telaFormulario('institucional/cursos', ['curso' => []]);
    }

    public function criar(): ResponseInterface|RedirectResponse|string
    {
        $dados = $this->dadosPostados();
        $erros = $this->validarCurso($dados);

        if ($erros !== []) {
            return $this->telaFormulario('institucional/cursos', ['curso' => $dados], $erros, 422);
        }

        $this->cursos->insert($dados);

        return $this->salvo(site_url('cursos'), lang('Cursos.saved'));
    }

    public function editar(int $id): ResponseInterface|RedirectResponse|string
    {
        $curso = $this->cursos->find($id);

        if ($curso === null) {
            return redirect()->to(site_url('cursos'))->with('erro', lang('Cursos.notFound'));
        }

        return $this->telaFormulario('institucional/cursos', ['curso' => $curso]);
    }

    public function atualizar(int $id): ResponseInterface|RedirectResponse|string
    {
        $atual = $this->cursos->find($id);

        if ($atual === null) {
            return redirect()->to(site_url('cursos'))->with('erro', lang('Cursos.notFound'));
        }

        $dados = $this->dadosPostados();
        $erros = $this->validarCurso($dados, $atual);

        if ($erros !== []) {
            $curso = $dados + ['id_curso' => $id];

            return $this->telaFormulario('institucional/cursos', ['curso' => $curso], $erros, 422);
        }

        $this->cursos->update($id, $dados);

        return $this->salvo(site_url('cursos'), lang('Cursos.saved'));
    }

    public function excluir(int $id): RedirectResponse
    {
        if ($this->cursos->find($id) === null) {
            return redirect()->to(site_url('cursos'))->with('erro', lang('Cursos.notFound'));
        }

        if (! $this->exclusaoConfirmada()) {
            return redirect()->to(site_url('cursos'))->with('erro', lang('Ui.confirmMismatch'));
        }

        $dependentes = $this->cursos->dependentes($id);

        if ($dependentes !== []) {
            return redirect()->to(site_url('cursos'))->with('erro', $this->mensagemDependentes($dependentes));
        }

        $this->cursos->delete($id);

        return redirect()->to(site_url('cursos'))->with('sucesso', lang('Cursos.deleted'));
    }

    /** @return array<string, ?string> */
    private function dadosPostados(): array
    {
        return [
            'nome'       => $this->texto('nome'),
            'abreviacao' => $this->texto('abreviacao'),
            'descricao'  => $this->textoOuNulo('descricao'),
            'tipo'       => $this->texto('tipo'),
            'responsavel_id' => $this->texto('responsavel_id'),
        ];
    }

    /**
     * @param array<string, ?string>     $dados
     * @param array<string, mixed>|null $atual curso já gravado (alteração): o responsável dele continua válido
     *
     * @return array<string, string>
     */
    private function validarCurso(array $dados, ?array $atual = null): array
    {
        $tipos = implode(',', CursoModel::TIPOS);

        $erros = $this->validar($dados, [
            'nome'       => $this->regra('Institucional.fieldName', 'required|max_length[255]'),
            'abreviacao' => $this->regra('Institucional.fieldAbbreviation', 'required|max_length[20]'),
            'descricao'  => $this->regra('Institucional.fieldDescription', 'permit_empty|max_length[5000]'),
            'tipo'       => $this->regra('Cursos.fieldType', "required|in_list[{$tipos}]"),
            'responsavel_id' => $this->regra('Cursos.fieldManager', 'required|is_natural_no_zero'),
        ]);

        $mantido = $atual !== null && (string) $atual['responsavel_id'] === (string) $dados['responsavel_id'];

        $valido = $mantido || service('opcoes')->pessoas()->contem($dados['responsavel_id']);

        if (! isset($erros['responsavel_id']) && ! $valido) {
            $erros['responsavel_id'] = lang('Cursos.managerInvalid');
        }

        return $erros;
    }
}

<?php

namespace App\Controllers\Institucional;

use App\Models\Institucional\DisciplinaModel;
use App\Tabelas\Institucional\DisciplinasTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/** Disciplinas (catálogo global); cada uma pertence a um único curso, com sigla única dentro dele. */
class Disciplinas extends ControladorAcademico
{
    private DisciplinaModel $disciplinas;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);

        $this->disciplinas = new DisciplinaModel();
    }

    public function index(): string
    {
        $cursoId = $this->cursoFiltro();
        $tabela  = $this->tabela(new DisciplinasTabela($this->podeEditarCatalogo(), $cursoId));

        return $this->pedidoParcial() ? $tabela->fragmento() : view('institucional/disciplinas/index', [
            'tabela'     => $tabela,
            'podeEditar' => $this->podeEditarCatalogo(),
            'cursoId'    => $cursoId,
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela(new DisciplinasTabela($this->podeEditarCatalogo(), $this->cursoFiltro()));
    }

    public function novo(): ResponseInterface|string
    {
        return $this->telaFormulario('institucional/disciplinas', ['disciplina' => []]);
    }

    public function criar(): ResponseInterface|RedirectResponse|string
    {
        $dados = $this->dadosPostados();
        $erros = $this->validarDisciplina($dados);

        if ($erros !== []) {
            return $this->telaFormulario('institucional/disciplinas', ['disciplina' => $dados], $erros, 422);
        }

        $id = $this->disciplinas->insert($dados);

        return $this->salvo(site_url("disciplinas/{$id}"), lang('Disciplinas.saved'));
    }

    public function ver(int $id): ResponseInterface|RedirectResponse|string
    {
        $disciplina = $this->disciplinas->find($id);

        if ($disciplina === null) {
            return redirect()->to(site_url('disciplinas'))->with('erro', lang('Disciplinas.notFound'));
        }

        return $this->pagina($disciplina);
    }

    public function editar(int $id): ResponseInterface|RedirectResponse|string
    {
        $disciplina = $this->disciplinas->find($id);

        if ($disciplina === null) {
            return redirect()->to(site_url('disciplinas'))->with('erro', lang('Disciplinas.notFound'));
        }

        return $this->telaFormulario('institucional/disciplinas', ['disciplina' => $disciplina]);
    }

    public function atualizar(int $id): ResponseInterface|RedirectResponse|string
    {
        if ($this->disciplinas->find($id) === null) {
            return redirect()->to(site_url('disciplinas'))->with('erro', lang('Disciplinas.notFound'));
        }

        $dados = $this->dadosPostados();
        $erros = $this->validarDisciplina($dados, $id);

        if ($erros !== []) {
            $disciplina = $dados + ['id_disciplina' => $id];

            return $this->telaFormulario('institucional/disciplinas', ['disciplina' => $disciplina], $erros, 422);
        }

        $this->disciplinas->update($id, $dados);

        return $this->salvo(site_url("disciplinas/{$id}"), lang('Disciplinas.saved'));
    }

    public function excluir(int $id): RedirectResponse
    {
        if ($this->disciplinas->find($id) === null) {
            return redirect()->to(site_url('disciplinas'))->with('erro', lang('Disciplinas.notFound'));
        }

        if (! $this->exclusaoConfirmada()) {
            return redirect()->to(site_url("disciplinas/{$id}"))->with('erro', lang('Ui.confirmMismatch'));
        }

        $dependentes = $this->disciplinas->dependentes($id);

        if ($dependentes !== []) {
            return redirect()->to(site_url("disciplinas/{$id}"))
                ->with('erro', $this->mensagemDependentes($dependentes));
        }

        $this->disciplinas->delete($id);

        return redirect()->to(site_url('disciplinas'))->with('sucesso', lang('Disciplinas.deleted'));
    }

    /** Filtro por curso (?curso=), validado contra a mesma fonte do select. */
    private function cursoFiltro(): ?int
    {
        $valor = trim((string) $this->request->getGet('curso'));

        return $valor !== '' && service('opcoes')->cursos()->contem($valor) ? (int) $valor : null;
    }

    /** @return array{curso_id: string, nome: string, sigla: string} */
    private function dadosPostados(): array
    {
        return [
            'curso_id' => $this->texto('curso_id'),
            'nome'     => $this->texto('nome'),
            'sigla'    => $this->texto('sigla'),
        ];
    }

    /**
     * @param array<string, string> $dados
     *
     * @return array<string, string>
     */
    private function validarDisciplina(array $dados, ?int $ignorarId = null): array
    {
        $erros = $this->validar($dados, [
            'nome'  => $this->regra('Institucional.fieldName', 'required|max_length[255]'),
            'sigla' => $this->regra('Disciplinas.fieldCode', 'required|max_length[20]'),
        ]);

        if (! service('opcoes')->cursos()->contem($dados['curso_id'])) {
            $erros['curso_id'] = lang('Disciplinas.courseInvalid');
        } elseif (! isset($erros['sigla']) && $this->siglaEmUso($dados, $ignorarId)) {
            $erros['sigla'] = lang('Disciplinas.codeInUse');
        }

        return $erros;
    }

    /** @param array<string, string> $dados */
    private function siglaEmUso(array $dados, ?int $ignorarId): bool
    {
        $consulta = $this->disciplinas->where('curso_id', (int) $dados['curso_id'])->where('sigla', $dados['sigla']);

        if ($ignorarId !== null) {
            $consulta->where('id_disciplina !=', $ignorarId);
        }

        return $consulta->countAllResults() > 0;
    }

    /**
     * @param array<string, mixed>  $disciplina
     * @param array<string, string> $erros
     */
    private function pagina(array $disciplina, array $erros = [], int $status = 200): ResponseInterface|string
    {
        $curso = db_connect()->table('cursos')->select('nome, abreviacao')
            ->where('id_curso', $disciplina['curso_id'])->get()->getRowArray();

        return $this->responder('institucional/disciplinas/ver', [
            'disciplina' => $disciplina,
            'curso'      => $curso,
            'podeEditar' => $this->podeEditarCatalogo(),
        ], $erros, $status);
    }
}

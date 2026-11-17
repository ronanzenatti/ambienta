<?php

namespace App\Controllers\Institucional;

use App\Models\Institucional\SemestreModel;
use App\Tabelas\Institucional\SemestresTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Semestres letivos (catálogo global): nome único entre os ativos, início, fim e status
 * (planejado, aberto, encerrado). A consulta pública mostra as alocações dos semestres abertos.
 */
class Semestres extends ControladorAcademico
{
    private SemestreModel $semestres;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);

        $this->semestres = new SemestreModel();
    }

    public function index(): string
    {
        $tabela = $this->tabela(new SemestresTabela($this->podeEditarCatalogo()));

        return $this->pedidoParcial() ? $tabela->fragmento() : view('institucional/semestres/index', [
            'tabela'     => $tabela,
            'podeEditar' => $this->podeEditarCatalogo(),
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela(new SemestresTabela($this->podeEditarCatalogo()));
    }

    public function novo(): ResponseInterface|string
    {
        return $this->telaFormulario('institucional/semestres', ['semestre' => ['status' => 'planejado']]);
    }

    public function criar(): ResponseInterface|RedirectResponse|string
    {
        $dados = $this->dadosPostados();
        $erros = $this->validarSemestre($dados, null);

        if ($erros !== []) {
            return $this->telaFormulario('institucional/semestres', ['semestre' => $dados], $erros, 422);
        }

        $this->semestres->insert($dados);

        return $this->salvo(site_url('semestres'), lang('Semestres.saved'));
    }

    public function editar(int $id): ResponseInterface|RedirectResponse|string
    {
        $semestre = $this->semestres->find($id);

        if ($semestre === null) {
            return redirect()->to(site_url('semestres'))->with('erro', lang('Semestres.notFound'));
        }

        return $this->telaFormulario('institucional/semestres', ['semestre' => $semestre]);
    }

    public function atualizar(int $id): ResponseInterface|RedirectResponse|string
    {
        if ($this->semestres->find($id) === null) {
            return redirect()->to(site_url('semestres'))->with('erro', lang('Semestres.notFound'));
        }

        $dados = $this->dadosPostados();
        $erros = $this->validarSemestre($dados, $id);

        if ($erros !== []) {
            $semestre = $dados + ['id_semestre' => $id];

            return $this->telaFormulario('institucional/semestres', ['semestre' => $semestre], $erros, 422);
        }

        $this->semestres->update($id, $dados);

        return $this->salvo(site_url('semestres'), lang('Semestres.saved'));
    }

    public function excluir(int $id): RedirectResponse
    {
        if ($this->semestres->find($id) === null) {
            return redirect()->to(site_url('semestres'))->with('erro', lang('Semestres.notFound'));
        }

        if (! $this->exclusaoConfirmada()) {
            return redirect()->to(site_url('semestres'))->with('erro', lang('Ui.confirmMismatch'));
        }

        $dependentes = $this->semestres->dependentes($id);

        if ($dependentes !== []) {
            return redirect()->to(site_url('semestres'))->with('erro', $this->mensagemDependentes($dependentes));
        }

        $this->semestres->delete($id);

        return redirect()->to(site_url('semestres'))->with('sucesso', lang('Semestres.deleted'));
    }

    /** @return array<string, string> */
    private function dadosPostados(): array
    {
        return [
            'nome'        => $this->texto('nome'),
            'data_inicio' => $this->texto('data_inicio'),
            'data_fim'    => $this->texto('data_fim'),
            'status'      => $this->texto('status'),
        ];
    }

    /**
     * @param array<string, string> $dados
     *
     * @return array<string, string>
     */
    private function validarSemestre(array $dados, ?int $id): array
    {
        $erros = $this->validar($dados, [
            'nome'        => $this->regra('Institucional.fieldName', 'required|max_length[50]'),
            'data_inicio' => $this->regra('Semestres.fieldStart', 'required|valid_date[Y-m-d]'),
            'data_fim'    => $this->regra('Semestres.fieldEnd', 'required|valid_date[Y-m-d]'),
            'status'      => $this->regra('Ui.status', 'required|in_list[' . implode(',', SemestreModel::STATUS) . ']'),
        ]);

        $datasValidas = ! isset($erros['data_inicio']) && ! isset($erros['data_fim']);

        if ($datasValidas && $dados['data_fim'] <= $dados['data_inicio']) {
            $erros['data_fim'] = lang('Semestres.endBeforeStart');
        }

        if (! isset($erros['nome']) && $this->semestres->valorEmUso('nome', $dados['nome'], $id)) {
            $erros['nome'] = lang('Semestres.nameInUse');
        }

        return $erros;
    }
}

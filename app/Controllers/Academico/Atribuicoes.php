<?php

namespace App\Controllers\Academico;

use App\Controllers\ControladorCadastro;
use App\Libraries\Academico\GestaoAtribuicoes;
use App\Libraries\Agendamento\Recusa;
use App\Libraries\Agendamento\Vinculos as VinculosServico;
use App\Libraries\Autorizacao;
use App\Models\Chamados\RequisitoDisciplinaModel;
use App\Tabelas\Academico\AtribuicoesTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Atribuições de aulas (Acadêmico): quem leciona cada disciplina em cada semestre (professores_disciplinas); a
 * alocação semestral exige a atribuição. Quem vê e quem gere (ver GestaoAtribuicoes):
 * - academico.atribuicoes: leitor vê todas, editor gere as de qualquer curso;
 * - o responsável do curso cadastra, importa e exclui as atribuições das disciplinas dele;
 * - os demais veem só as próprias, e abrem daqui os requisitos permanentes da disciplina (academico.requisitos).
 */
class Atribuicoes extends ControladorCadastro
{
    private const LISTA = 'atribuicoes';

    private GestaoAtribuicoes $gestao;

    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);

        $this->gestao = new GestaoAtribuicoes(db_connect(), service('autorizacao'), $this->usuarioId());
    }

    public function index(): ResponseInterface|string
    {
        $tabela = $this->tabela($this->novaTabela());

        if ($this->pedidoParcial()) {
            return $tabela->fragmento();
        }

        $gere = $this->gestao->gereAlgum();

        return view('patrimonio/_lista', [
            'tabela' => $tabela,
            'titulo' => lang('Agendamento.linksTitle'),
            'intro'  => lang('Agendamento.linksIntro'),
            'novo'   => $gere ? [lang('Agendamento.linkNew'), site_url(self::LISTA . '/novo')] : null,
            'extra'  => $gere ? ui_button(lang('Agendamento.linkImport'), 'secondary', [
                'href'   => site_url(self::LISTA . '/importar'),
                '@click' => 'ambientaAbrirFormulario($event)',
            ]) : null,
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela($this->novaTabela());
    }

    public function novo(): ResponseInterface|string
    {
        return $this->gestao->gereAlgum() ? $this->formulario([]) : $this->negar();
    }

    public function criar(): ResponseInterface|RedirectResponse|string
    {
        if (! $this->gestao->gereAlgum()) {
            return $this->negar();
        }

        $dados = [
            'semestre_id'   => $this->texto('semestre_id'),
            'disciplina_id' => $this->texto('disciplina_id'),
            'professor_id'  => $this->texto('professor_id'),
        ];
        $erros = $this->validar($dados, [
            'semestre_id'   => $this->regra('Agendamento.fieldSemester', 'required|is_natural_no_zero'),
            'disciplina_id' => $this->regra('Agendamento.fieldDiscipline', 'required|is_natural_no_zero'),
            'professor_id'  => $this->regra('Agendamento.fieldTeacher', 'required|is_natural_no_zero'),
        ]);
        $opcoes = service('opcoes');
        $fontes = [
            'semestre_id'   => $opcoes->semestresAbertos(),
            'disciplina_id' => $opcoes->disciplinasAtribuiveis(),
            'professor_id'  => $opcoes->pessoas(),
        ];

        foreach ($fontes as $campo => $fonte) {
            if (! isset($erros[$campo]) && ! $fonte->contem((int) $dados[$campo])) {
                $erros[$campo] = lang('Agendamento.optionInvalid');
            }
        }

        $servico = new VinculosServico(db_connect());

        $existe = $erros === [] && $servico->existe(
            (int) $dados['semestre_id'],
            (int) $dados['disciplina_id'],
            (int) $dados['professor_id']
        );

        if ($existe) {
            $erros['professor_id'] = lang('Agendamento.linkExists');
        }

        if ($erros !== []) {
            return $this->formulario($dados, $erros, 422);
        }

        $servico->criar((int) $dados['semestre_id'], (int) $dados['disciplina_id'], (int) $dados['professor_id']);

        return $this->salvo(site_url(self::LISTA), lang('Agendamento.linkSaved'));
    }

    public function importarForm(): ResponseInterface|string
    {
        return $this->gestao->gereAlgum() ? $this->formularioImportar([]) : $this->negar();
    }

    public function importar(): ResponseInterface|RedirectResponse|string
    {
        if (! $this->gestao->gereAlgum()) {
            return $this->negar();
        }

        $dados = ['origem_id' => $this->texto('origem_id'), 'destino_id' => $this->texto('destino_id')];
        $erros = $this->validar($dados, [
            'origem_id'  => $this->regra('Agendamento.linkImportFrom', 'required|is_natural_no_zero'),
            'destino_id' => $this->regra('Agendamento.linkImportTo', 'required|is_natural_no_zero'),
        ]);
        $opcoes = service('opcoes');

        if (! isset($erros['origem_id']) && ! $opcoes->semestres()->contem((int) $dados['origem_id'])) {
            $erros['origem_id'] = lang('Agendamento.optionInvalid');
        }

        if (! isset($erros['destino_id']) && ! $opcoes->semestresAbertos()->contem((int) $dados['destino_id'])) {
            $erros['destino_id'] = lang('Agendamento.optionInvalid');
        }

        $resultado = null;

        if ($erros === []) {
            try {
                $resultado = (new VinculosServico(db_connect()))->importar(
                    (int) $dados['origem_id'],
                    (int) $dados['destino_id'],
                    $this->gestao->cursosGeridos()
                );
            } catch (Recusa $recusa) {
                $erros['destino_id'] = $recusa->texto();
            }
        }

        if ($resultado === null) {
            return $this->formularioImportar($dados, $erros, 422);
        }

        return $this->salvo(
            site_url(self::LISTA),
            lang('Agendamento.linkImported', [$resultado['copiados'], $resultado['existentes']])
        );
    }

    public function excluir(int $id): RedirectResponse|ResponseInterface
    {
        $lista   = redirect()->to(site_url(self::LISTA));
        $vinculo = db_connect()->table('professores_disciplinas pd')
            ->select('pd.semestre_id, pd.disciplina_id, pd.professor_id, d.curso_id')
            ->join('disciplinas d', 'd.id_disciplina = pd.disciplina_id')
            ->where('pd.id_professor_disciplina', $id)
            ->where('pd.deletado_em', null)
            ->get()
            ->getRowArray();

        if ($vinculo === null) {
            return $lista->with('erro', lang('Agendamento.linkNotFound'));
        }

        if (! $this->gestao->gereCurso((int) $vinculo['curso_id'])) {
            return $this->negar();
        }

        if (! $this->exclusaoConfirmada()) {
            return $lista->with('erro', lang('Ui.confirmMismatch'));
        }

        $emUso = (new VinculosServico(db_connect()))->alocacoesDependentes(
            (int) $vinculo['semestre_id'],
            (int) $vinculo['disciplina_id'],
            (int) $vinculo['professor_id']
        );

        if ($emUso > 0) {
            return $lista->with('erro', lang('Agendamento.linkInUse'));
        }

        db_connect()->table('professores_disciplinas')
            ->where('id_professor_disciplina', $id)
            ->update(['deletado_em' => date('Y-m-d H:i:s')]);

        return $lista->with('sucesso', lang('Agendamento.linkDeleted'));
    }

    /**
     * Abre (criando, se preciso) o requisito permanente do professor logado para a disciplina que ele leciona.
     * Exige academico.requisitos como editor.
     */
    public function requisitos(int $disciplinaId): ResponseInterface|RedirectResponse
    {
        if (! service('autorizacao')->pode('academico.requisitos', Autorizacao::EDITOR)) {
            return $this->negar();
        }

        $vinculado = db_connect()->table('professores_disciplinas')
            ->where('professor_id', $this->usuarioId())
            ->where('disciplina_id', $disciplinaId)
            ->where('deletado_em', null)
            ->countAllResults() > 0;

        if (! $vinculado) {
            return redirect()->to(site_url(self::LISTA))->with('erro', lang('Chamados.myDisciplineNotLinked'));
        }

        $modelo    = new RequisitoDisciplinaModel();
        $requisito = $modelo->where('professor_id', $this->usuarioId())
            ->where('disciplina_id', $disciplinaId)
            ->first();
        $id        = $requisito === null
            ? (int) $modelo->insert([
                'professor_id'      => $this->usuarioId(),
                'disciplina_id'     => $disciplinaId,
                'capacidade_minima' => 0,
            ])
            : (int) $requisito['id_requisito_disciplina'];

        return redirect()->to(site_url("requisitos/{$id}"));
    }

    private function usuarioId(): int
    {
        return (int) (service('autenticacao')->usuario()['id_usuario'] ?? 0);
    }

    private function novaTabela(): AtribuicoesTabela
    {
        return new AtribuicoesTabela(
            $this->gestao,
            $this->usuarioId(),
            service('autorizacao')->pode('academico.requisitos', Autorizacao::EDITOR)
        );
    }

    /**
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    private function formulario(array $dados, array $erros = [], int $status = 200): ResponseInterface|string
    {
        return $this->telaFormulario('academico/atribuicoes', ['dados' => $dados], $erros, $status);
    }

    /**
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    private function formularioImportar(array $dados, array $erros = [], int $status = 200): ResponseInterface|string
    {
        return $this->telaFormulario('academico/atribuicoes/importar', ['dados' => $dados], $erros, $status);
    }
}

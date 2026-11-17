<?php

namespace App\Controllers\Agendamento;

use App\Libraries\Agendamento\Alocacoes as AlocacoesServico;
use App\Libraries\Agendamento\Recusa;
use App\Libraries\Autorizacao;
use App\Libraries\Chamados\Atendimento;
use App\Libraries\Opcoes\Fonte;
use App\Tabelas\Agendamento\AlocacoesTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Alocação semestral: listagem, cadastro em página (8 campos) pela procedure, página da alocação (com as
 * pendências de requisito e as exceções por dia), cancelamento e exclusão (CONFIRMO).
 */
class Alocacoes extends ControladorAgendamento
{
    private const LISTA = 'agendamento/alocacoes';

    public function index(): string
    {
        $tabela = $this->tabela($this->novaTabela());

        return $this->pedidoParcial() ? $tabela->fragmento() : view('patrimonio/_lista', [
            'tabela' => $tabela,
            'titulo' => lang('Agendamento.allocationsTitle'),
            'intro'  => lang('Agendamento.allocationsIntro'),
            'novo'   => $this->autorizacao->pode(self::ALOCAR, Autorizacao::EDITOR)
                ? [lang('Agendamento.allocationNew'), site_url(self::LISTA . '/novo')]
                : null,
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela($this->novaTabela());
    }

    public function novo(): ResponseInterface|string
    {
        if (! $this->autorizacao->pode(self::ALOCAR, Autorizacao::EDITOR)) {
            return $this->negar();
        }

        return $this->formulario([]);
    }

    public function criar(): ResponseInterface|RedirectResponse|string
    {
        if (! $this->autorizacao->pode(self::ALOCAR, Autorizacao::EDITOR)) {
            return $this->negar();
        }

        $dados = [
            'semestre_id'   => $this->texto('semestre_id'),
            'vinculo_id'    => $this->texto('vinculo_id'),
            'ambiente_id'   => $this->texto('ambiente_id'),
            'dia_semana'    => $this->texto('dia_semana'),
            'hora_inicio'   => $this->texto('hora_inicio'),
            'hora_fim'      => $this->texto('hora_fim'),
        ];
        $ciencia      = $this->request->getPost('ciencia') === '1';

        $erros = $this->validar($dados, [
            'semestre_id'   => $this->regra('Agendamento.fieldSemester', 'required|is_natural_no_zero'),
            'vinculo_id'    => $this->regra('Agendamento.fieldLink', 'required|is_natural_no_zero'),
            'ambiente_id'   => $this->regra('Agendamento.fieldEnvironment', 'required|is_natural_no_zero'),
            'dia_semana'    => $this->regra(
                'Agendamento.fieldWeekday',
                'required|in_list[' . implode(',', AlocacoesServico::DIAS) . ']'
            ),
            'hora_inicio'   => $this->regra('Agendamento.fieldStartTime', 'required'),
            'hora_fim'      => $this->regra('Agendamento.fieldEndTime', 'required'),
        ]);

        foreach ($this->fontes((int) $dados['semestre_id']) as $campo => $fonte) {
            if (! isset($erros[$campo]) && ! $fonte->contem((int) $dados[$campo])) {
                $erros[$campo] = lang('Agendamento.optionInvalid');
            }
        }

        foreach (['hora_inicio', 'hora_fim'] as $campo) {
            if (! isset($erros[$campo]) && ! $this->horaValida($dados[$campo])) {
                $erros[$campo] = lang('Agendamento.dateTimeInvalid');
            }
        }

        $resultado = null;

        $outros = [];

        if ($erros === []) {
            $vinculo = db_connect()->table('professores_disciplinas')
                ->select('disciplina_id, professor_id')
                ->where('id_professor_disciplina', (int) $dados['vinculo_id'])
                ->where('deletado_em', null)
                ->get()
                ->getRowArray();
            $valores = [
                'semestre_id'   => (int) $dados['semestre_id'],
                'disciplina_id' => (int) $vinculo['disciplina_id'],
                'professor_id'  => (int) $vinculo['professor_id'],
                'ambiente_id'   => (int) $dados['ambiente_id'],
                'dia_semana'    => $dados['dia_semana'],
                'hora_inicio'   => $dados['hora_inicio'] . ':00',
                'hora_fim'      => $dados['hora_fim'] . ':00',
            ];
            $servico = new AlocacoesServico(db_connect());

            try {
                $resultado = $servico->criar($valores, $this->usuarioId(), $ciencia);
            } catch (Recusa $recusa) {
                $erros['geral'] = $recusa->texto();
                $outros         = $servico->mesmaDisciplinaEmOutroAmbiente($valores);
            }
        }

        if ($resultado === null) {
            return $this->formulario($dados, $erros, 422, $outros);
        }

        $mensagem = lang('Agendamento.allocationCreated');
        $alerta   = AlocacoesServico::alertaDuracao($dados['hora_inicio'], $dados['hora_fim']);
        $destino  = redirect()->to(site_url(self::LISTA . '/' . $resultado['id']));

        if ($resultado['chamado_id'] !== null) {
            $mensagem .= ' ' . lang('Agendamento.ticketOpenedForAllocation', [$resultado['chamado_id']]);
        }

        if ($alerta !== null || $resultado['pendencias'] !== []) {
            $avisos = [];

            if ($alerta !== null) {
                $avisos[] = lang('Agendamento.longDuration', [$alerta]);
            }

            if ($avisos !== []) {
                $destino = $destino->with('aviso', implode(' ', $avisos));
            }
        }

        return $destino->with('sucesso', $mensagem);
    }

    public function ver(int $id): ResponseInterface|RedirectResponse|string
    {
        [$alocacao, $recusa] = $this->carregar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $db       = db_connect();
        $excecoes = $db->table('alocacoes_semestrais_excecoes')
            ->select('data_excecao, motivo, observacao, reserva_pontual_id')
            ->where('alocacao_semestral_id', $id)
            ->where('deletado_em', null)
            ->orderBy('data_excecao')
            ->get()
            ->getResultArray();

        $ambienteId = (int) $alocacao['ambiente_id'];

        return view('agendamento/alocacoes/ver', [
            'alocacao'   => $alocacao,
            'ambiente'   => $this->locais->rotulo($ambienteId, null),
            'excecoes'   => $excecoes,
            'atendimento' => (new Atendimento($db))
                ->avaliar((int) $alocacao['professor_id'], (int) $alocacao['disciplina_id'], $ambienteId),
            'alerta'     => AlocacoesServico::alertaDuracao(
                (string) $alocacao['hora_inicio'],
                (string) $alocacao['hora_fim']
            ),
            'podeGerir'  => $this->podeNoAmbiente($ambienteId, Autorizacao::EDITOR, self::ALOCAR),
        ]);
    }

    public function cancelar(int $id): RedirectResponse|ResponseInterface
    {
        [$alocacao, $recusa] = $this->carregarParaGerir($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $destino = redirect()->to(site_url(self::LISTA . "/{$id}"));

        if (! $this->exclusaoConfirmada()) {
            return $destino->with('erro', lang('Ui.confirmMismatch'));
        }

        try {
            (new AlocacoesServico(db_connect()))->cancelar($id, $this->usuarioId());
        } catch (Recusa $recusa) {
            return $destino->with('erro', $recusa->texto());
        }

        return $destino->with('sucesso', lang('Agendamento.allocationCancelled'));
    }

    public function excluir(int $id): RedirectResponse|ResponseInterface
    {
        [$alocacao, $recusa] = $this->carregarParaGerir($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $lista = redirect()->to(site_url(self::LISTA));

        if (! $this->exclusaoConfirmada()) {
            return $lista->with('erro', lang('Ui.confirmMismatch'));
        }

        (new AlocacoesServico(db_connect()))->excluir($id);

        return $lista->with('sucesso', lang('Agendamento.allocationDeleted'));
    }

    private function novaTabela(): AlocacoesTabela
    {
        return new AlocacoesTabela(
            $this->escopo(),
            $this->usuarioId(),
            fn (int $ambiente): bool => $this->podeNoAmbiente($ambiente, Autorizacao::EDITOR, self::ALOCAR)
        );
    }

    /**
     * @return array{0: array<string, mixed>, 1: ResponseInterface|RedirectResponse|null}
     */
    private function carregar(int $id): array
    {
        $alocacao = db_connect()->table('alocacoes_semestrais a')
            ->select('a.*, s.nome AS semestre, s.data_inicio, s.data_fim, c.nome AS curso, d.sigla,
                      d.nome AS disciplina, u.nome AS professor')
            ->join('semestres s', 's.id_semestre = a.semestre_id')
            ->join('disciplinas d', 'd.id_disciplina = a.disciplina_id')
            ->join('cursos c', 'c.id_curso = d.curso_id')
            ->join('usuarios u', 'u.id_usuario = a.professor_id')
            ->where('a.id_alocacao_semestral', $id)
            ->where('a.deletado_em', null)
            ->get()
            ->getRowArray();

        if ($alocacao === null) {
            return [[], redirect()->to(site_url(self::LISTA))->with('erro', lang('Agendamento.allocationNotFound'))];
        }

        $visivel = (int) $alocacao['professor_id'] === $this->usuarioId()
            || $this->podeNoAmbiente((int) $alocacao['ambiente_id']);

        return [$alocacao, $visivel ? null : $this->negar()];
    }

    /** @return array{0: array<string, mixed>, 1: ResponseInterface|RedirectResponse|null} */
    private function carregarParaGerir(int $id): array
    {
        [$alocacao, $recusa] = $this->carregar($id);

        $ambienteId = (int) ($alocacao['ambiente_id'] ?? 0);

        if ($recusa === null && ! $this->podeNoAmbiente($ambienteId, Autorizacao::EDITOR, self::ALOCAR)) {
            $recusa = $this->negar();
        }

        return [$alocacao, $recusa];
    }

    /**
     * Fontes das listas do formulário (as mesmas que a tela carrega sob demanda), para validar o que foi postado.
     *
     * @return array<string, Fonte>
     */
    private function fontes(int $semestreId): array
    {
        $opcoes = service('opcoes');

        return [
            'semestre_id'   => $opcoes->semestresAbertos(),
            'vinculo_id'    => $opcoes->vinculos($semestreId),
            'ambiente_id'   => $opcoes->ambientes($this->escopo(Autorizacao::EDITOR, self::ALOCAR)),
        ];
    }

    /**
     * @param array<string, mixed>       $dados
     * @param array<string, string>      $erros
     * @param list<array<string, mixed>> $outros alocações da disciplina em outro ambiente no mesmo horário
     */
    private function formulario(
        array $dados,
        array $erros = [],
        int $status = 200,
        array $outros = []
    ): ResponseInterface|string {
        $dados = ['dados' => $dados, 'outros' => $outros];

        return $this->responder('agendamento/alocacoes/form', $dados, $erros, $status);
    }
}

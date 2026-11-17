<?php

namespace App\Controllers\Chamados;

use App\Libraries\Autorizacao;
use App\Libraries\Opcoes\Fonte;
use App\Libraries\Chamados\Chamados;
use App\Libraries\Chamados\Recusa;
use App\Tabelas\Chamados\ChamadosTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Chamados de manutenção: lista, abertura (ambiente + descrição, em modal), página do chamado com o
 * histórico completo, a mudança de status e as movimentações (em modal, observação obrigatória).
 */
class ChamadosAdequacao extends ControladorChamados
{
    private const LISTA = 'chamados';

    public function index(): string
    {
        $tabela = $this->tabela($this->novaTabela());

        return $this->pedidoParcial() ? $tabela->fragmento() : view('patrimonio/_lista', [
            'tabela' => $tabela,
            'titulo' => lang('Chamados.ticketsTitle'),
            'intro'  => lang('Chamados.ticketsIntro'),
            'novo'   => $this->ehEditor() ? [lang('Chamados.ticketNew'), site_url(self::LISTA . '/novo')] : null,
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela($this->novaTabela());
    }

    /** Chamados de um ambiente (cartão da página do ambiente): só a região da tabela, com ?parcial=1. */
    public function doAmbiente(int $ambienteId): ResponseInterface|RedirectResponse|string
    {
        $recusa = $this->recusaNoAmbiente($ambienteId, Autorizacao::LEITOR);

        if ($recusa !== null) {
            return $recusa;
        }

        $tabela = $this->tabela(new ChamadosTabela($this->escopo(), $this->usuarioId(), $ambienteId));

        return $this->pedidoParcial()
            ? $tabela->fragmento()
            : redirect()->to(site_url("ambientes/{$ambienteId}"));
    }

    public function exportarDoAmbiente(int $ambienteId): ResponseInterface|RedirectResponse
    {
        $recusa = $this->recusaNoAmbiente($ambienteId, Autorizacao::LEITOR);

        return $recusa ?? $this->exportarTabela(new ChamadosTabela($this->escopo(), $this->usuarioId(), $ambienteId));
    }

    /** Aberto da página do ambiente (?ambiente=): o ambiente já vem escolhido e fixo. */
    public function novo(): ResponseInterface|RedirectResponse|string
    {
        $ambienteId = (string) $this->request->getGet('ambiente');

        if (! ctype_digit($ambienteId) || $ambienteId === '0') {
            return $this->formulario([]);
        }

        $recusa = $this->recusaNoAmbiente((int) $ambienteId, Autorizacao::EDITOR);

        return $recusa ?? $this->formulario(['ambiente_id' => $ambienteId, 'ambiente_fixo' => '1']);
    }

    public function criar(): ResponseInterface|RedirectResponse|string
    {
        $dados = [
            'ambiente_id'   => $this->texto('ambiente_id'),
            'descricao'     => $this->texto('descricao'),
            'ambiente_fixo' => $this->texto('ambiente_fixo'),
        ];
        $erros = $this->validar($dados, [
            'ambiente_id' => $this->regra('Chamados.fieldEnvironment', 'required'),
            'descricao'   => $this->regra('Chamados.fieldDescription', 'required|max_length[5000]'),
        ]);


        if (! isset($erros['ambiente_id']) && ! $this->ambientesParaAbrir()->contem((int) $dados['ambiente_id'])) {
            $erros['ambiente_id'] = lang('Chamados.environmentInvalid');
        }

        if ($erros !== []) {
            return $this->formulario($dados, $erros, 422);
        }

        $id = (new Chamados(db_connect()))->abrir((int) $dados['ambiente_id'], $dados['descricao'], $this->usuarioId());

        return $this->salvo(site_url(self::LISTA . "/{$id}"), lang('Chamados.ticketOpened', [$id]));
    }

    public function ver(int $id): ResponseInterface|RedirectResponse|string
    {
        [$chamado, $recusa] = $this->carregar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $historico = db_connect()->table('chamados_historico h')
            ->select('h.status_anterior, h.status_novo, h.observacao, h.criado_em, u.nome AS usuario')
            ->join('usuarios u', 'u.id_usuario = h.usuario_id')
            ->where('h.chamado_id', $id)
            ->where('h.deletado_em', null)
            ->orderBy('h.criado_em', 'DESC')
            ->orderBy('h.id_chamado_historico', 'DESC')
            ->get()
            ->getResultArray();

        return view('chamados/chamados/ver', [
            'chamado'   => $chamado,
            'ambiente'  => $this->locais->rotulo((int) $chamado['ambiente_id'], null),
            'historico' => $historico,
            'podeMudar' => $this->statusPermitidos($chamado) !== [],
            'podeMovimentar' => $this->podeMovimentar($chamado),
        ]);
    }

    public function situacao(int $id): ResponseInterface|RedirectResponse|string
    {
        [$chamado, $recusa] = $this->carregarParaMudar($id);

        return $recusa ?? $this->formularioSituacao($chamado, []);
    }

    public function mudarSituacao(int $id): ResponseInterface|RedirectResponse|string
    {
        [$chamado, $recusa] = $this->carregarParaMudar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $dados = ['status' => $this->texto('status'), 'observacao' => $this->texto('observacao')];
        $erros = $this->validar($dados, [
            'status'     => $this->regra('Chamados.fieldNewStatus', 'required'),
            'observacao' => $this->regra('Chamados.fieldNote', 'required|max_length[5000]'),
        ]);

        if (! isset($erros['status']) && ! in_array($dados['status'], $this->statusPermitidos($chamado), true)) {
            $erros['status'] = lang('Chamados.transitionInvalid');
        }

        if ($erros === []) {
            try {
                (new Chamados(db_connect()))
                    ->mudarStatus($id, $dados['status'], $dados['observacao'], $this->usuarioId());
            } catch (Recusa $recusa) {
                $erros['status'] = lang($recusa->getMessage());
            }
        }

        if ($erros !== []) {
            return $this->formularioSituacao($chamado, $dados, $erros, 422);
        }

        $mensagem = lang('Chamados.statusChanged', [lang('Chamados.status_' . $dados['status'])]);

        // No modal a resposta é JSON e a página recarrega (o histórico mudou): a mensagem vai na sessão.
        if ($this->pedidoModal()) {
            session()->setFlashdata('sucesso', $mensagem);
        }

        return $this->salvo(site_url(self::LISTA . "/{$id}"), $mensagem);
    }

    public function movimentacao(int $id): ResponseInterface|RedirectResponse|string
    {
        [$chamado, $recusa] = $this->carregarParaMovimentar($id);

        return $recusa ?? $this->formularioMovimentacao($chamado, []);
    }

    public function movimentar(int $id): ResponseInterface|RedirectResponse|string
    {
        [$chamado, $recusa] = $this->carregarParaMovimentar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $dados = ['observacao' => $this->texto('observacao')];
        $erros = $this->validar($dados, [
            'observacao' => $this->regra('Chamados.fieldMovement', 'required|max_length[5000]'),
        ]);

        if ($erros === []) {
            try {
                (new Chamados(db_connect()))->movimentar($id, $dados['observacao'], $this->usuarioId());
            } catch (Recusa $recusa) {
                $erros['observacao'] = lang($recusa->getMessage());
            }
        }

        if ($erros !== []) {
            return $this->formularioMovimentacao($chamado, $dados, $erros, 422);
        }

        $mensagem = lang('Chamados.movementSaved');

        if ($this->pedidoModal()) {
            session()->setFlashdata('sucesso', $mensagem);
        }

        return $this->salvo(site_url(self::LISTA . "/{$id}"), $mensagem);
    }

    /**
     * Todos que veem o chamado (carregar() já conferiu) registram movimentações/comentários, enquanto ele
     * não está encerrado.
     *
     * @param array<string, mixed> $chamado
     */
    private function podeMovimentar(array $chamado): bool
    {
        return Chamados::aceitaMovimentacao((string) $chamado['status']);
    }

    /**
     * @return array{0: array<string, mixed>, 1: ResponseInterface|RedirectResponse|null}
     */
    private function carregarParaMovimentar(int $id): array
    {
        [$chamado, $recusa] = $this->carregar($id);

        if ($recusa === null && ! $this->podeMovimentar($chamado)) {
            $recusa = $this->negar();
        }

        return [$chamado, $recusa];
    }

    /**
     * @param array<string, mixed>  $chamado
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    private function formularioMovimentacao(
        array $chamado,
        array $dados,
        array $erros = [],
        int $status = 200
    ): ResponseInterface|string {
        return $this->telaFormulario('chamados/chamados/movimentacao', [
            'chamado' => $chamado,
            'dados'   => $dados,
        ], $erros, $status);
    }

    /** Ambiente inexistente ou fora do escopo do nível pedido: a resposta de recusa; null = pode. */
    private function recusaNoAmbiente(int $ambienteId, string $nivel): ResponseInterface|RedirectResponse|null
    {
        if ($this->locais->local($ambienteId, null) === null) {
            return redirect()->to(site_url('campus'))->with('erro', lang('Ambientes.notFound'));
        }

        return $this->podeNoAmbiente($ambienteId, $nivel) ? null : $this->negar();
    }

    private function novaTabela(): ChamadosTabela
    {
        return new ChamadosTabela($this->escopo(), $this->usuarioId());
    }

    /** Ambientes onde o usuário pode abrir chamado (editor): a mesma fonte do select do formulário. */
    private function ambientesParaAbrir(): Fonte
    {
        return service('opcoes')->ambientes($this->escopo(Autorizacao::EDITOR));
    }

    /**
     * Para onde o usuário pode levar o chamado: todas as transições válidas com chamados.resolver_cancelar
     * no ambiente; senão, quem abriu só cancela o próprio enquanto está aberto.
     *
     * @param array<string, mixed> $chamado
     *
     * @return list<string>
     */
    private function statusPermitidos(array $chamado): array
    {
        $status = (string) $chamado['status'];

        if ($this->podeNoAmbiente((int) $chamado['ambiente_id'], Autorizacao::EDITOR, self::RESOLVER)) {
            return Chamados::proximos($status);
        }

        if ($status === 'aberto' && (int) $chamado['aberto_por_id'] === $this->usuarioId()) {
            return ['cancelado'];
        }

        return [];
    }

    /**
     * Chamado que o usuário pode ver: ambiente no escopo de leitura, ou aberto por ele.
     *
     * @return array{0: array<string, mixed>, 1: ResponseInterface|RedirectResponse|null}
     */
    private function carregar(int $id): array
    {
        $chamado = db_connect()->table('chamados c')
            ->select('c.*, u.nome AS aberto_por')
            ->join('usuarios u', 'u.id_usuario = c.aberto_por_id')
            ->where('c.id_chamado', $id)
            ->where('c.deletado_em', null)
            ->get()
            ->getRowArray();

        if ($chamado === null) {
            return [[], redirect()->to(site_url(self::LISTA))->with('erro', lang('Chamados.ticketNotFound'))];
        }

        $visivel = (int) $chamado['aberto_por_id'] === $this->usuarioId()
            || $this->podeNoAmbiente((int) $chamado['ambiente_id']);

        return [$chamado, $visivel ? null : $this->negar()];
    }

    /**
     * @return array{0: array<string, mixed>, 1: ResponseInterface|RedirectResponse|null}
     */
    private function carregarParaMudar(int $id): array
    {
        [$chamado, $recusa] = $this->carregar($id);

        if ($recusa === null && $this->statusPermitidos($chamado) === []) {
            $recusa = $this->negar();
        }

        return [$chamado, $recusa];
    }

    /**
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    private function formulario(array $dados, array $erros = [], int $status = 200): ResponseInterface|string
    {
        $fixo = (string) ($dados['ambiente_fixo'] ?? '') === '1' && ctype_digit((string) ($dados['ambiente_id'] ?? ''));

        return $this->telaFormulario('chamados/chamados', [
            'dados'          => $dados,
            'ambienteRotulo' => $fixo ? $this->locais->rotulo((int) $dados['ambiente_id'], null) : null,
        ], $erros, $status);
    }

    /**
     * @param array<string, mixed>  $chamado
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    private function formularioSituacao(
        array $chamado,
        array $dados,
        array $erros = [],
        int $status = 200
    ): ResponseInterface|string {
        return $this->telaFormulario('chamados/chamados/situacao', [
            'chamado'    => $chamado,
            'dados'      => $dados,
            'permitidos' => opcoes_traduzidas('Chamados.status_', $this->statusPermitidos($chamado)),
        ], $erros, $status);
    }
}

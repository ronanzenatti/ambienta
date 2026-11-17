<?php

namespace App\Controllers\Agendamento;

use App\Libraries\Agendamento\Bloqueios as BloqueiosServico;
use App\Libraries\Agendamento\Recusa;
use App\Libraries\Autorizacao;
use App\Libraries\Opcoes\Fonte;
use App\Tabelas\Agendamento\BloqueiosTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Bloqueios de ambiente: listagem (leitura de agendamento) e cadastro em modal por quem gere o ambiente
 * (institucional.acesso editor). Criar cancela as reservas e excepciona as alocações do período.
 */
class Bloqueios extends ControladorAgendamento
{
    private const LISTA = 'agendamento/bloqueios';

    public function index(): string
    {
        $tabela = $this->tabela($this->novaTabela());

        return $this->pedidoParcial() ? $tabela->fragmento() : view('patrimonio/_lista', [
            'tabela' => $tabela,
            'titulo' => lang('Agendamento.blocksTitle'),
            'intro'  => lang('Agendamento.blocksIntro'),
            'novo'   => $this->ambientesGeridos()->existe()
                ? [lang('Agendamento.blockNew'), site_url(self::LISTA . '/novo')]
                : null,
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela($this->novaTabela());
    }

    public function novo(): ResponseInterface|string
    {
        if (! $this->ambientesGeridos()->existe()) {
            return $this->negar();
        }

        return $this->formulario(['ambiente_id' => (string) $this->request->getGet('ambiente')]);
    }

    public function criar(): ResponseInterface|RedirectResponse|string
    {
        $dados = [
            'ambiente_id' => $this->texto('ambiente_id'),
            'tipo'        => $this->texto('tipo'),
            'motivo'      => $this->texto('motivo'),
            'inicio'      => $this->texto('inicio'),
            'fim'         => $this->texto('fim'),
        ];
        $erros = $this->validar($dados, [
            'ambiente_id' => $this->regra('Agendamento.fieldEnvironment', 'required|is_natural_no_zero'),
            'tipo'        => $this->regra(
                'Agendamento.fieldBlockType',
                'required|in_list[' . implode(',', BloqueiosServico::TIPOS) . ']'
            ),
            'motivo'      => $this->regra('Agendamento.fieldReason', 'required|max_length[5000]'),
            'inicio'      => $this->regra('Agendamento.fieldStart', 'required'),
            'fim'         => $this->regra('Agendamento.fieldEnd', 'required'),
        ]);

        $gerido = $this->ambientesGeridos()->contem((int) $dados['ambiente_id']);

        if (! isset($erros['ambiente_id']) && ! $gerido) {
            $erros['ambiente_id'] = lang('Agendamento.environmentInvalid');
        }

        $inicio = $this->dataHoraLocal($dados['inicio']);
        $fim    = $this->dataHoraLocal($dados['fim']);

        if (! isset($erros['inicio']) && $inicio === null) {
            $erros['inicio'] = lang('Agendamento.dateTimeInvalid');
        }

        if (! isset($erros['fim']) && $fim === null) {
            $erros['fim'] = lang('Agendamento.dateTimeInvalid');
        }

        $resultado = null;

        if ($erros === [] && $inicio !== null && $fim !== null) {
            try {
                $resultado = (new BloqueiosServico(db_connect()))->criar(
                    (int) $dados['ambiente_id'],
                    $dados['tipo'],
                    $dados['motivo'],
                    $inicio,
                    $fim,
                    $this->usuarioId()
                );
            } catch (Recusa $recusa) {
                $erros['fim'] = $recusa->texto();
            }
        }

        if ($erros !== [] || $resultado === null) {
            return $this->formulario($dados, $erros, 422);
        }

        return $this->salvo(
            site_url(self::LISTA),
            lang('Agendamento.blockCreated', [$resultado['reservas'], $resultado['excecoes']])
        );
    }

    public function excluir(int $id): RedirectResponse|ResponseInterface
    {
        $lista    = redirect()->to(site_url(self::LISTA));
        $bloqueio = db_connect()->table('bloqueios_ambientes')
            ->where('id_bloqueio_ambiente', $id)
            ->where('deletado_em', null)
            ->get()
            ->getRowArray();

        if ($bloqueio === null) {
            return $lista->with('erro', lang('Agendamento.blockNotFound'));
        }

        if (! $this->podeNoAmbiente((int) $bloqueio['ambiente_id'], Autorizacao::EDITOR, self::GESTAO)) {
            return $this->negar();
        }

        if (! $this->exclusaoConfirmada()) {
            return $lista->with('erro', lang('Ui.confirmMismatch'));
        }

        (new BloqueiosServico(db_connect()))->excluir($id);

        return $lista->with('sucesso', lang('Agendamento.blockDeleted'));
    }

    private function novaTabela(): BloqueiosTabela
    {
        return new BloqueiosTabela(
            $this->escopo(),
            fn (int $ambienteId): bool => $this->podeNoAmbiente($ambienteId, Autorizacao::EDITOR, self::GESTAO)
        );
    }

    /** Ambientes que o usuário gere (pode bloquear): a mesma fonte do select do formulário. */
    private function ambientesGeridos(): Fonte
    {
        return service('opcoes')->ambientes($this->escopo(Autorizacao::EDITOR, self::GESTAO));
    }

    /** Valor de <input type="datetime-local"> (AAAA-MM-DDTHH:MM). */
    private function dataHoraLocal(string $valor): ?\DateTimeImmutable
    {
        $partes = explode('T', $valor);

        return count($partes) === 2 ? $this->dataHora($partes[0], $partes[1]) : null;
    }

    /**
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    private function formulario(array $dados, array $erros = [], int $status = 200): ResponseInterface|string
    {
        return $this->telaFormulario('agendamento/bloqueios', ['dados' => $dados], $erros, $status);
    }
}

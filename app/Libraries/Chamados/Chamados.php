<?php

namespace App\Libraries\Chamados;

use App\Libraries\Auditoria\Auditoria;
use App\Libraries\Notificacoes\Destinatarios;
use App\Libraries\Notificacoes\Notificador;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Chamados de manutenção: ciclo de vida fechado (aberto → em andamento → concluído/cancelado; concluído e
 * cancelado são finais) e histórico de toda mudança de status, sempre com observação. A abertura grava o
 * primeiro registro do histórico (status_anterior NULL). Movimentações (andamentos sem mudar a situação)
 * entram no mesmo histórico com status anterior = novo.
 *
 * Com alocação semestral vinculada: concluir libera a alocação (pendente_adequacao → ativa); cancelar
 * não depende de ninguém, mas marca a alocação com a observação crítica.
 *
 * O chamado é travado com FOR UPDATE: duas mudanças simultâneas não gravam histórico inconsistente.
 *
 * A abertura avisa quem resolve chamados no ambiente; cada mudança de status avisa quem abriu.
 */
final class Chamados
{
    public const STATUS = ['aberto', 'em_andamento', 'concluido', 'cancelado'];

    /** @var array<string, list<string>> status atual => para onde pode ir */
    private const TRANSICOES = [
        'aberto'       => ['em_andamento', 'concluido', 'cancelado'],
        'em_andamento' => ['concluido', 'cancelado'],
        'concluido'    => [],
        'cancelado'    => [],
    ];

    private readonly Auditoria $auditoria;
    private readonly Notificador $notificador;

    public function __construct(private readonly BaseConnection $db)
    {
        $this->auditoria   = new Auditoria($db);
        $this->notificador = new Notificador($db);
    }

    /** @return list<string> */
    public static function proximos(string $status): array
    {
        return self::TRANSICOES[$status] ?? [];
    }

    public function abrir(
        int $ambienteId,
        string $descricao,
        int $usuarioId,
        ?int $alocacaoId = null,
        ?int $reservaId = null
    ): int {
        return $this->emTransacao(function () use ($ambienteId, $descricao, $usuarioId, $alocacaoId, $reservaId): int {
            $this->db->table('chamados')->insert([
                'ambiente_id'           => $ambienteId,
                'alocacao_semestral_id' => $alocacaoId,
                'reserva_pontual_id'    => $reservaId,
                'aberto_por_id'         => $usuarioId,
                'status'                => 'aberto',
                'descricao'             => $descricao,
            ]);

            $id = (int) $this->db->insertID();

            $this->registrar($id, $usuarioId, null, 'aberto', $descricao);
            $this->auditoria->registrarDepois('chamados', $id, null);
            $this->notificador->notificar(
                (new Destinatarios($this->db))->noAmbiente('chamados.resolver_cancelar', $ambienteId),
                'chamado_aberto',
                'chamado',
                $id,
                [
                    'numero'   => (string) $id,
                    'ambiente' => $this->notificador->ambiente($ambienteId),
                    'autor'    => $this->notificador->nome($usuarioId),
                ],
                $usuarioId
            );

            return $id;
        });
    }

    /** @throws Recusa */
    public function mudarStatus(int $chamadoId, string $novo, string $observacao, int $usuarioId): void
    {
        if (trim($observacao) === '') {
            throw new Recusa('Chamados.noteRequired');
        }

        $this->emTransacao(function () use ($chamadoId, $novo, $observacao, $usuarioId): void {
            $chamado = $this->db->query(
                'SELECT * FROM chamados
                 WHERE id_chamado = ? AND deletado_em IS NULL FOR UPDATE',
                [$chamadoId]
            )->getRowArray();

            if ($chamado === null) {
                throw new Recusa('Chamados.ticketNotFound');
            }

            $atual = (string) $chamado['status'];

            if (! in_array($novo, self::proximos($atual), true)) {
                throw new Recusa('Chamados.transitionInvalid');
            }

            $this->db->query('UPDATE chamados SET status = ? WHERE id_chamado = ?', [$novo, $chamadoId]);
            $this->registrar($chamadoId, $usuarioId, $atual, $novo, $observacao);
            $this->auditoria->registrarDepois('chamados', $chamadoId, $chamado);

            if ($chamado['alocacao_semestral_id'] !== null) {
                $this->refletirNaAlocacao((int) $chamado['alocacao_semestral_id'], $novo, $observacao);
            }

            $this->notificador->notificar([(int) $chamado['aberto_por_id']], 'chamado_status', 'chamado', $chamadoId, [
                'numero'     => (string) $chamadoId,
                'ambiente'   => $this->notificador->ambiente((int) $chamado['ambiente_id']),
                'status'     => $novo,
                'autor'      => $this->notificador->nome($usuarioId),
                'observacao' => $observacao,
            ], $usuarioId);
        });
    }

    /**
     * Movimentação: registra um andamento (equipamento verificado, aguardando peças…) sem mudar a situação.
     * Vai para o histórico com status anterior = novo = o atual. Só em chamado ainda em curso.
     *
     * @throws Recusa
     */
    public function movimentar(int $chamadoId, string $observacao, int $usuarioId): void
    {
        if (trim($observacao) === '') {
            throw new Recusa('Chamados.noteRequired');
        }

        $this->emTransacao(function () use ($chamadoId, $observacao, $usuarioId): void {
            $chamado = $this->db->query(
                'SELECT * FROM chamados
                 WHERE id_chamado = ? AND deletado_em IS NULL FOR UPDATE',
                [$chamadoId]
            )->getRowArray();

            if ($chamado === null) {
                throw new Recusa('Chamados.ticketNotFound');
            }

            $atual = (string) $chamado['status'];

            if (! self::aceitaMovimentacao($atual)) {
                throw new Recusa('Chamados.movementInvalid');
            }

            $this->registrar($chamadoId, $usuarioId, $atual, $atual, $observacao);
            // Sem mudança de status o UPDATE ... SET status = status não tocaria alterado_em: força o carimbo.
            $this->db->query('UPDATE chamados SET alterado_em = NOW() WHERE id_chamado = ?', [$chamadoId]);
            $this->auditoria->registrarDepois('chamados', $chamadoId, $chamado);
        });
    }

    /** Movimentação só enquanto o chamado não está encerrado (concluído/cancelado). */
    public static function aceitaMovimentacao(string $status): bool
    {
        return self::proximos($status) !== [];
    }

    private function refletirNaAlocacao(int $alocacaoId, string $status, string $observacao): void
    {
        $antes = $this->auditoria->ler('alocacoes_semestrais', $alocacaoId);

        if ($status === 'concluido') {
            $this->db->query(
                "UPDATE alocacoes_semestrais SET status = 'ativa'
                 WHERE id_alocacao_semestral = ? AND status = 'pendente_adequacao' AND deletado_em IS NULL",
                [$alocacaoId]
            );
        } elseif ($status === 'cancelado') {
            $this->db->query(
                'UPDATE alocacoes_semestrais SET observacao_critica = ?
                 WHERE id_alocacao_semestral = ? AND deletado_em IS NULL',
                [$observacao, $alocacaoId]
            );
        }

        $this->auditoria->registrarDepois('alocacoes_semestrais', $alocacaoId, $antes);
    }

    private function registrar(
        int $chamadoId,
        int $usuarioId,
        ?string $anterior,
        string $novo,
        string $observacao
    ): void {
        $this->db->table('chamados_historico')->insert([
            'chamado_id'      => $chamadoId,
            'usuario_id'      => $usuarioId,
            'status_anterior' => $anterior,
            'status_novo'     => $novo,
            'observacao'      => $observacao,
        ]);
    }

    /**
     * @template T
     *
     * @param callable(): T $operacao
     *
     * @return T
     */
    private function emTransacao(callable $operacao): mixed
    {
        $this->db->transBegin();

        try {
            $resultado = $operacao();
            $this->db->transCommit();

            return $resultado;
        } catch (Throwable $erro) {
            $this->db->transRollback();

            throw $erro;
        }
    }
}

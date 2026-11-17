<?php

namespace App\Libraries\Agendamento;

use App\Libraries\Auditoria\Auditoria;
use App\Libraries\Notificacoes\Notificador;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use Throwable;

/**
 * Bloqueio de ambiente (REQUISITOS §8): sempre tem prioridade sobre o que já existe. Ao criar, as reservas
 * sobrepostas são canceladas (justificativa com quem bloqueou e por quê) e as alocações semestrais que
 * caem no período ganham exceção 'bloqueio_ambiente' nos dias afetados. Tudo numa transação.
 *
 * Os envolvidos (quem reservou, o beneficiário e o professor das alocações afetadas) são notificados da
 * indisponibilidade, de quem bloqueou e por quê.
 */
final class Bloqueios
{
    public const TIPOS = ['manutencao', 'outro'];

    /** Maior período de um bloqueio (dias), para limitar as exceções geradas numa requisição. */
    public const MAXIMO_DIAS = 366;

    private readonly Auditoria $auditoria;

    public function __construct(private readonly BaseConnection $db)
    {
        $this->auditoria = new Auditoria($db);
    }

    /**
     * @return array{id: int, reservas: int, excecoes: int}
     *
     * @throws Recusa
     */
    public function criar(
        int $ambienteId,
        string $tipo,
        string $motivo,
        DateTimeImmutable $inicio,
        DateTimeImmutable $fim,
        int $usuarioId
    ): array {
        if ($fim <= $inicio) {
            throw new Recusa('Agendamento.endBeforeStart');
        }

        if ($inicio->diff($fim)->days > self::MAXIMO_DIAS) {
            throw new Recusa('Agendamento.blockTooLong', [self::MAXIMO_DIAS]);
        }

        $usuario = $this->db->query('SELECT nome FROM usuarios WHERE id_usuario = ?', [$usuarioId])->getRow();
        $nome    = (string) ($usuario->nome ?? '');

        $this->db->transBegin();

        try {
            $this->db->table('bloqueios_ambientes')->insert([
                'ambiente_id'      => $ambienteId,
                'tipo'             => $tipo,
                'motivo'           => $motivo,
                'data_hora_inicio' => $inicio->format('Y-m-d H:i:s'),
                'data_hora_fim'    => $fim->format('Y-m-d H:i:s'),
                'criado_por_id'    => $usuarioId,
            ]);
            $id = (int) $this->db->insertID();
            $this->auditoria->registrarDepois('bloqueios_ambientes', $id, null);

            [$reservas, $pessoas] = $this->cancelarReservas($ambienteId, $inicio, $fim, $usuarioId, lang(
                'Agendamento.blockJustification',
                [$nome, lang('Agendamento.blockType_' . $tipo), $motivo]
            ));
            [$excecoes, $professores] = $this->excepcionarAlocacoes($ambienteId, $inicio, $fim, $motivo);

            $notificador = new Notificador($this->db);
            $notificador->notificar([...$pessoas, ...$professores], 'bloqueio_ambiente', 'bloqueio_ambiente', $id, [
                'ambiente'      => $notificador->ambiente($ambienteId),
                'autor'         => $nome,
                'tipo_bloqueio' => $tipo,
                'motivo'        => $motivo,
                'inicio'        => $inicio->format('Y-m-d H:i:s'),
                'fim'           => $fim->format('Y-m-d H:i:s'),
            ], $usuarioId);

            $this->db->transCommit();
        } catch (Throwable $erro) {
            $this->db->transRollback();

            throw $erro;
        }

        return ['id' => $id, 'reservas' => $reservas, 'excecoes' => $excecoes];
    }

    /**
     * Remove o bloqueio (soft delete) e devolve às alocações os dias que só ele excepcionava. Reservas
     * canceladas pelo bloqueio continuam canceladas (quem reservou é que decide reservar de novo).
     */
    public function excluir(int $bloqueioId): void
    {
        $bloqueio = $this->db->table('bloqueios_ambientes')
            ->where('id_bloqueio_ambiente', $bloqueioId)
            ->where('deletado_em', null)
            ->get()
            ->getRowArray();

        if ($bloqueio === null) {
            throw new Recusa('Agendamento.blockNotFound');
        }

        $this->db->transBegin();

        try {
            $this->db->query(
                'UPDATE bloqueios_ambientes SET deletado_em = NOW() WHERE id_bloqueio_ambiente = ?',
                [$bloqueioId]
            );
            $this->auditoria->registrarDepois('bloqueios_ambientes', $bloqueioId, $bloqueio);

            // Exceção por bloqueio neste período que nenhum outro bloqueio ativo do ambiente ainda cobre.
            $this->db->query(
                "UPDATE alocacoes_semestrais_excecoes e
                 JOIN alocacoes_semestrais a ON a.id_alocacao_semestral = e.alocacao_semestral_id
                 SET e.deletado_em = NOW()
                 WHERE a.ambiente_id = ? AND e.motivo = 'bloqueio_ambiente' AND e.deletado_em IS NULL
                   AND e.data_excecao BETWEEN DATE(?) AND DATE(?)
                   AND NOT EXISTS (SELECT 1 FROM bloqueios_ambientes b
                                   WHERE b.ambiente_id = a.ambiente_id AND b.deletado_em IS NULL
                                     AND DATE(b.data_hora_inicio) <= e.data_excecao
                                     AND DATE(b.data_hora_fim) >= e.data_excecao)",
                [(int) $bloqueio['ambiente_id'], $bloqueio['data_hora_inicio'], $bloqueio['data_hora_fim']]
            );

            $this->db->transCommit();
        } catch (Throwable $erro) {
            $this->db->transRollback();

            throw $erro;
        }
    }

    /**
     * @return array{0: int, 1: list<int>} [quantas foram canceladas, quem reservou e beneficiários]
     */
    private function cancelarReservas(
        int $ambienteId,
        DateTimeImmutable $inicio,
        DateTimeImmutable $fim,
        int $usuarioId,
        string $justificativa
    ): array {
        $afetadas = $this->db->query(
            "SELECT * FROM reservas_pontuais
             WHERE ambiente_id = ? AND deletado_em IS NULL AND status <> 'cancelada'
               AND data_hora_inicio < ? AND data_hora_fim > ? FOR UPDATE",
            [$ambienteId, $fim->format('Y-m-d H:i:s'), $inicio->format('Y-m-d H:i:s')]
        )->getResultArray();
        $pessoas = [];

        foreach ($afetadas as $reserva) {
            $id = (int) $reserva['id_reserva_pontual'];

            $this->db->query(
                "UPDATE reservas_pontuais
                 SET status = 'cancelada', justificativa_cancelamento = ?, cancelado_por_id = ?,
                     data_cancelamento = NOW()
                 WHERE id_reserva_pontual = ?",
                [$justificativa, $usuarioId, $id]
            );
            $this->auditoria->registrarDepois('reservas_pontuais', $id, $reserva);

            $pessoas[] = (int) $reserva['solicitante_id'];
            $pessoas[] = (int) ($reserva['beneficiario_id'] ?? 0);
        }

        return [count($afetadas), $pessoas];
    }

    /**
     * Uma exceção por alocação e por dia do período em que ela ocupa o ambiente no horário bloqueado.
     *
     * @return array{0: int, 1: list<int>} [quantas exceções, professores das alocações afetadas]
     */
    private function excepcionarAlocacoes(
        int $ambienteId,
        DateTimeImmutable $inicio,
        DateTimeImmutable $fim,
        string $motivo
    ): array {
        $total     = 0;
        $alocacoes = [];

        for ($dia = $inicio->setTime(0, 0); $dia < $fim; $dia = $dia->modify('+1 day')) {
            $deHora  = max($inicio, $dia);
            $ateHora = min($fim, $dia->modify('+1 day'));

            foreach (Alocacoes::ocupandoNoDia($this->db, $ambienteId, $deHora, $ateHora) as $alocacaoId) {
                $this->db->table('alocacoes_semestrais_excecoes')->insert([
                    'alocacao_semestral_id' => $alocacaoId,
                    'data_excecao'          => $dia->format('Y-m-d'),
                    'motivo'                => 'bloqueio_ambiente',
                    'observacao'            => $motivo,
                ]);
                $total++;
                $alocacoes[$alocacaoId] = true;
            }
        }

        if ($alocacoes === []) {
            return [0, []];
        }

        $professores = $this->db->table('alocacoes_semestrais')
            ->select('professor_id')
            ->whereIn('id_alocacao_semestral', array_keys($alocacoes))
            ->get()
            ->getResultArray();

        return [$total, array_map('intval', array_column($professores, 'professor_id'))];
    }
}

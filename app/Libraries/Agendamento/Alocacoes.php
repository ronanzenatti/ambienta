<?php

namespace App\Libraries\Agendamento;

use App\Libraries\Auditoria\Auditoria;
use App\Libraries\Chamados\Chamados;
use App\Libraries\Chamados\Atendimento;
use App\Libraries\Notificacoes\Destinatarios;
use App\Libraries\Notificacoes\Notificador;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/**
 * Alocação semestral (REQUISITOS §6). A criação passa por sp_criar_alocacao_semestral, que trava o
 * ambiente/dia/horário no banco. Antes dela, aqui: disciplina já alocada em outro ambiente no mesmo horário
 * (só com ciência explícita) e, depois, o atendimento do ambiente aos requisitos do professor: não sendo
 * integral, abre sozinha um chamado de adequação e a alocação fica pendente de adequação.
 *
 * O professor é avisado quando outra pessoa aloca ou cancela a alocação dele; alocação longa (6h, 12h, 24h)
 * avisa quem solicitou, o professor e quem gere o ambiente.
 *
 * @phpstan-type Dados array{
 *     semestre_id: int, disciplina_id: int, professor_id: int, ambiente_id: int,
 *     dia_semana: string, hora_inicio: string, hora_fim: string
 * }
 */
final class Alocacoes
{
    public const DIAS   = ['segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado', 'domingo'];
    public const STATUS = ['ativa', 'pendente_adequacao', 'cancelada'];

    /** Durações (horas) que geram alerta de alocação longa. */
    public const ALERTAS_DURACAO = [24, 12, 6];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** Dia da semana (valor do ENUM) de uma data. */
    public static function diaDaSemana(DateTimeImmutable $data): string
    {
        return self::DIAS[((int) $data->format('N')) - 1];
    }

    /**
     * Alocações ativas do ambiente que ocupam o horário naquela data (semestre vigente na data, mesmo dia da
     * semana, horário sobreposto, sem exceção no dia).
     *
     * @return list<int>
     */
    public static function ocupandoNoDia(
        BaseConnection $db,
        int $ambienteId,
        DateTimeImmutable $inicio,
        DateTimeImmutable $fim
    ): array {
        $data    = $inicio->format('Y-m-d');
        $horaFim = $fim->format('Y-m-d') > $data ? '24:00:00' : $fim->format('H:i:s');

        $linhas = $db->query(
            "SELECT a.id_alocacao_semestral FROM alocacoes_semestrais a
             JOIN semestres s ON s.id_semestre = a.semestre_id
             WHERE a.ambiente_id = ? AND a.deletado_em IS NULL AND a.status IN ('ativa','pendente_adequacao')
               AND a.dia_semana = ? AND a.hora_inicio < ? AND a.hora_fim > ?
               AND s.data_inicio <= ? AND s.data_fim >= ?
               AND NOT EXISTS (SELECT 1 FROM alocacoes_semestrais_excecoes e
                               WHERE e.alocacao_semestral_id = a.id_alocacao_semestral
                                 AND e.data_excecao = ? AND e.deletado_em IS NULL)",
            [$ambienteId, self::diaDaSemana($inicio), $horaFim, $inicio->format('H:i:s'), $data, $data, $data]
        )->getResultArray();

        return array_map(static fn (array $l): int => (int) $l['id_alocacao_semestral'], $linhas);
    }

    /**
     * Alerta de duração longa (6h, 12h, 24h): o maior limiar atingido, ou null.
     */
    public static function alertaDuracao(string $horaInicio, string $horaFim): ?int
    {
        $segundos = strtotime('1970-01-01 ' . $horaFim . ' UTC') - strtotime('1970-01-01 ' . $horaInicio . ' UTC');
        $horas    = $segundos / 3600;

        foreach (self::ALERTAS_DURACAO as $limiar) {
            if ($horas >= $limiar) {
                return $limiar;
            }
        }

        return null;
    }

    /**
     * Outros ambientes em que a mesma disciplina (mesmo semestre) já está no mesmo dia e horário.
     *
     * @param Dados $dados
     *
     * @return list<array<string, mixed>>
     */
    public function mesmaDisciplinaEmOutroAmbiente(array $dados): array
    {
        return $this->db->query(
            "SELECT a.id_alocacao_semestral, a.hora_inicio, a.hora_fim, am.numero, am.nome
             FROM alocacoes_semestrais a
             JOIN ambientes am ON am.id_ambiente = a.ambiente_id
             WHERE a.semestre_id = ? AND a.disciplina_id = ? AND a.dia_semana = ?
               AND a.ambiente_id <> ? AND a.deletado_em IS NULL AND a.status <> 'cancelada'
               AND a.hora_inicio < ? AND a.hora_fim > ?",
            [
                $dados['semestre_id'], $dados['disciplina_id'], $dados['dia_semana'],
                $dados['ambiente_id'], $dados['hora_fim'], $dados['hora_inicio'],
            ]
        )->getResultArray();
    }

    /**
     * Cria a alocação.
     *
     * @param Dados $dados
     *
     * @return array{id: int, pendencias: list<array<string, mixed>>, chamado_id: ?int}
     *
     * @throws Recusa
     */
    public function criar(array $dados, int $usuarioId, bool $ciencia): array
    {
        if ($dados['hora_fim'] <= $dados['hora_inicio']) {
            throw new Recusa('Agendamento.endBeforeStart');
        }

        if (! in_array($dados['dia_semana'], self::DIAS, true)) {
            throw new Recusa('Agendamento.weekdayInvalid');
        }

        $semestre = $this->db->table('semestres')
            ->where('id_semestre', $dados['semestre_id'])
            ->where('deletado_em', null)
            ->get()
            ->getRowArray();

        if ($semestre === null || $semestre['status'] === 'encerrado') {
            throw new Recusa('Agendamento.semesterInvalid');
        }

        $vinculado = $this->db->table('professores_disciplinas')
            ->where('semestre_id', $dados['semestre_id'])
            ->where('disciplina_id', $dados['disciplina_id'])
            ->where('professor_id', $dados['professor_id'])
            ->where('deletado_em', null)
            ->countAllResults() > 0;

        if (! $vinculado) {
            throw new Recusa('Agendamento.teacherNotLinked');
        }

        $outros = $this->mesmaDisciplinaEmOutroAmbiente($dados);

        if ($outros !== [] && ! $ciencia) {
            throw new Recusa('Agendamento.awarenessRequired');
        }

        $this->db->query(
            'CALL sp_criar_alocacao_semestral(?, ?, ?, ?, ?, ?, ?, @ambienta_id, @ambienta_conflito)',
            [
                $dados['semestre_id'], $dados['disciplina_id'], $dados['professor_id'],
                $dados['ambiente_id'], $dados['dia_semana'], $dados['hora_inicio'], $dados['hora_fim'],
            ]
        );

        $saida = $this->db->query('SELECT @ambienta_id AS id, @ambienta_conflito AS conflito')->getRowArray();

        if ((bool) $saida['conflito'] || $saida['id'] === null) {
            throw new Recusa('Agendamento.allocationConflict');
        }

        $id          = (int) $saida['id'];
        $auditoria   = new Auditoria($this->db);
        $notificador = new Notificador($this->db);

        $auditoria->registrarDepois('alocacoes_semestrais', $id, null);
        $parametros = $this->parametros($id, $usuarioId);
        $notificador->notificar(
            [$dados['professor_id']],
            'alocacao_criada',
            'alocacao_semestral',
            $id,
            $parametros,
            $usuarioId
        );

        $horas = self::alertaDuracao($dados['hora_inicio'], $dados['hora_fim']);

        if ($horas !== null) {
            $gestores = (new Destinatarios($this->db))->noAmbiente('institucional.acesso', $dados['ambiente_id']);
            $notificador->notificar(
                [$usuarioId, $dados['professor_id'], ...$gestores],
                'alocacao_longa',
                'alocacao_semestral',
                $id,
                $parametros + ['horas' => $horas]
            );
        }

        if ($outros !== []) {
            $this->db->query(
                'UPDATE alocacoes_semestrais SET ciencia_conflito_horario_em = NOW() WHERE id_alocacao_semestral = ?',
                [$id]
            );
        }

        $avaliacao  = (new Atendimento($this->db))
            ->avaliar($dados['professor_id'], $dados['disciplina_id'], $dados['ambiente_id']);
        $pendencias = $avaliacao['pendencias'];
        $chamadoId  = null;

        if ($avaliacao['nivel'] !== Atendimento::INTEGRAL) {
            $chamadoId = (new Chamados($this->db))->abrir(
                $dados['ambiente_id'],
                $this->descricaoChamado($pendencias),
                $usuarioId,
                $id
            );
            $this->db->query(
                "UPDATE alocacoes_semestrais SET status = 'pendente_adequacao' WHERE id_alocacao_semestral = ?",
                [$id]
            );
        }

        return ['id' => $id, 'pendencias' => $pendencias, 'chamado_id' => $chamadoId];
    }

    /** Cancela a alocação (fica no histórico; o horário é liberado). $usuarioId: quem cancelou. */
    public function cancelar(int $id, ?int $usuarioId = null): void
    {
        $auditoria = new Auditoria($this->db);
        $antes     = $auditoria->ler('alocacoes_semestrais', $id);

        $this->db->query(
            "UPDATE alocacoes_semestrais SET status = 'cancelada'
             WHERE id_alocacao_semestral = ? AND deletado_em IS NULL AND status <> 'cancelada'",
            [$id]
        );

        if ($this->db->affectedRows() === 0 || $antes === null) {
            throw new Recusa('Agendamento.allocationAlreadyCancelled');
        }

        $auditoria->registrarDepois('alocacoes_semestrais', $id, $antes);
        (new Notificador($this->db))->notificar(
            [(int) $antes['professor_id']],
            'alocacao_cancelada',
            'alocacao_semestral',
            $id,
            $this->parametros($id, $usuarioId),
            $usuarioId
        );
    }

    /** Exclusão (soft delete) da alocação. */
    public function excluir(int $id): void
    {
        $auditoria = new Auditoria($this->db);
        $antes     = $auditoria->ler('alocacoes_semestrais', $id);

        $this->db->query(
            'UPDATE alocacoes_semestrais SET deletado_em = NOW()
             WHERE id_alocacao_semestral = ? AND deletado_em IS NULL',
            [$id]
        );

        if ($antes !== null) {
            $auditoria->registrarDepois('alocacoes_semestrais', $id, $antes);
        }
    }

    /**
     * Parâmetros das notificações de uma alocação (disciplina, ambiente, dia, horário, quem fez).
     *
     * @return array<string, string>
     */
    private function parametros(int $id, ?int $usuarioId): array
    {
        $linha = $this->db->query(
            'SELECT a.ambiente_id, a.dia_semana, a.hora_inicio, a.hora_fim, d.sigla, d.nome
             FROM alocacoes_semestrais a JOIN disciplinas d ON d.id_disciplina = a.disciplina_id
             WHERE a.id_alocacao_semestral = ?',
            [$id]
        )->getRowArray() ?? [];

        $notificador = new Notificador($this->db);
        $hora        = static fn (string $campo): string => substr((string) ($linha[$campo] ?? ''), 0, 5);

        return [
            'disciplina' => ($linha['sigla'] ?? '') . ' — ' . ($linha['nome'] ?? ''),
            'ambiente'   => $notificador->ambiente((int) ($linha['ambiente_id'] ?? 0)),
            'dia'        => (string) ($linha['dia_semana'] ?? ''),
            'horario'    => $hora('hora_inicio') . '–' . $hora('hora_fim'),
            'autor'      => $notificador->nome($usuarioId),
        ];
    }

    /**
     * Texto de uma pendência de requisito (mesmas frases da tela de requisitos).
     *
     * @param array<string, mixed> $pendencia
     */
    public static function textoPendencia(array $pendencia): string
    {
        return match ($pendencia['tipo']) {
            'capacidade' => lang('Chamados.gapCapacity', [$pendencia['exigido'], $pendencia['disponivel']]),
            'item'       => lang(
                'Chamados.gapItem',
                [$pendencia['nome'], $pendencia['exigido'], $pendencia['disponivel']]
            ),
            default      => lang('Chamados.gapSoftware', [$pendencia['nome']]),
        };
    }

    /** @param list<array<string, mixed>> $pendencias */
    private function descricaoChamado(array $pendencias): string
    {
        $linhas = [lang('Agendamento.ticketFromAllocation')];

        foreach ($pendencias as $pendencia) {
            $linhas[] = '- ' . self::textoPendencia($pendencia);
        }

        return implode("
", $linhas);
    }
}

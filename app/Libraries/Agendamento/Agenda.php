<?php

namespace App\Libraries\Agendamento;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/**
 * Agenda semanal de um ambiente: alocações semestrais, reservas pontuais (menos as canceladas), bloqueios e
 * feriados, dia a dia. Conflito reserva × alocação ainda não decidido aparece nos dois lados como pendente
 * (signal-pending: é decisão a tomar, não erro), com o id do conflito para os botões Autorizar/Negar.
 *
 * Modo público (agenda do QR Code, sem login): só o que a consulta pública já revela — semestre aberto ou
 * encerrado, primeiro nome do professor, reserva confirmada como "Reservado" (sem título nem pessoa) e bloqueio
 * só pelo tipo; nada de pendências nem ids de conflito.
 *
 * @phpstan-type Evento array{
 *     tipo: string, inicio: string, fim: string, titulo: string, detalhe: string, status: string,
 *     id: int, conflito_id: ?int, excecao: ?string
 * }
 * @phpstan-type Dia array{data: DateTimeImmutable, feriado: ?string, eventos: list<Evento>}
 */
final class Agenda
{
    /** Primeiro nome do professor (u = usuarios), como na consulta pública; anonimizado não tem nome exibido. */
    public const PRIMEIRO_NOME = "CASE WHEN u.anonimizado_em IS NULL
        THEN SUBSTRING_INDEX(TRIM(u.nome), ' ', 1) ELSE NULL END";

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** Segunda-feira da semana da data. */
    public static function segunda(DateTimeImmutable $data): DateTimeImmutable
    {
        return $data->setTime(0, 0)->modify('-' . ((int) $data->format('N') - 1) . ' days');
    }

    /** @return list<Dia> */
    public function semana(int $ambienteId, DateTimeImmutable $segunda, bool $publica = false): array
    {
        $inicio = $segunda->format('Y-m-d 00:00:00');
        $fim    = $segunda->modify('+7 days')->format('Y-m-d 00:00:00');

        $campusId  = (int) ($this->db->query(
            'SELECT p.campus_id FROM ambientes a JOIN predios p ON p.id_predio = a.predio_id WHERE a.id_ambiente = ?',
            [$ambienteId]
        )->getRow()->campus_id ?? 0);
        $reservas  = $this->reservas($ambienteId, $inicio, $fim, $publica);
        $bloqueios = $this->bloqueios($ambienteId, $inicio, $fim);
        $feriados  = $this->feriados($campusId, $segunda);

        $conflitoDaAlocacao = [];
        foreach ($reservas as $r) {
            if ($r['conflito_id'] !== null) {
                $dia = substr((string) $r['data_hora_inicio'], 0, 10);

                $conflitoDaAlocacao[$dia][(int) $r['alocacao_conflito_id']] = (int) $r['conflito_id'];
            }
        }

        $dias = [];

        for ($i = 0; $i < 7; $i++) {
            $data    = $segunda->modify("+{$i} days");
            $chave   = $data->format('Y-m-d');
            $eventos = [];

            foreach ($this->alocacoesDoDia($ambienteId, $data, $publica) as $a) {
                $conflito  = $conflitoDaAlocacao[$chave][(int) $a['id_alocacao_semestral']] ?? null;
                $eventos[] = [
                    'tipo'        => 'alocacao',
                    'inicio'      => substr((string) $a['hora_inicio'], 0, 5),
                    'fim'         => substr((string) $a['hora_fim'], 0, 5),
                    'titulo'      => $a['sigla'] . ' — ' . $a['disciplina'],
                    'detalhe'     => implode(' · ', array_filter([(string) $a['curso'], (string) $a['professor']])),
                    'status'      => $conflito !== null ? 'em_conflito' : (string) $a['status'],
                    'id'          => (int) $a['id_alocacao_semestral'],
                    'conflito_id' => $conflito,
                    'excecao'     => $a['excecao'],
                ];
            }

            foreach ($reservas as $r) {
                if (substr((string) $r['data_hora_inicio'], 0, 10) !== $chave) {
                    continue;
                }

                $eventos[] = [
                    'tipo'        => 'reserva',
                    'inicio'      => substr((string) $r['data_hora_inicio'], 11, 5),
                    'fim'         => substr((string) $r['data_hora_fim'], 11, 5),
                    'titulo'      => $publica ? lang('Agendamento.publicReserved') : (string) $r['titulo'],
                    'detalhe'     => $publica ? '' : (string) $r['pessoa'],
                    'status'      => (string) $r['status'],
                    'id'          => $publica ? 0 : (int) $r['id_reserva_pontual'],
                    'conflito_id' => $r['conflito_id'] === null ? null : (int) $r['conflito_id'],
                    'excecao'     => null,
                ];
            }

            foreach ($bloqueios as $b) {
                $deDia  = max((string) $b['data_hora_inicio'], $chave . ' 00:00:00');
                $ateDia = min((string) $b['data_hora_fim'], $data->modify('+1 day')->format('Y-m-d 00:00:00'));

                if ($deDia >= $ateDia) {
                    continue;
                }

                $eventos[] = [
                    'tipo'        => 'bloqueio',
                    'inicio'      => substr($deDia, 11, 5),
                    'fim'         => substr($ateDia, 0, 10) > $chave ? '24:00' : substr($ateDia, 11, 5),
                    'titulo'      => lang('Agendamento.blockType_' . $b['tipo']),
                    'detalhe'     => $publica ? '' : (string) $b['motivo'],
                    'status'      => 'bloqueado',
                    'id'          => $publica ? 0 : (int) $b['id_bloqueio_ambiente'],
                    'conflito_id' => null,
                    'excecao'     => null,
                ];
            }

            usort(
                $eventos,
                static fn (array $x, array $y): int => [$x['inicio'], $x['fim']] <=> [$y['inicio'], $y['fim']]
            );

            $dias[] = ['data' => $data, 'feriado' => $feriados[$chave] ?? null, 'eventos' => $eventos];
        }

        return $dias;
    }

    /**
     * Situação de cada ambiente num horário, para a escolha na reserva: null = livre; senão o motivo
     * ('bloqueio', 'reserva' ou 'alocacao'). Ocupado por alocação ainda pode ser escolhido de propósito
     * (vira conflito a autorizar); a procedure é quem decide de verdade na hora de gravar.
     *
     * @param list<int> $ambientes
     *
     * @return array<int, ?string>
     */
    public function situacaoNoHorario(array $ambientes, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $situacao = array_fill_keys($ambientes, null);

        if ($ambientes === []) {
            return $situacao;
        }

        $ids   = implode(', ', array_map('intval', $ambientes));
        $binds = [$fim->format('Y-m-d H:i:s'), $inicio->format('Y-m-d H:i:s')];

        foreach ($ambientes as $id) {
            if (Alocacoes::ocupandoNoDia($this->db, $id, $inicio, $fim) !== []) {
                $situacao[$id] = 'alocacao';
            }
        }

        $reservados = $this->db->query(
            "SELECT DISTINCT ambiente_id FROM reservas_pontuais
             WHERE ambiente_id IN ({$ids}) AND deletado_em IS NULL
               AND status IN ('confirmada','pendente_confirmacao','pendente_autorizacao_conflito')
               AND data_hora_inicio < ? AND data_hora_fim > ?",
            $binds
        )->getResultArray();

        foreach ($reservados as $linha) {
            $situacao[(int) $linha['ambiente_id']] = 'reserva';
        }

        $bloqueados = $this->db->query(
            "SELECT DISTINCT ambiente_id FROM bloqueios_ambientes
             WHERE ambiente_id IN ({$ids}) AND deletado_em IS NULL AND data_hora_inicio < ? AND data_hora_fim > ?",
            $binds
        )->getResultArray();

        foreach ($bloqueados as $linha) {
            $situacao[(int) $linha['ambiente_id']] = 'bloqueio';
        }

        return $situacao;
    }

    /** Tom do indicador de status de um evento da agenda. */
    public static function tom(string $status): string
    {
        return match ($status) {
            'ativa', 'confirmada'                                                           => 'confirmed',
            'bloqueado'                                                                     => 'critical',
            'pendente_confirmacao', 'pendente_autorizacao_conflito', 'em_conflito', 'pendente_adequacao' => 'pending',
            default                                                                         => 'neutral',
        };
    }

    /** @return list<array<string, mixed>> */
    private function alocacoesDoDia(int $ambienteId, DateTimeImmutable $data, bool $publica): array
    {
        $dia       = $data->format('Y-m-d');
        $professor = $publica ? self::PRIMEIRO_NOME : 'u.nome';
        $semestre  = $publica ? " AND s.status <> 'planejado'" : '';

        return $this->db->query(
            "SELECT a.id_alocacao_semestral, a.hora_inicio, a.hora_fim, a.status,
                    d.sigla, d.nome AS disciplina, c.nome AS curso, {$professor} AS professor,
                    (SELECT e.motivo FROM alocacoes_semestrais_excecoes e
                     WHERE e.alocacao_semestral_id = a.id_alocacao_semestral AND e.data_excecao = ?
                       AND e.deletado_em IS NULL LIMIT 1) AS excecao
             FROM alocacoes_semestrais a
             JOIN semestres s   ON s.id_semestre = a.semestre_id AND s.deletado_em IS NULL{$semestre}
             JOIN disciplinas d ON d.id_disciplina = a.disciplina_id
             JOIN cursos c      ON c.id_curso = d.curso_id
             JOIN usuarios u    ON u.id_usuario = a.professor_id
             WHERE a.ambiente_id = ? AND a.deletado_em IS NULL AND a.status <> 'cancelada'
               AND a.dia_semana = ? AND s.data_inicio <= ? AND s.data_fim >= ?",
            [$dia, $ambienteId, Alocacoes::diaDaSemana($data), $dia, $dia]
        )->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    private function reservas(int $ambienteId, string $inicio, string $fim, bool $publica): array
    {
        $status = $publica ? "r.status = 'confirmada'" : "r.status <> 'cancelada'";

        return $this->db->query(
            "SELECT r.id_reserva_pontual, r.titulo, r.data_hora_inicio, r.data_hora_fim, r.status,
                    COALESCE(b.nome, s.nome) AS pessoa,
                    c.id_conflito_agendamento AS conflito_id, c.alocacao_semestral_id AS alocacao_conflito_id
             FROM reservas_pontuais r
             JOIN usuarios s ON s.id_usuario = r.solicitante_id
             LEFT JOIN usuarios b ON b.id_usuario = r.beneficiario_id
             LEFT JOIN conflitos_agendamento c ON c.reserva_pontual_id = r.id_reserva_pontual
                  AND c.deletado_em IS NULL AND c.decisao = 'pendente'
             WHERE r.ambiente_id = ? AND r.deletado_em IS NULL AND {$status}
               AND r.data_hora_inicio < ? AND r.data_hora_fim > ?
             ORDER BY r.data_hora_inicio",
            [$ambienteId, $fim, $inicio]
        )->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    private function bloqueios(int $ambienteId, string $inicio, string $fim): array
    {
        return $this->db->query(
            'SELECT id_bloqueio_ambiente, tipo, motivo, data_hora_inicio, data_hora_fim FROM bloqueios_ambientes
             WHERE ambiente_id = ? AND deletado_em IS NULL AND data_hora_inicio < ? AND data_hora_fim > ?',
            [$ambienteId, $fim, $inicio]
        )->getResultArray();
    }

    /** @return array<string, string> data => descrição */
    private function feriados(int $campusId, DateTimeImmutable $segunda): array
    {
        $linhas = $this->db->query(
            'SELECT data_feriado, descricao FROM feriados
             WHERE deletado_em IS NULL AND (campus_id = ? OR campus_id IS NULL) AND data_feriado BETWEEN ? AND ?',
            [$campusId, $segunda->format('Y-m-d'), $segunda->modify('+6 days')->format('Y-m-d')]
        )->getResultArray();

        return array_column($linhas, 'descricao', 'data_feriado');
    }
}

<?php

namespace App\Models\Agendamento;

use App\Libraries\Agendamento\Agenda;
use App\Libraries\Agendamento\GradeConsulta;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/**
 * Consulta pública (sem login): o que acontece em cada ambiente de um campus numa semana (domingo a sábado).
 *
 * Reúne as aulas do semestre (recorrentes) e as reservas pontuais confirmadas. Mostra disciplina, ambiente,
 * dia/horário e SÓ o primeiro nome do professor (LGPD); a busca por professor também compara só o primeiro
 * nome, para a consulta nunca revelar mais do que exibe. Usuário anonimizado não tem nome exibido nem é
 * encontrado pela busca. A montagem da grade fica em GradeConsulta.
 */
class ConsultaPublicaModel
{
    /** Fim de cada turno quando o campus não configurou (minutos desde 00:00): manhã 13h, tarde 18h, noite 23h. */
    public const TURNOS_PADRAO = ['manha' => 780, 'tarde' => 1080, 'noite' => 1380];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /** O primeiro campus ativo (mesma ordem do select de campus); null se não houver nenhum. */
    public function campusPadrao(): ?int
    {
        $linha = $this->db->table('campus')
            ->select('id_campus')
            ->where('ativo', 1)
            ->where('deletado_em', null)
            ->orderBy('nome')
            ->orderBy('id_campus')
            ->limit(1)
            ->get()->getRowArray();

        return $linha === null ? null : (int) $linha['id_campus'];
    }

    public function campusAtivo(int $id): bool
    {
        return $this->db->table('campus')
            ->where('id_campus', $id)
            ->where('ativo', 1)
            ->where('deletado_em', null)
            ->countAllResults() > 0;
    }

    /**
     * Minuto em que termina cada turno do campus (configuracoes_campus); o que faltar, ou toda a configuração
     * se estiver fora de ordem, cai no padrão. A régua termina às 23h, então nenhum turno passa disso.
     *
     * @return array{manha: int, tarde: int, noite: int}
     */
    public function fimDosTurnos(int $campusId): array
    {
        $linha = $this->db->table('configuracoes_campus')
            ->select('horario_fim_manha, horario_fim_tarde, horario_fim_noite')
            ->where('campus_id', $campusId)
            ->where('deletado_em', null)
            ->get()->getRowArray() ?? [];

        $fins = [];

        foreach (self::TURNOS_PADRAO as $turno => $padrao) {
            $hora         = $linha['horario_fim_' . $turno] ?? null;
            $fins[$turno] = $hora === null ? $padrao : GradeConsulta::minutos((string) $hora);
        }

        $fins['noite'] = min($fins['noite'], GradeConsulta::FIM);

        $emOrdem = GradeConsulta::INICIO < $fins['manha'] && $fins['manha'] < $fins['tarde']
            && $fins['tarde'] < $fins['noite'];

        return $emOrdem ? $fins : self::TURNOS_PADRAO;
    }

    /** @return list<array<string, mixed>> todos os ambientes do campus, com prédio e nome do campus */
    public function ambientes(int $campusId): array
    {
        return $this->db->query(
            "SELECT amb.id_ambiente AS id, amb.numero, amb.nome, amb.ativo, p.id_predio AS predio_id, p.nome AS predio,
                    COALESCE(NULLIF(TRIM(cp.abreviacao), ''), cp.nome) AS campus
             FROM ambientes amb
             JOIN predios p ON p.id_predio = amb.predio_id AND p.deletado_em IS NULL
             JOIN campus cp ON cp.id_campus = p.campus_id AND cp.deletado_em IS NULL AND cp.ativo = 1
             WHERE amb.deletado_em IS NULL AND cp.id_campus = ?",
            [$campusId]
        )->getResultArray();
    }

    /**
     * Alocações semestrais do campus cujo semestre (já iniciado ou encerrado, nunca o planejado) cobre a semana.
     *
     * @param array{curso?: int, disciplina?: int, professor?: string, texto?: string} $filtros
     *
     * @return list<array<string, mixed>>
     */
    public function aulas(int $campusId, array $filtros, DateTimeImmutable $domingo): array
    {
        [$condicoes, $binds] = $this->condicoes($filtros, false);

        $sql = "SELECT a.id_alocacao_semestral AS id, a.dia_semana, a.hora_inicio, a.hora_fim,
                       s.nome AS semestre, s.data_inicio, s.data_fim,
                       d.nome AS disciplina, d.sigla AS disciplina_sigla,
                       c.nome AS curso, c.abreviacao AS curso_sigla,
                       amb.id_ambiente AS ambiente_id, " . Agenda::PRIMEIRO_NOME . " AS pessoa
                FROM alocacoes_semestrais a
                JOIN semestres s    ON s.id_semestre = a.semestre_id AND s.deletado_em IS NULL
                                   AND s.status <> 'planejado' AND s.data_inicio <= ? AND s.data_fim >= ?
                JOIN disciplinas d  ON d.id_disciplina = a.disciplina_id AND d.deletado_em IS NULL
                JOIN cursos c       ON c.id_curso = d.curso_id AND c.deletado_em IS NULL
                JOIN ambientes amb  ON amb.id_ambiente = a.ambiente_id AND amb.deletado_em IS NULL
                JOIN predios p      ON p.id_predio = amb.predio_id AND p.deletado_em IS NULL
                JOIN campus cp      ON cp.id_campus = p.campus_id AND cp.deletado_em IS NULL AND cp.ativo = 1
                JOIN usuarios u     ON u.id_usuario = a.professor_id
                WHERE a.deletado_em IS NULL AND a.status <> 'cancelada' AND cp.id_campus = ?{$condicoes}";

        return $this->db->query(
            $sql,
            [$domingo->modify('+6 days')->format('Y-m-d'), $domingo->format('Y-m-d'), $campusId, ...$binds]
        )->getResultArray();
    }

    /**
     * Datas da semana em que uma alocação não acontece (exceção: conflito autorizado, bloqueio...).
     *
     * @return list<array<string, mixed>>
     */
    public function excecoes(DateTimeImmutable $domingo): array
    {
        return $this->db->table('alocacoes_semestrais_excecoes')
            ->select('alocacao_semestral_id, data_excecao')
            ->where('deletado_em', null)
            ->where('data_excecao >=', $domingo->format('Y-m-d'))
            ->where('data_excecao <=', $domingo->modify('+6 days')->format('Y-m-d'))
            ->get()->getResultArray();
    }

    /**
     * Reservas pontuais confirmadas do campus que tocam a semana. Quem aparece é o beneficiário (ou, sem ele,
     * o solicitante), só pelo primeiro nome.
     *
     * @param array{curso?: int, disciplina?: int, professor?: string, texto?: string} $filtros
     *
     * @return list<array<string, mixed>>
     */
    public function reservas(int $campusId, array $filtros, DateTimeImmutable $domingo): array
    {
        [$condicoes, $binds] = $this->condicoes($filtros, true);

        $sql = "SELECT r.id_reserva_pontual AS id, r.titulo, r.data_hora_inicio, r.data_hora_fim, r.recorrencia_id,
                       rec.tipo AS recorrencia_tipo,
                       d.nome AS disciplina, d.sigla AS disciplina_sigla,
                       c.nome AS curso, c.abreviacao AS curso_sigla,
                       amb.id_ambiente AS ambiente_id, " . Agenda::PRIMEIRO_NOME . " AS pessoa
                FROM reservas_pontuais r
                JOIN ambientes amb  ON amb.id_ambiente = r.ambiente_id AND amb.deletado_em IS NULL
                JOIN predios p      ON p.id_predio = amb.predio_id AND p.deletado_em IS NULL
                JOIN campus cp      ON cp.id_campus = p.campus_id AND cp.deletado_em IS NULL AND cp.ativo = 1
                JOIN usuarios u     ON u.id_usuario = COALESCE(r.beneficiario_id, r.solicitante_id)
                LEFT JOIN disciplinas d ON d.id_disciplina = r.disciplina_id AND d.deletado_em IS NULL
                LEFT JOIN cursos c      ON c.id_curso = d.curso_id AND c.deletado_em IS NULL
                LEFT JOIN recorrencias rec ON rec.id_recorrencia = r.recorrencia_id AND rec.deletado_em IS NULL
                WHERE r.deletado_em IS NULL AND r.status = 'confirmada' AND cp.id_campus = ?
                  AND r.data_hora_inicio < ? AND r.data_hora_fim > ?{$condicoes}";

        return $this->db->query(
            $sql,
            [
                $campusId,
                $domingo->modify('+7 days')->format('Y-m-d 00:00:00'),
                $domingo->format('Y-m-d 00:00:00'),
                ...$binds,
            ]
        )->getResultArray();
    }

    /**
     * Condições dos filtros (aliases das duas consultas: d, c, amb, p, u). A reserva sem disciplina não tem
     * curso nem disciplina, então some quando um desses filtros está ativo; o título da reserva entra na busca livre.
     *
     * @param array{curso?: int, disciplina?: int, professor?: string, texto?: string} $filtros
     *
     * @return array{0: string, 1: list<int|string>}
     */
    private function condicoes(array $filtros, bool $reserva): array
    {
        $primeiroNome = "SUBSTRING_INDEX(TRIM(u.nome), ' ', 1)";
        $sql          = '';
        $binds        = [];

        if (isset($filtros['curso'])) {
            $sql    .= ' AND c.id_curso = ?';
            $binds[] = $filtros['curso'];
        }

        if (isset($filtros['disciplina'])) {
            $sql    .= ' AND d.id_disciplina = ?';
            $binds[] = $filtros['disciplina'];
        }

        if (isset($filtros['professor'])) {
            $sql    .= " AND u.anonimizado_em IS NULL AND {$primeiroNome} LIKE ? ESCAPE '!'";
            $binds[] = '%' . $this->db->escapeLikeString($filtros['professor']) . '%';
        }

        if (isset($filtros['texto'])) {
            $colunas = [
                'd.nome', 'd.sigla', 'c.nome', 'c.abreviacao', 'amb.nome', 'amb.numero', 'amb.abreviacao', 'p.nome',
            ];

            if ($reserva) {
                $colunas[] = 'r.titulo';
            }

            $termo    = '%' . $this->db->escapeLikeString($filtros['texto']) . '%';
            $partes   = array_map(static fn (string $col): string => "{$col} LIKE ? ESCAPE '!'", $colunas);
            $partes[] = "(u.anonimizado_em IS NULL AND {$primeiroNome} LIKE ? ESCAPE '!')";

            $sql .= ' AND (' . implode(' OR ', $partes) . ')';
            array_push($binds, ...array_fill(0, count($partes), $termo));
        }

        return [$sql, $binds];
    }
}

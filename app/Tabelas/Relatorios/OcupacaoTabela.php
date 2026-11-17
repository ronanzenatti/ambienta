<?php

namespace App\Tabelas\Relatorios;

use App\Libraries\Agendamento\Alocacoes;
use App\Libraries\Relatorios\Ocupacao;
use App\Libraries\Tabela\Coluna;
use CodeIgniter\Database\BaseBuilder;

/**
 * Taxa de ocupação de cada ambiente ativo no período: (horas de aulas alocadas + horas de reservas
 * confirmadas) ÷ horas disponíveis (ver Ocupacao).
 *
 * Aulas: cada alocação não cancelada conta as ocorrências do seu dia da semana dentro do período e do
 * semestre, menos feriados do campus e exceções (dias em que a aula não acontece). Reservas: só a parte
 * dentro do período.
 */
final class OcupacaoTabela extends RelatorioTabela
{
    private const TAXA_SQL = '(oc.seg_alocados + oc.seg_reservados) / NULLIF(oc.seg_disponiveis, 0)';

    /** @var array{0: array<int, int>, 1: array<int, int>}|null disponível por campus, bloqueado por ambiente */
    private ?array $calendario = null;

    public function slug(): string
    {
        return 'ocupacao';
    }

    public function ordemPadrao(): array
    {
        return ['taxa', 'desc'];
    }

    protected function consulta(): BaseBuilder
    {
        return $this->base()->select('oc.*')->select(self::TAXA_SQL . ' AS taxa', false);
    }

    private function base(): BaseBuilder
    {
        $db   = db_connect();
        $de   = $db->escape($this->filtrosRelatorio->de->format('Y-m-d'));
        $ate  = $db->escape($this->filtrosRelatorio->ate->format('Y-m-d'));
        $ini  = $this->filtrosRelatorio->inicioSql();
        $fim  = $this->filtrosRelatorio->fimSql();

        // Ocorrências do dia da semana w entre a e b (datas), sem feriados nem exceções.
        $a       = "GREATEST(s.data_inicio, {$de})";
        $b       = "LEAST(s.data_fim, {$ate})";
        $w       = "(FIELD(al.dia_semana, '" . implode("', '", Alocacoes::DIAS) . "') - 1)";
        $primeiro = "DATE_ADD({$a}, INTERVAL MOD({$w} - WEEKDAY({$a}) + 7, 7) DAY)";
        $ocorrencias = "CASE WHEN {$primeiro} > {$b} THEN 0 ELSE FLOOR(DATEDIFF({$b}, {$primeiro}) / 7) + 1 END";
        $feriado     = 'f.deletado_em IS NULL AND (f.campus_id IS NULL OR f.campus_id = pr.campus_id)';
        $feriados    = "(SELECT COUNT(DISTINCT f.data_feriado) FROM feriados f WHERE {$feriado}"
            . " AND f.data_feriado BETWEEN {$a} AND {$b} AND WEEKDAY(f.data_feriado) = {$w})";
        $excecoes    = '(SELECT COUNT(*) FROM alocacoes_semestrais_excecoes x'
            . ' WHERE x.alocacao_semestral_id = al.id_alocacao_semestral AND x.deletado_em IS NULL'
            . " AND x.data_excecao BETWEEN {$a} AND {$b}"
            . " AND NOT EXISTS (SELECT 1 FROM feriados f WHERE {$feriado} AND f.data_feriado = x.data_excecao))";

        $alocados = "(SELECT COALESCE(SUM(GREATEST({$ocorrencias} - {$feriados} - {$excecoes}, 0)"
            . ' * TIME_TO_SEC(TIMEDIFF(al.hora_fim, al.hora_inicio))), 0)'
            . ' FROM alocacoes_semestrais al'
            . ' JOIN semestres s ON s.id_semestre = al.semestre_id AND s.deletado_em IS NULL'
            . " WHERE al.ambiente_id = am.id_ambiente AND al.deletado_em IS NULL AND al.status <> 'cancelada'"
            . " AND s.data_inicio <= {$ate} AND s.data_fim >= {$de})";

        $reservados = "(SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, GREATEST(r.data_hora_inicio, {$ini}),"
            . " LEAST(r.data_hora_fim, {$fim}))), 0) FROM reservas_pontuais r"
            . " WHERE r.ambiente_id = am.id_ambiente AND r.deletado_em IS NULL AND r.status = 'confirmada'"
            . " AND r.data_hora_inicio < {$fim} AND r.data_hora_fim > {$ini})";

        [$porCampus, $bloqueado] = $this->calendario();
        $disponivel = 'GREATEST(' . self::casoSql('pr.campus_id', $porCampus)
            . ' - ' . self::casoSql('am.id_ambiente', $bloqueado) . ', 0)';

        $ambientes = $db->table('ambientes am')
            ->select("am.id_ambiente, CONCAT(am.numero, ' ', am.nome) AS ambiente", false)
            ->select('pr.nome AS predio, pr.campus_id, am.predio_id')
            ->select('IF(LENGTH(TRIM(ca.abreviacao)) > 0, TRIM(ca.abreviacao), ca.nome) AS campus', false)
            ->select("{$alocados} AS seg_alocados, {$reservados} AS seg_reservados", false)
            ->select("{$disponivel} AS seg_disponiveis", false)
            ->join('predios pr', 'pr.id_predio = am.predio_id AND pr.deletado_em IS NULL')
            ->join('campus ca', 'ca.id_campus = pr.campus_id AND ca.deletado_em IS NULL')
            ->where('am.deletado_em', null)
            ->where('am.ativo', 1);

        $condicao = $this->filtrosRelatorio->condicaoLocal('pr.campus_id', 'am.predio_id');

        if ($condicao !== null) {
            $ambientes->where($condicao, null, false);
        }

        return $db->newQuery()->fromSubquery($ambientes, 'oc');
    }

    /** @return array{0: array<int, int>, 1: array<int, int>} */
    private function calendario(): array
    {
        if ($this->calendario === null) {
            $ocupacao = new Ocupacao(db_connect(), $this->filtrosRelatorio->de, $this->filtrosRelatorio->ate);

            $this->calendario = [
                $ocupacao->disponivelPorCampus(array_keys($this->filtrosRelatorio->campi())),
                $ocupacao->bloqueadoPorAmbiente(),
            ];
        }

        return $this->calendario;
    }

    /** @param array<int, int> $valores id => segundos */
    private static function casoSql(string $coluna, array $valores): string
    {
        if ($valores === []) {
            return '0';
        }

        $casos = '';
        foreach ($valores as $id => $segundos) {
            $casos .= ' WHEN ' . (int) $id . ' THEN ' . (int) $segundos;
        }

        return "(CASE {$coluna}{$casos} ELSE 0 END)";
    }

    protected function chavePrimaria(): string
    {
        return 'oc.id_ambiente';
    }

    protected function definirColunas(): array
    {
        $horas = static fn (array $linha, mixed $valor): string => self::horas($valor);

        return [
            Coluna::nova('ambiente', lang('Relatorios.fieldEnvironment'), 'oc.ambiente')->principal(),
            Coluna::nova('predio', lang('Relatorios.fieldBuilding'), 'oc.predio'),
            Coluna::nova('campus', lang('Relatorios.fieldCampus'), 'oc.campus'),
            Coluna::nova('seg_alocados', lang('Relatorios.fieldHoursAllocated'), 'oc.seg_alocados')
                ->numerica()->semBusca()->texto($horas),
            Coluna::nova('seg_reservados', lang('Relatorios.fieldHoursReserved'), 'oc.seg_reservados')
                ->numerica()->semBusca()->texto($horas),
            Coluna::nova('seg_disponiveis', lang('Relatorios.fieldHoursAvailable'), 'oc.seg_disponiveis')
                ->numerica()->semBusca()->texto($horas),
            Coluna::nova('taxa', lang('Relatorios.fieldOccupancy'), self::TAXA_SQL)
                ->numerica()->semBusca()
                ->texto(static fn (array $linha, mixed $valor): string => self::percentual($valor)),
        ];
    }

    protected function calcularResumo(): array
    {
        $totais = $this->base()
            ->select('COUNT(*) AS ambientes, COALESCE(SUM(oc.seg_alocados + oc.seg_reservados), 0) AS ocupados', false)
            ->select('COALESCE(SUM(oc.seg_disponiveis), 0) AS disponiveis', false)
            ->get()
            ->getRowArray() ?? [];

        $disponiveis = (int) ($totais['disponiveis'] ?? 0);

        return [
            [lang('Relatorios.summaryEnvironments'), ui_numero((int) ($totais['ambientes'] ?? 0))],
            [lang('Relatorios.summaryHoursOccupied'), self::horas($totais['ocupados'] ?? 0)],
            [lang('Relatorios.fieldHoursAvailable'), self::horas($disponiveis)],
            [lang('Relatorios.summaryOverallOccupancy'), self::percentual(
                $disponiveis === 0 ? null : ((int) ($totais['ocupados'] ?? 0)) / $disponiveis
            )],
        ];
    }

    private static function percentual(mixed $taxa): string
    {
        return $taxa === null ? '—' : self::decimal(((float) $taxa) * 100) . ' %';
    }
}

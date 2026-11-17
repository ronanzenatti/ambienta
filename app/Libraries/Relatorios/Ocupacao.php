<?php

namespace App\Libraries\Relatorios;

use App\Libraries\Agendamento\Alocacoes;
use CodeIgniter\Database\BaseConnection;
use Config\Relatorios as ConfigRelatorios;
use DateTimeImmutable;

/**
 * Base da taxa de ocupação: horas disponíveis de cada ambiente no período = janela de funcionamento de cada
 * dia (Config\Relatorios::$janela), menos os feriados do campus (nacionais/estaduais valem para todos) e as
 * horas de bloqueio do ambiente que caem dentro da janela.
 *
 * Só depende do calendário, não das reservas: é calculado aqui, uma vez por relatório, e entra na consulta
 * como CASE por campus/ambiente (a ordenação e a paginação continuam no banco).
 */
final class Ocupacao
{
    /** @var array<string, array{0: string, 1: string}> */
    private array $janela;

    /** @var array<string, true>|null datas de feriado para todos os campi */
    private ?array $feriadosGerais = null;

    /** @var array<int, array<string, true>> datas de feriado de cada campus */
    private array $feriadosCampus = [];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly DateTimeImmutable $de,
        private readonly DateTimeImmutable $ate,
    ) {
        $this->janela = config(ConfigRelatorios::class)->janela;
    }

    /**
     * Segundos de funcionamento no período, por campus (sem descontar bloqueios).
     *
     * @param list<int> $campi
     *
     * @return array<int, int>
     */
    public function disponivelPorCampus(array $campi): array
    {
        $total = [];

        foreach ($campi as $campusId) {
            $total[$campusId] = 0;

            for ($dia = $this->de; $dia <= $this->ate; $dia = $dia->modify('+1 day')) {
                $janela = $this->janelaDoDia($dia, $campusId);

                if ($janela !== null) {
                    $total[$campusId] += $janela[1] - $janela[0];
                }
            }
        }

        return $total;
    }

    /**
     * Segundos de bloqueio dentro da janela de funcionamento, por ambiente (só os que têm bloqueio no período).
     *
     * @return array<int, int>
     */
    public function bloqueadoPorAmbiente(): array
    {
        $inicio = $this->de->format('Y-m-d 00:00:00');
        $fim    = $this->ate->modify('+1 day')->format('Y-m-d 00:00:00');

        $bloqueios = $this->db->query(
            'SELECT b.ambiente_id, b.data_hora_inicio, b.data_hora_fim, p.campus_id
             FROM bloqueios_ambientes b
             JOIN ambientes a ON a.id_ambiente = b.ambiente_id
             JOIN predios p ON p.id_predio = a.predio_id
             WHERE b.deletado_em IS NULL AND b.data_hora_inicio < ? AND b.data_hora_fim > ?',
            [$fim, $inicio]
        )->getResultArray();

        $total = [];

        foreach ($bloqueios as $bloqueio) {
            $bi       = (int) strtotime((string) $bloqueio['data_hora_inicio']);
            $bf       = (int) strtotime((string) $bloqueio['data_hora_fim']);
            $campusId = (int) $bloqueio['campus_id'];
            $primeiro = max($this->de, new DateTimeImmutable(date('Y-m-d', $bi)));
            $ultimo   = min($this->ate, new DateTimeImmutable(date('Y-m-d', $bf)));
            $segundos = 0;

            for ($dia = $primeiro; $dia <= $ultimo; $dia = $dia->modify('+1 day')) {
                $janela = $this->janelaDoDia($dia, $campusId);

                if ($janela !== null) {
                    $segundos += max(0, min($bf, $janela[1]) - max($bi, $janela[0]));
                }
            }

            $ambienteId         = (int) $bloqueio['ambiente_id'];
            $total[$ambienteId] = ($total[$ambienteId] ?? 0) + $segundos;
        }

        return array_filter($total);
    }

    /**
     * Início e fim (timestamps) da janela de funcionamento do dia no campus; null = fechado ou feriado.
     *
     * @return array{0: int, 1: int}|null
     */
    private function janelaDoDia(DateTimeImmutable $dia, int $campusId): ?array
    {
        $janela = $this->janela[Alocacoes::diaDaSemana($dia)] ?? null;
        $data   = $dia->format('Y-m-d');

        if ($janela === null || $this->ehFeriado($data, $campusId)) {
            return null;
        }

        return [(int) strtotime("{$data} {$janela[0]}"), (int) strtotime("{$data} {$janela[1]}")];
    }

    private function ehFeriado(string $data, int $campusId): bool
    {
        if ($this->feriadosGerais === null) {
            $this->feriadosGerais = [];

            $linhas = $this->db->table('feriados')
                ->select('data_feriado, campus_id')
                ->where('deletado_em', null)
                ->where('data_feriado >=', $this->de->format('Y-m-d'))
                ->where('data_feriado <=', $this->ate->format('Y-m-d'))
                ->get()
                ->getResultArray();

            foreach ($linhas as $linha) {
                if ($linha['campus_id'] === null) {
                    $this->feriadosGerais[(string) $linha['data_feriado']] = true;
                } else {
                    $this->feriadosCampus[(int) $linha['campus_id']][(string) $linha['data_feriado']] = true;
                }
            }
        }

        return isset($this->feriadosGerais[$data]) || isset($this->feriadosCampus[$campusId][$data]);
    }
}

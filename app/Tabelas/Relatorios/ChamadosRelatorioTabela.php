<?php

namespace App\Tabelas\Relatorios;

use App\Libraries\Patrimonio\Locais;
use App\Libraries\Tabela\Coluna;
use App\Tabelas\Chamados\ChamadosTabela;
use CodeIgniter\Database\BaseBuilder;

/**
 * Chamados de adequação abertos no período, por status, com o encerramento (conclusão ou cancelamento, do
 * histórico) e o tempo de resolução dos concluídos. O resumo traz a contagem por status e o tempo médio.
 */
final class ChamadosRelatorioTabela extends RelatorioTabela
{
    public const STATUS = ['aberto', 'em_andamento', 'concluido', 'cancelado'];

    /** Quando o chamado chegou ao status final atual (NULL enquanto aberto ou em andamento). */
    private const ENCERRAMENTO_SQL = '(SELECT MAX(h.criado_em) FROM chamados_historico h'
        . ' WHERE h.chamado_id = c.id_chamado AND h.status_novo = c.status AND h.deletado_em IS NULL'
        . " AND c.status IN ('concluido', 'cancelado'))";

    private const RESOLUCAO_SQL = "CASE WHEN c.status = 'concluido'"
        . ' THEN TIMESTAMPDIFF(SECOND, c.criado_em, ' . self::ENCERRAMENTO_SQL . ') END';

    public function slug(): string
    {
        return 'chamados';
    }

    public function ordemPadrao(): array
    {
        return ['criado_em', 'desc'];
    }

    protected function consulta(): BaseBuilder
    {
        return $this->base()
            ->select('c.id_chamado, c.status, c.descricao, c.criado_em, u.nome AS aberto_por')
            ->select(Locais::rotuloSql('l') . ' AS ambiente', false)
            ->select(self::ENCERRAMENTO_SQL . ' AS encerrado_em', false)
            ->select(self::RESOLUCAO_SQL . ' AS resolucao', false);
    }

    private function base(): BaseBuilder
    {
        $builder = db_connect()->table('chamados c')
            ->join('usuarios u', 'u.id_usuario = c.aberto_por_id')
            ->where('c.deletado_em', null)
            ->where($this->filtrosRelatorio->condicaoPeriodo('c.criado_em'), null, false);

        Locais::juntar($builder, 'l', 'c.ambiente_id', 'NULL');

        $condicao = $this->filtrosRelatorio->condicaoLocal('l_ca.id_campus', 'l_amb.predio_id');

        if ($condicao !== null) {
            $builder->where($condicao, null, false);
        }

        return $builder;
    }

    protected function chavePrimaria(): string
    {
        return 'c.id_chamado';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('id_chamado', lang('Relatorios.fieldTicket'), 'c.id_chamado')
                ->principal()
                ->numerica()
                ->texto(static fn (array $linha, mixed $valor): string => '#' . $valor),
            Coluna::nova('ambiente', lang('Relatorios.fieldEnvironment'), Locais::rotuloSql('l')),
            Coluna::nova('descricao', lang('Relatorios.fieldDescription'), 'c.descricao')
                ->semOrdem()
                ->html(static fn (array $linha, string $texto): string => esc(mb_strimwidth($texto, 0, 80, '…'))),
            Coluna::nova('aberto_por', lang('Relatorios.fieldOpenedBy'), 'u.nome'),
            Coluna::nova('criado_em', lang('Relatorios.fieldOpenedAt'), 'c.criado_em')->dataHora(),
            Coluna::nova('status', lang('Ui.status'), 'c.status')
                ->rotulos(opcoes_traduzidas('Chamados.status_', self::STATUS))
                ->html(static fn (array $linha, string $texto): string => ui_status_dot(
                    ChamadosTabela::tom((string) $linha['status']),
                    $texto
                )),
            Coluna::nova('encerrado_em', lang('Relatorios.fieldClosedAt'), self::ENCERRAMENTO_SQL)->dataHora(),
            Coluna::nova('resolucao', lang('Relatorios.fieldResolutionTime'), self::RESOLUCAO_SQL)
                ->semBusca()
                ->numerica()
                ->texto(static fn (array $linha, mixed $valor): string => $valor === null
                    ? ''
                    : self::duracao((int) $valor)),
        ];
    }

    protected function calcularResumo(): array
    {
        $builder = $this->base()
            ->select('COUNT(*) AS total', false)
            ->select('AVG(' . self::RESOLUCAO_SQL . ') AS media', false);

        foreach (self::STATUS as $status) {
            $builder->select("COALESCE(SUM(c.status = '{$status}'), 0) AS {$status}", false);
        }

        $totais = $builder->get()->getRowArray() ?? [];
        $resumo = [[lang('Relatorios.fieldTotal'), ui_numero((int) ($totais['total'] ?? 0))]];

        foreach (self::STATUS as $status) {
            $resumo[] = [lang('Chamados.status_' . $status), ui_numero((int) ($totais[$status] ?? 0))];
        }

        $media    = $totais['media'] ?? null;
        $resumo[] = [
            lang('Relatorios.summaryAverageResolution'),
            $media === null ? '—' : self::duracao((int) round((float) $media)),
        ];

        return $resumo;
    }

    /** Duração em dias e horas ("2 d 5 h"); abaixo de uma hora, em minutos. */
    public static function duracao(int $segundos): string
    {
        if ($segundos < 3600) {
            return lang('Relatorios.durationMinutes', [intdiv(max(0, $segundos), 60)]);
        }

        $horas = intdiv($segundos, 3600);

        return lang('Relatorios.durationDaysHours', [intdiv($horas, 24), $horas % 24]);
    }
}

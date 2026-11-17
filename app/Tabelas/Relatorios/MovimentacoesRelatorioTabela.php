<?php

namespace App\Tabelas\Relatorios;

use App\Libraries\Patrimonio\Locais;
use App\Libraries\Tabela\Coluna;
use App\Tabelas\Patrimonio\MovimentacoesTabela;
use CodeIgniter\Database\BaseBuilder;

/**
 * Histórico de movimentações enviadas no período, com origem ou destino no escopo (e nos filtros de campus e
 * prédio). Situação calculada como na listagem de movimentações: pendente, atrasada ou confirmada.
 */
final class MovimentacoesRelatorioTabela extends RelatorioTabela
{
    private const SITUACAO_SQL = "CASE WHEN mv.status = 'confirmada' THEN 'confirmada'"
        . " WHEN mv.data_limite_confirmacao < NOW() THEN 'atrasada' ELSE 'pendente' END";

    public function slug(): string
    {
        return 'movimentacoes';
    }

    public function ordemPadrao(): array
    {
        return ['data_envio', 'desc'];
    }

    protected function consulta(): BaseBuilder
    {
        return $this->base()
            ->select('mv.id_movimentacao_item, mv.data_envio, mv.data_confirmacao')
            ->select('i.numero_patrimonio, i.patrimoniavel, i.nome, i.quantidade')
            ->select('rem.nome AS remetente, des.nome AS destinatario')
            ->select(Locais::rotuloSql('o') . ' AS origem', false)
            ->select(Locais::rotuloSql('d') . ' AS destino', false)
            ->select(self::SITUACAO_SQL . ' AS situacao', false);
    }

    private function base(): BaseBuilder
    {
        $builder = db_connect()->table('movimentacoes_itens mv')
            ->join('itens i', 'i.id_item = mv.item_id')
            ->join('usuarios rem', 'rem.id_usuario = mv.remetente_id')
            ->join('usuarios des', 'des.id_usuario = mv.destinatario_id')
            ->where('mv.deletado_em', null)
            ->where($this->filtrosRelatorio->condicaoPeriodo('mv.data_envio'), null, false);

        Locais::juntar($builder, 'o', 'mv.origem_ambiente_id', 'mv.origem_almoxarifado_id');
        Locais::juntar($builder, 'd', 'mv.destino_ambiente_id', 'mv.destino_almoxarifado_id');

        $origem  = $this->filtrosRelatorio->condicaoLocal('o_ca.id_campus', 'o_amb.predio_id');
        $destino = $this->filtrosRelatorio->condicaoLocal('d_ca.id_campus', 'd_amb.predio_id');

        if ($origem !== null && $destino !== null) {
            $builder->where("(({$origem}) OR ({$destino}))", null, false);
        }

        return $builder;
    }

    protected function chavePrimaria(): string
    {
        return 'mv.id_movimentacao_item';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('item', lang('Patrimonio.fieldItem'), 'i.numero_patrimonio')
                ->principal()
                ->buscaEm('i.numero_patrimonio', 'i.nome')
                ->texto(static fn (array $linha): string => item_rotulo($linha)),
            Coluna::nova('origem', lang('Patrimonio.fieldOrigin'), Locais::rotuloSql('o')),
            Coluna::nova('destino', lang('Patrimonio.fieldDestination'), Locais::rotuloSql('d')),
            Coluna::nova('remetente', lang('Patrimonio.fieldSender'), 'rem.nome'),
            Coluna::nova('destinatario', lang('Patrimonio.fieldRecipient'), 'des.nome'),
            Coluna::nova('data_envio', lang('Patrimonio.fieldSentAt'), 'mv.data_envio')->dataHora(),
            Coluna::nova('data_confirmacao', lang('Patrimonio.fieldConfirmedAt'), 'mv.data_confirmacao')->dataHora(),
            Coluna::nova('situacao', lang('Ui.status'), self::SITUACAO_SQL)
                ->rotulos(opcoes_traduzidas('Patrimonio.movement_', MovimentacoesTabela::SITUACOES))
                ->html(static fn (array $linha, string $texto): string => ui_status_dot(
                    MovimentacoesTabela::tom((string) $linha['situacao']),
                    $texto
                )),
        ];
    }

    protected function calcularResumo(): array
    {
        $totais = $this->base()
            ->select('COUNT(*) AS total', false)
            ->select("COALESCE(SUM(mv.status = 'confirmada'), 0) AS confirmadas", false)
            ->select(
                "COALESCE(SUM(mv.status = 'pendente' AND mv.data_limite_confirmacao < NOW()), 0) AS atrasadas",
                false
            )
            ->get()
            ->getRowArray() ?? [];

        return [
            [lang('Relatorios.fieldTotal'), ui_numero((int) ($totais['total'] ?? 0))],
            [lang('Patrimonio.movement_confirmada'), ui_numero((int) ($totais['confirmadas'] ?? 0))],
            [lang('Patrimonio.movement_atrasada'), ui_numero((int) ($totais['atrasadas'] ?? 0))],
        ];
    }
}

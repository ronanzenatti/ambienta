<?php

namespace App\Tabelas\Relatorios;

use App\Libraries\Patrimonio\Locais;
use App\Libraries\Tabela\Coluna;
use App\Tabelas\Patrimonio\DescartesTabela;
use CodeIgniter\Database\BaseBuilder;

/**
 * Descartes solicitados no período, pelo local do item (o item baixado continua no local onde foi baixado),
 * com motivo, justificativa, quem pediu e quem decidiu.
 */
final class DescartesRelatorioTabela extends RelatorioTabela
{
    public function slug(): string
    {
        return 'descartes';
    }

    public function ordemPadrao(): array
    {
        return ['data_solicitacao', 'desc'];
    }

    protected function consulta(): BaseBuilder
    {
        return $this->base()
            ->select('ds.id_descarte, ds.status, ds.justificativa, ds.data_solicitacao, ds.data_decisao')
            ->select('i.numero_patrimonio, i.patrimoniavel, i.nome, i.quantidade, mo.nome AS motivo')
            ->select('sol.nome AS solicitante, apr.nome AS aprovador')
            ->select(Locais::rotuloSql('l') . ' AS local', false);
    }

    private function base(): BaseBuilder
    {
        $builder = db_connect()->table('descartes ds')
            ->join('itens i', 'i.id_item = ds.item_id')
            ->join('motivos_descarte mo', 'mo.id_motivo_descarte = ds.motivo_descarte_id')
            ->join('usuarios sol', 'sol.id_usuario = ds.solicitante_id')
            ->join('usuarios apr', 'apr.id_usuario = ds.aprovador_id', 'left')
            ->where('ds.deletado_em', null)
            ->where($this->filtrosRelatorio->condicaoPeriodo('ds.data_solicitacao'), null, false);

        Locais::juntar($builder, 'l', 'i.ambiente_id', 'i.almoxarifado_id');

        $condicao = $this->filtrosRelatorio->condicaoLocal('l_ca.id_campus', 'l_amb.predio_id');

        if ($condicao !== null) {
            $builder->where($condicao, null, false);
        }

        return $builder;
    }

    protected function chavePrimaria(): string
    {
        return 'ds.id_descarte';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('item', lang('Patrimonio.fieldItem'), 'i.numero_patrimonio')
                ->principal()
                ->buscaEm('i.numero_patrimonio', 'i.nome')
                ->texto(static fn (array $linha): string => item_rotulo($linha)),
            Coluna::nova('local', lang('Patrimonio.fieldLocation'), Locais::rotuloSql('l')),
            Coluna::nova('motivo', lang('Patrimonio.fieldReason'), 'mo.nome'),
            Coluna::nova('justificativa', lang('Patrimonio.fieldJustification'), 'ds.justificativa'),
            Coluna::nova('solicitante', lang('Patrimonio.fieldRequester'), 'sol.nome'),
            Coluna::nova('data_solicitacao', lang('Patrimonio.fieldRequestedAt'), 'ds.data_solicitacao')->dataHora(),
            Coluna::nova('status', lang('Ui.status'), 'ds.status')
                ->rotulos(opcoes_traduzidas('Patrimonio.disposal_', DescartesTabela::STATUS))
                ->html(static fn (array $linha, string $texto): string => ui_status_dot(
                    DescartesTabela::tom((string) $linha['status']),
                    $texto
                )),
            Coluna::nova('aprovador', lang('Patrimonio.fieldApprover'), 'apr.nome'),
            Coluna::nova('data_decisao', lang('Patrimonio.fieldDecidedAt'), 'ds.data_decisao')->dataHora(),
        ];
    }

    protected function calcularResumo(): array
    {
        $builder = $this->base()->select('COUNT(*) AS total', false);

        foreach (DescartesTabela::STATUS as $status) {
            $builder->select("COALESCE(SUM(ds.status = '{$status}'), 0) AS {$status}", false);
        }

        $totais = $builder->get()->getRowArray() ?? [];
        $resumo = [[lang('Relatorios.fieldTotal'), ui_numero((int) ($totais['total'] ?? 0))]];

        foreach (DescartesTabela::STATUS as $status) {
            $resumo[] = [lang('Patrimonio.disposal_' . $status), ui_numero((int) ($totais[$status] ?? 0))];
        }

        return $resumo;
    }
}

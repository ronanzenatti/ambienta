<?php

namespace App\Tabelas\Relatorios;

use App\Libraries\Patrimonio\Locais;
use App\Libraries\Tabela\Coluna;
use App\Tabelas\Patrimonio\ItensTabela;
use CodeIgniter\Database\BaseBuilder;

/**
 * Itens patrimoniais por status, contados por campus, prédio ou ambiente (almoxarifado conta como um local
 * próprio no agrupamento por prédio ou ambiente). Inclui os descartados (baixados), que continuam no local
 * onde foram baixados. Retrato do momento: sem período.
 */
final class ItensPorStatusTabela extends RelatorioTabela
{
    public function slug(): string
    {
        return 'itens-por-status';
    }

    public function usaPeriodo(): bool
    {
        return false;
    }

    public function usaAgrupamento(): bool
    {
        return true;
    }

    public function ordemPadrao(): array
    {
        return ['grupo', 'asc'];
    }

    protected function consulta(): BaseBuilder
    {
        $builder = $this->base()
            ->select('g.chave, g.grupo, SUM(g.quantidade) AS total', false)
            ->groupBy(['g.chave', 'g.grupo']);

        foreach (ItensTabela::STATUS as $status) {
            $builder->select("SUM(CASE WHEN g.status = '{$status}' THEN g.quantidade ELSE 0 END) AS {$status}", false);
        }

        return $builder;
    }

    /** Uma linha por item ("g"): chave e rótulo do grupo, campus/prédio do local e status. */
    private function base(): BaseBuilder
    {
        [$chave, $grupo] = match ($this->filtrosRelatorio->agrupamento) {
            'predio' => [
                "COALESCE(CONCAT('p', l_pr.id_predio), CONCAT('a', l_alx.id_almoxarifado))",
                "CASE WHEN l_alx.id_almoxarifado IS NOT NULL THEN CONCAT(l_alx.nome, ' · ', l_ca.abreviacao)"
                . " ELSE CONCAT(l_pr.nome, ' · ', l_ca.abreviacao) END",
            ],
            'ambiente' => [
                "COALESCE(CONCAT('m', l_amb.id_ambiente), CONCAT('a', l_alx.id_almoxarifado))",
                Locais::rotuloSql('l'),
            ],
            default => ["CONCAT('c', l_ca.id_campus)", "COALESCE(NULLIF(TRIM(l_ca.abreviacao), ''), l_ca.nome)"],
        };

        $itens = db_connect()->table('itens i')
            ->select("{$chave} AS chave, {$grupo} AS grupo", false)
            ->select('l_ca.id_campus AS campus_id, l_amb.predio_id AS predio_id, i.status, i.quantidade')
            ->where("(i.deletado_em IS NULL OR i.status = 'descartado')", null, false);
        Locais::juntar($itens, 'l', 'i.ambiente_id', 'i.almoxarifado_id');

        $builder  = db_connect()->newQuery()->fromSubquery($itens, 'g');
        $condicao = $this->filtrosRelatorio->condicaoLocal('g.campus_id', 'g.predio_id');

        if ($condicao !== null) {
            $builder->where($condicao, null, false);
        }

        return $builder;
    }

    protected function chavePrimaria(): string
    {
        return 'g.chave';
    }

    protected function definirColunas(): array
    {
        $colunas = [
            Coluna::nova('grupo', lang('Relatorios.group_' . $this->filtrosRelatorio->agrupamento), 'g.grupo')
                ->principal(),
        ];

        foreach (ItensTabela::STATUS as $status) {
            $soma      = "SUM(CASE WHEN g.status = '{$status}' THEN g.quantidade ELSE 0 END)";
            $colunas[] = Coluna::nova($status, lang('Patrimonio.status_' . $status), $soma)
                ->numerica()
                ->semBusca();
        }

        $colunas[] = Coluna::nova('total', lang('Relatorios.fieldTotal'), 'SUM(g.quantidade)')->numerica()->semBusca();

        return $colunas;
    }

    protected function calcularResumo(): array
    {
        $builder = $this->base()->select('COALESCE(SUM(g.quantidade), 0) AS total', false);

        foreach (ItensTabela::STATUS as $status) {
            $soma = "SUM(CASE WHEN g.status = '{$status}' THEN g.quantidade ELSE 0 END)";
            $builder->select("COALESCE({$soma}, 0) AS {$status}", false);
        }

        $totais = $builder->get()->getRowArray() ?? [];
        $resumo = [[lang('Relatorios.fieldTotal'), ui_numero((int) ($totais['total'] ?? 0))]];

        foreach (ItensTabela::STATUS as $status) {
            $resumo[] = [lang('Patrimonio.status_' . $status), ui_numero((int) ($totais[$status] ?? 0))];
        }

        return $resumo;
    }
}

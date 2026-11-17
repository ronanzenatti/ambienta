<?php

namespace App\Tabelas\Relatorios;

use App\Libraries\Patrimonio\Locais;
use App\Libraries\Tabela\Coluna;
use App\Tabelas\Patrimonio\ItensTabela;
use CodeIgniter\Database\BaseBuilder;

/**
 * Inventário por ambiente/prédio/campus: itens ativos numa lista só — patrimoniais (uma linha por número), pendentes
 * (patrimoniáveis ainda sem número) e sem patrimônio (uma linha por tipo e local, com a quantidade). Retrato do
 * momento: sem período.
 */
final class InventarioTabela extends RelatorioTabela
{
    public const TIPOS = ['patrimonial', 'pendente', 'nao_patrimonial'];

    /** Com número = patrimonial; patrimoniável sem número = pendente; sem patrimônio = por quantidade. */
    private const TIPO_SQL = "CASE WHEN i.numero_patrimonio IS NOT NULL THEN 'patrimonial'"
        . " WHEN i.patrimoniavel = 1 THEN 'pendente' ELSE 'nao_patrimonial' END";

    public function slug(): string
    {
        return 'inventario';
    }

    public function usaPeriodo(): bool
    {
        return false;
    }

    public function ordemPadrao(): array
    {
        return ['local', 'asc'];
    }

    protected function consulta(): BaseBuilder
    {
        return $this->base()->select('inv.*');
    }

    /** Itens numa subconsulta "inv", já com o escopo e os filtros. */
    private function base(): BaseBuilder
    {
        $db = db_connect();

        $itens = $db->table('itens i')
            ->select("i.id_item AS chave, " . self::TIPO_SQL . ' AS tipo', false)
            ->select('i.numero_patrimonio AS numero_patrimonio, i.patrimoniavel AS patrimoniavel, i.nome AS nome')
            ->select('c.nome AS categoria, i.quantidade AS quantidade, i.status AS status')
            ->select(Locais::rotuloSql('l') . ' AS local', false)
            ->select('l_ca.id_campus AS campus_id, l_amb.predio_id AS predio_id')
            ->join('categorias_itens c', 'c.id_categoria_item = i.categoria_item_id')
            ->where('i.deletado_em', null);
        Locais::juntar($itens, 'l', 'i.ambiente_id', 'i.almoxarifado_id');

        $builder  = $db->newQuery()->fromSubquery($itens, 'inv');
        $condicao = $this->filtrosRelatorio->condicaoLocal('inv.campus_id', 'inv.predio_id');

        if ($condicao !== null) {
            $builder->where($condicao, null, false);
        }

        return $builder;
    }
    protected function chavePrimaria(): string
    {
        return 'inv.chave';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('local', lang('Patrimonio.fieldLocation'), 'inv.local'),
            Coluna::nova('tipo', lang('Relatorios.fieldType'), 'inv.tipo')
                ->rotulos(opcoes_traduzidas('Relatorios.type_', self::TIPOS)),
            Coluna::nova('numero_patrimonio', lang('Patrimonio.fieldNumber'), 'inv.numero_patrimonio')
                ->texto(static fn (array $linha): string => item_numero($linha)),
            Coluna::nova('nome', lang('Institucional.fieldName'), 'inv.nome')->principal(),
            Coluna::nova('categoria', lang('Patrimonio.fieldCategory'), 'inv.categoria'),
            Coluna::nova('quantidade', lang('Patrimonio.fieldQuantity'), 'inv.quantidade')->numerica()->semBusca(),
            Coluna::nova('status', lang('Ui.status'), 'inv.status')
                ->rotulos(opcoes_traduzidas('Patrimonio.status_', ItensTabela::STATUS))
                ->html(static fn (array $linha, string $texto): string => $linha['status'] === null
                    ? ''
                    : ui_status_dot(ItensTabela::tom((string) $linha['status']), $texto)),
        ];
    }

    protected function calcularResumo(): array
    {
        $totais = $this->base()
            ->select("COALESCE(SUM(inv.tipo = 'patrimonial'), 0) AS unidades", false)
            ->select("COALESCE(SUM(inv.tipo = 'pendente'), 0) AS pendentes", false)
            ->select(
                "COALESCE(SUM(CASE WHEN inv.tipo = 'nao_patrimonial' THEN inv.quantidade ELSE 0 END), 0) AS estoque",
                false
            )
            ->get()
            ->getRowArray() ?? [];

        return [
            [lang('Relatorios.summaryAssetUnits'), ui_numero((int) ($totais['unidades'] ?? 0))],
            [lang('Relatorios.summaryPendingUnits'), ui_numero((int) ($totais['pendentes'] ?? 0))],
            [lang('Relatorios.summaryStockUnits'), ui_numero((int) ($totais['estoque'] ?? 0))],
        ];
    }
}

<?php

namespace App\Tabelas\Patrimonio;

use App\Libraries\Patrimonio\ImportadorNovosItens;
use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/**
 * Planilha em branco para cadastrar itens novos de um modelo: X linhas (modelo, local, número vazio) que voltam
 * pela importação de itens novos (ImportadorNovosItens), cada linha preenchida criando um item numerado com os
 * dados do modelo. Só serve à exportação (Excel); a ordem das colunas é a que o importador lê.
 */
final class ModeloEmBrancoTabela extends Tabela
{
    /**
     * @param string $localValor  "tipo:id" do local escolhido (ex.: "ambiente:12")
     * @param string $localRotulo texto do local
     */
    public function __construct(
        private readonly int $modeloId,
        private readonly string $modeloNome,
        private readonly int $quantidade,
        private readonly string $localValor,
        private readonly string $localRotulo,
    ) {
    }

    public function id(): string
    {
        return 'itens-novos-modelo';
    }

    public function titulo(): string
    {
        return lang('Patrimonio.newItemsSheetTitle');
    }

    public function urlLista(): string
    {
        return site_url('patrimonio/numeros');
    }

    public function urlExportar(): string
    {
        return site_url('patrimonio/numeros/modelo');
    }

    /** As X linhas: sequência 1..X gerada por produto de dígitos (sem depender de tabela auxiliar). */
    protected function consulta(): BaseBuilder
    {
        $db      = db_connect();
        $digitos = '(SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4'
            . ' UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
        $n       = '(a.d + 10 * b.d + 100 * c.d + 1000 * e.d + 1)';

        return $db->newQuery()
            ->select("{$n} AS n", false)
            ->from("{$digitos} AS a", true)
            ->join("{$digitos} AS b", '1 = 1', 'inner', false)
            ->join("{$digitos} AS c", '1 = 1', 'inner', false)
            ->join("{$digitos} AS e", '1 = 1', 'inner', false)
            ->where("{$n} <= " . $this->quantidade, null, false);
    }

    protected function chavePrimaria(): string
    {
        return '(a.d + 10 * b.d + 100 * c.d + 1000 * e.d)';
    }

    protected function definirColunas(): array
    {
        $colunas = [
            ImportadorNovosItens::COLUNA_MODELO => Coluna::nova('modelo_id', lang('Patrimonio.fieldModelId'))
                ->texto(fn (): string => (string) $this->modeloId),
            1 => Coluna::nova('modelo', lang('Patrimonio.fieldModel'))->texto(fn (): string => $this->modeloNome),
            ImportadorNovosItens::COLUNA_LOCAL => Coluna::nova('local_codigo', lang('Patrimonio.fieldLocationCode'))
                ->texto(fn (): string => $this->localValor),
            3 => Coluna::nova('local', lang('Patrimonio.fieldLocation'))->texto(fn (): string => $this->localRotulo),
            ImportadorNovosItens::COLUNA_NUMERO => Coluna::nova('numero', lang('Patrimonio.fieldNumber'))
                ->texto(static fn (): string => ''),
        ];

        ksort($colunas);

        return array_values($colunas);
    }

    public function temAcoes(): bool
    {
        return false;
    }
}

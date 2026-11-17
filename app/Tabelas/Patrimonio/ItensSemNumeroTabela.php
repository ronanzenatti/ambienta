<?php

namespace App\Tabelas\Patrimonio;

use App\Libraries\Patrimonio\ImportadorNumeros;
use App\Libraries\Patrimonio\Locais;
use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/**
 * Itens patrimoniais já lançados e ainda sem número, no escopo de EDIÇÃO do usuário (opcionalmente só os de um
 * modelo). Aparecem em amarelo: ficam pendentes (não podem ser movidos, alocados nem usados) até receberem o número.
 * A exportação desta listagem é a planilha de preenchimento em massa: a ordem das colunas (id na 1ª, número vazio
 * na 4ª) é a que o ImportadorNumeros lê de volta.
 */
final class ItensSemNumeroTabela extends Tabela
{
    /** @param array{global: bool, campus: list<int>, predio: list<int>} $escopo */
    public function __construct(private readonly array $escopo, private readonly ?int $modeloId = null)
    {
    }

    public function id(): string
    {
        return 'numeros-patrimonio';
    }

    public function titulo(): string
    {
        return lang('Patrimonio.numbersTitle');
    }

    public function urlLista(): string
    {
        return site_url('patrimonio/numeros');
    }

    public function urlExportar(): string
    {
        return site_url('patrimonio/numeros/exportar');
    }

    public function filtros(): array
    {
        return $this->modeloId === null ? [] : ['modelo' => (string) $this->modeloId];
    }

    protected function consulta(): BaseBuilder
    {
        $builder = db_connect()->table('itens i')
            ->select('i.id_item, i.nome, i.numero_patrimonio, i.patrimoniavel')
            ->select(Locais::rotuloSql('l') . ' AS local', false)
            ->where('i.deletado_em', null)
            ->where('i.patrimoniavel', 1)
            ->where('i.numero_patrimonio', null)
            ->whereIn('i.status', ['em_uso', 'almoxarifado']);

        Locais::juntar($builder, 'l', 'i.ambiente_id', 'i.almoxarifado_id');

        $escopo = Locais::condicaoEscopo($this->escopo, 'l');

        if ($escopo !== null) {
            $builder->where($escopo, null, false);
        }

        if ($this->modeloId !== null) {
            $builder->where('i.modelo_item_id', $this->modeloId);
        }

        return $builder;
    }

    protected function chavePrimaria(): string
    {
        return 'i.id_item';
    }

    protected function definirColunas(): array
    {
        $colunas = [
            ImportadorNumeros::COLUNA_ID => Coluna::nova('id_item', lang('Patrimonio.fieldItemId'), 'i.id_item')
                ->numerica(),
            1 => Coluna::nova('nome', lang('Patrimonio.fieldItem'), 'i.nome')->principal(),
            2 => Coluna::nova('local', lang('Patrimonio.fieldLocation'), Locais::rotuloSql('l')),
            ImportadorNumeros::COLUNA_NUMERO => Coluna::nova('numero', lang('Patrimonio.fieldNumber'))
                ->texto(static fn (): string => ''),
        ];

        ksort($colunas);

        return array_values($colunas);
    }

    /** Todas as linhas são pendências de número: em amarelo (o aviso em texto fica acima da tabela). */
    public function classeLinha(array $linha): string
    {
        return 'bg-action-edit/15';
    }

    public function temAcoes(): bool
    {
        return false;
    }

    public function vazio(): string
    {
        return lang('Patrimonio.numbersEmpty');
    }
}

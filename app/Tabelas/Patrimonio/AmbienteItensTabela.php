<?php

namespace App\Tabelas\Patrimonio;

use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use App\Models\Patrimonio\ItemModel;
use CodeIgniter\Database\BaseBuilder;

/**
 * Itens que estão num ambiente (cartão da página do ambiente): patrimoniais, um por número, e sem patrimônio, por
 * quantidade. Patrimonial sem número aparece em amarelo, avisado em texto: não pode ser movido nem usado.
 * Excluir devolve o item ao almoxarifado do campus por movimentação; item em trânsito ou sem número não tem
 * ação de devolver (em trânsito já está em movimentação).
 */
final class AmbienteItensTabela extends Tabela
{
    public function __construct(private readonly int $ambienteId, private readonly bool $podeEditar)
    {
    }

    public function id(): string
    {
        return 'ambiente-itens';
    }

    public function titulo(): string
    {
        return lang('Patrimonio.itemsInEnvironment');
    }

    public function urlLista(): string
    {
        return site_url("ambientes/{$this->ambienteId}/itens");
    }

    public function urlExportar(): string
    {
        return site_url("ambientes/{$this->ambienteId}/itens/exportar");
    }

    public function independente(): bool
    {
        return true;
    }

    protected function consulta(): BaseBuilder
    {
        return db_connect()->table('itens i')
            ->select('i.id_item, i.numero_patrimonio, i.patrimoniavel, i.nome, i.quantidade, i.status')
            ->select('c.nome AS categoria')
            ->join('categorias_itens c', 'c.id_categoria_item = i.categoria_item_id')
            ->where('i.ambiente_id', $this->ambienteId)
            ->where('i.deletado_em', null);
    }

    protected function chavePrimaria(): string
    {
        return 'i.id_item';
    }

    protected function definirColunas(): array
    {
        return [
            ItensTabela::colunaNumero(),
            Coluna::nova('nome', lang('Institucional.fieldName'), 'i.nome')->principal(),
            Coluna::nova('categoria', lang('Patrimonio.fieldCategory'), 'c.nome'),
            Coluna::nova('quantidade', lang('Patrimonio.fieldQuantity'), 'i.quantidade')->numerica(),
            Coluna::nova('status', lang('Ui.status'), 'i.status')
                ->rotulos(opcoes_traduzidas('Patrimonio.status_', ItensTabela::STATUS))
                ->html(static fn (array $linha, string $texto): string => ui_status_dot(
                    ItensTabela::tom((string) $linha['status']),
                    $texto
                )),
        ];
    }

    public function classeLinha(array $linha): string
    {
        return ItemModel::pendente($linha) ? 'bg-action-edit/15' : parent::classeLinha($linha);
    }

    public function acoes(array $linha): array
    {
        $id = (int) $linha['id_item'];

        return [
            'ver'     => site_url("patrimonio/itens/{$id}"),
            'excluir' => $this->podeEditar && $linha['status'] === 'em_uso' && ! ItemModel::pendente($linha)
                ? site_url("ambientes/{$this->ambienteId}/itens/{$id}")
                : null,
        ];
    }

    public function mensagemExclusao(array $linha): string
    {
        return lang('Patrimonio.returnItemText', [item_rotulo($linha)]);
    }

    public function tituloExclusao(): string
    {
        return lang('Patrimonio.returnItemTitle');
    }

    public function vazio(): string
    {
        return lang('Patrimonio.noItemsInEnvironment');
    }
}

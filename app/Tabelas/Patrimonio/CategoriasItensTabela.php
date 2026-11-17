<?php

namespace App\Tabelas\Patrimonio;

use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/** Listagem de categorias de item (catálogo global), com quantos modelos usam cada uma. */
final class CategoriasItensTabela extends Tabela
{
    public function __construct(private readonly bool $podeEditar)
    {
    }

    public function id(): string
    {
        return 'categorias-itens';
    }

    public function titulo(): string
    {
        return lang('Patrimonio.categoriesTitle');
    }

    public function urlLista(): string
    {
        return site_url('patrimonio/categorias');
    }

    public function urlExportar(): string
    {
        return site_url('patrimonio/categorias/exportar');
    }

    protected function consulta(): BaseBuilder
    {
        return db_connect()->table('categorias_itens')
            ->select('categorias_itens.id_categoria_item, categorias_itens.nome, categorias_itens.descricao')
            ->select(
                '(SELECT COUNT(*) FROM modelos_itens m WHERE m.categoria_item_id = categorias_itens.id_categoria_item'
                . ' AND m.deletado_em IS NULL) AS modelos',
                false
            )
            ->where('categorias_itens.deletado_em', null);
    }

    protected function chavePrimaria(): string
    {
        return 'categorias_itens.id_categoria_item';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('nome', lang('Institucional.fieldName'), 'categorias_itens.nome')->principal(),
            Coluna::nova('descricao', lang('Institucional.fieldDescription'), 'categorias_itens.descricao'),
            Coluna::nova('modelos', lang('Patrimonio.modelsCount'))->numerica(),
        ];
    }

    public function temAcoes(): bool
    {
        return $this->podeEditar;
    }

    public function formulariosEmModal(): bool
    {
        return true;
    }

    public function acoes(array $linha): array
    {
        $id = (int) $linha['id_categoria_item'];

        return [
            'editar'  => site_url("patrimonio/categorias/{$id}/editar"),
            'excluir' => site_url("patrimonio/categorias/{$id}"),
        ];
    }

    public function mensagemExclusao(array $linha): string
    {
        return lang('Patrimonio.categoryDeleteText', [(string) $linha['nome']]);
    }

    public function tituloExclusao(): string
    {
        return lang('Patrimonio.categoryDeleteTitle');
    }

    public function vazio(): string
    {
        return lang('Patrimonio.categoriesEmpty');
    }
}

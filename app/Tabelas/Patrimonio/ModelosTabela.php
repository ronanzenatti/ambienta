<?php

namespace App\Tabelas\Patrimonio;

use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/** Modelos de item (catálogo global), com o tipo de controle (patrimônio ou quantidade). */
final class ModelosTabela extends Tabela
{
    public function __construct(private readonly bool $podeEditar)
    {
    }

    public function id(): string
    {
        return 'modelos';
    }

    public function titulo(): string
    {
        return lang('Patrimonio.modelsTitle');
    }

    public function urlLista(): string
    {
        return site_url('patrimonio/modelos');
    }

    public function urlExportar(): string
    {
        return site_url('patrimonio/modelos/exportar');
    }

    protected function consulta(): BaseBuilder
    {
        return db_connect()->table('modelos_itens m')
            ->select('m.id_modelo_item, m.nome, m.marca, m.patrimoniavel, c.nome AS categoria')
            ->join('categorias_itens c', 'c.id_categoria_item = m.categoria_item_id')
            ->where('m.deletado_em', null);
    }

    protected function chavePrimaria(): string
    {
        return 'm.id_modelo_item';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('nome', lang('Institucional.fieldName'), 'm.nome')
                ->principal()
                ->html(static fn (array $linha, string $texto): string => '<a href="'
                    . esc(site_url('patrimonio/modelos/' . $linha['id_modelo_item']), 'attr')
                    . '" class="inline-flex min-h-11 items-center underline">' . esc($texto) . '</a>'),
            Coluna::nova('marca', lang('Patrimonio.fieldBrand'), 'm.marca'),
            Coluna::nova('categoria', lang('Patrimonio.fieldCategory'), 'c.nome'),
            Coluna::nova('patrimoniavel', lang('Patrimonio.fieldControl'), 'm.patrimoniavel')
                ->rotulos([1 => lang('Patrimonio.controlAsset'), 0 => lang('Patrimonio.controlQuantity')]),
        ];
    }

    public function temExclusao(): bool
    {
        return $this->podeEditar;
    }

    public function acoes(array $linha): array
    {
        $id = (int) $linha['id_modelo_item'];

        return [
            'ver'     => site_url("patrimonio/modelos/{$id}"),
            'editar'  => $this->podeEditar ? site_url("patrimonio/modelos/{$id}/editar") : null,
            'excluir' => $this->podeEditar ? site_url("patrimonio/modelos/{$id}") : null,
        ];
    }

    public function mensagemExclusao(array $linha): string
    {
        return lang('Patrimonio.modelDeleteText', [(string) $linha['nome']]);
    }

    public function tituloExclusao(): string
    {
        return lang('Patrimonio.modelDeleteTitle');
    }

    public function vazio(): string
    {
        return lang('Patrimonio.modelsEmpty');
    }
}

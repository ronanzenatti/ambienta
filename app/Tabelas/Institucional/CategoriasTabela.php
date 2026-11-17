<?php

namespace App\Tabelas\Institucional;

use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/** Listagem de categorias de ambiente (catálogo global). */
final class CategoriasTabela extends Tabela
{
    public function __construct(private readonly bool $podeEditar)
    {
    }

    public function id(): string
    {
        return 'categorias';
    }

    public function titulo(): string
    {
        return lang('Categorias.title');
    }

    public function urlLista(): string
    {
        return site_url('categorias-ambiente');
    }

    public function urlExportar(): string
    {
        return site_url('categorias-ambiente/exportar');
    }

    protected function consulta(): BaseBuilder
    {
        return db_connect()->table('categorias_ambientes')
            ->select('id_categoria_ambiente, nome, descricao')
            ->where('categorias_ambientes.deletado_em', null);
    }

    protected function chavePrimaria(): string
    {
        return 'categorias_ambientes.id_categoria_ambiente';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('nome', lang('Institucional.fieldName'), 'categorias_ambientes.nome')->principal(),
            Coluna::nova('descricao', lang('Institucional.fieldDescription'), 'categorias_ambientes.descricao'),
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
        $id = (int) $linha['id_categoria_ambiente'];

        return [
            'editar'  => site_url("categorias-ambiente/{$id}/editar"),
            'excluir' => site_url("categorias-ambiente/{$id}"),
        ];
    }

    public function mensagemExclusao(array $linha): string
    {
        return lang('Categorias.deleteText', [(string) $linha['nome']]);
    }

    public function tituloExclusao(): string
    {
        return lang('Categorias.deleteTitle');
    }

    public function vazio(): string
    {
        return lang('Categorias.empty');
    }
}

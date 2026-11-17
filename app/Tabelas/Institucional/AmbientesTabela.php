<?php

namespace App\Tabelas\Institucional;

use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/** Ambientes de um prédio (na tela do prédio). */
final class AmbientesTabela extends Tabela
{
    public function __construct(private readonly int $predioId, private readonly bool $podeEditar)
    {
    }

    public function id(): string
    {
        return 'ambientes';
    }

    public function titulo(): string
    {
        return lang('Predios.environments');
    }

    public function urlLista(): string
    {
        return site_url("predios/{$this->predioId}");
    }

    public function urlExportar(): string
    {
        return site_url("predios/{$this->predioId}/ambientes/exportar");
    }

    protected function consulta(): BaseBuilder
    {
        return db_connect()->table('ambientes')
            ->select('ambientes.id_ambiente, ambientes.numero, ambientes.nome, ambientes.capacidade, ambientes.ativo')
            ->select('categorias_ambientes.nome AS categoria')
            ->join(
                'categorias_ambientes',
                'categorias_ambientes.id_categoria_ambiente = ambientes.categoria_ambiente_id'
            )
            ->where('ambientes.predio_id', $this->predioId)
            ->where('ambientes.deletado_em', null);
    }

    protected function chavePrimaria(): string
    {
        return 'ambientes.id_ambiente';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('numero', lang('Institucional.fieldNumber'), 'ambientes.numero'),
            Coluna::nova('nome', lang('Institucional.fieldName'), 'ambientes.nome')
                ->principal()
                ->html(static fn (array $linha, string $texto): string => '<a href="'
                    . esc(site_url('ambientes/' . $linha['id_ambiente']), 'attr')
                    . '" class="inline-flex min-h-11 items-center underline">' . esc($texto) . '</a>'),
            Coluna::nova('categoria', lang('Ambientes.fieldCategory'), 'categorias_ambientes.nome'),
            Coluna::nova('capacidade', lang('Ambientes.fieldCapacity'), 'ambientes.capacidade')->numerica(),
            Coluna::ativo('ambientes.ativo'),
        ];
    }

    public function temExclusao(): bool
    {
        return $this->podeEditar;
    }

    public function acoes(array $linha): array
    {
        $id = (int) $linha['id_ambiente'];

        return [
            'ver'     => site_url("ambientes/{$id}"),
            'editar'  => $this->podeEditar ? site_url("ambientes/{$id}/editar") : null,
            'excluir' => $this->podeEditar ? site_url("ambientes/{$id}") : null,
        ];
    }

    public function mensagemExclusao(array $linha): string
    {
        return lang('Ambientes.deleteText', [(string) $linha['nome']]);
    }

    public function tituloExclusao(): string
    {
        return lang('Ambientes.deleteTitle');
    }

    public function vazio(): string
    {
        return lang('Predios.noEnvironments');
    }
}

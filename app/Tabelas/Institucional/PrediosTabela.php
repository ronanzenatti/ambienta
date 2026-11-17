<?php

namespace App\Tabelas\Institucional;

use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use Closure;
use CodeIgniter\Database\BaseBuilder;

/** Prédios de um campus (na tela do campus), só os que o usuário enxerga. */
final class PrediosTabela extends Tabela
{
    /**
     * @param list<int>|null                        $visiveis   prédios visíveis (null = todos os do campus)
     * @param Closure(array<string, mixed>): bool   $podeEditar prédio => pode alterar
     * @param bool                                  $podeExcluir editor no campus
     */
    public function __construct(
        private readonly int $campusId,
        private readonly ?array $visiveis,
        private readonly Closure $podeEditar,
        private readonly bool $podeExcluir,
    ) {
    }

    public function id(): string
    {
        return 'predios';
    }

    public function titulo(): string
    {
        return lang('Campus.buildings');
    }

    public function urlLista(): string
    {
        return site_url("campus/{$this->campusId}");
    }

    public function urlExportar(): string
    {
        return site_url("campus/{$this->campusId}/predios/exportar");
    }

    protected function consulta(): BaseBuilder
    {
        $builder = db_connect()->table('predios')
            ->select('id_predio, campus_id, nome, abreviacao, numero')
            ->where('predios.campus_id', $this->campusId)
            ->where('predios.deletado_em', null);

        if ($this->visiveis !== null) {
            $this->visiveis === []
                ? $builder->where('1 = 0', null, false)
                : $builder->whereIn('predios.id_predio', $this->visiveis);
        }

        return $builder;
    }

    protected function chavePrimaria(): string
    {
        return 'predios.id_predio';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('nome', lang('Institucional.fieldName'), 'predios.nome')
                ->principal()
                ->html(static fn (array $linha, string $texto): string => '<a href="'
                    . esc(site_url('predios/' . $linha['id_predio']), 'attr')
                    . '" class="inline-flex min-h-11 items-center underline">' . esc($texto) . '</a>'),
            Coluna::nova('abreviacao', lang('Institucional.fieldAbbreviation'), 'predios.abreviacao'),
            Coluna::nova('numero', lang('Institucional.fieldNumber'), 'predios.numero'),
        ];
    }

    public function temExclusao(): bool
    {
        return $this->podeExcluir;
    }

    public function acoes(array $linha): array
    {
        $id = (int) $linha['id_predio'];

        return [
            'ver'     => site_url("predios/{$id}"),
            'editar'  => ($this->podeEditar)($linha) ? site_url("predios/{$id}/editar") : null,
            'excluir' => $this->podeExcluir ? site_url("predios/{$id}") : null,
        ];
    }

    public function mensagemExclusao(array $linha): string
    {
        return lang('Predios.deleteText', [(string) $linha['nome']]);
    }

    public function tituloExclusao(): string
    {
        return lang('Predios.deleteTitle');
    }

    public function vazio(): string
    {
        return lang('Campus.noBuildings');
    }
}

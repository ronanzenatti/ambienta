<?php

namespace App\Tabelas\Institucional;

use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use Closure;
use CodeIgniter\Database\BaseBuilder;

/**
 * Prédios de todos os campi que o usuário enxerga (item "Prédios" do menu), com o campus de cada um e filtro
 * por campus. A navegação Campus > Prédio continua em PrediosTabela.
 */
final class TodosPrediosTabela extends Tabela
{
    /**
     * @param array{global: bool, campus: list<int>, predio: list<int>} $escopo      onde o usuário tem leitura
     * @param Closure(array<string, mixed>): bool                       $podeEditar  prédio => pode alterar
     * @param Closure(int): bool                                        $podeExcluir id do campus => pode excluir
     */
    public function __construct(
        private readonly array $escopo,
        private readonly ?int $campusId,
        private readonly Closure $podeEditar,
        private readonly Closure $podeExcluir,
        private readonly bool $temExclusao,
    ) {
    }

    public function id(): string
    {
        return 'predios';
    }

    public function titulo(): string
    {
        return lang('Predios.title');
    }

    public function urlLista(): string
    {
        return site_url('predios');
    }

    public function urlExportar(): string
    {
        return site_url('predios/exportar');
    }

    public function filtros(): array
    {
        return $this->campusId === null ? [] : ['campus' => (string) $this->campusId];
    }

    protected function consulta(): BaseBuilder
    {
        $builder = db_connect()->table('predios')
            ->select('predios.id_predio, predios.campus_id, predios.nome, predios.abreviacao, predios.numero')
            ->select('campus.nome AS campus')
            ->join('campus', 'campus.id_campus = predios.campus_id AND campus.deletado_em IS NULL')
            ->where('predios.deletado_em', null);

        if (! $this->escopo['global']) {
            $builder->groupStart()
                ->whereIn('predios.campus_id', $this->escopo['campus'] === [] ? [0] : $this->escopo['campus'])
                ->orWhereIn('predios.id_predio', $this->escopo['predio'] === [] ? [0] : $this->escopo['predio'])
                ->groupEnd();
        }

        if ($this->campusId !== null) {
            $builder->where('predios.campus_id', $this->campusId);
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
            Coluna::nova('campus', lang('Predios.colCampus'), 'campus.nome'),
            Coluna::nova('abreviacao', lang('Institucional.fieldAbbreviation'), 'predios.abreviacao'),
            Coluna::nova('numero', lang('Institucional.fieldNumber'), 'predios.numero'),
        ];
    }

    public function temExclusao(): bool
    {
        return $this->temExclusao;
    }

    public function acoes(array $linha): array
    {
        $id = (int) $linha['id_predio'];

        return [
            'ver'     => site_url("predios/{$id}"),
            'editar'  => ($this->podeEditar)($linha) ? site_url("predios/{$id}/editar") : null,
            'excluir' => ($this->podeExcluir)((int) $linha['campus_id']) ? site_url("predios/{$id}") : null,
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
        return lang('Predios.empty');
    }
}

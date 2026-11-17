<?php

namespace App\Tabelas\Institucional;

use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use Closure;
use CodeIgniter\Database\BaseBuilder;

/**
 * Ambientes de todos os prédios que o usuário enxerga (item "Ambientes" do menu): a coluna Local mostra o
 * campus e, abaixo, o prédio; filtros por campus e prédio. A navegação Campus > Prédio > Ambiente continua em
 * AmbientesTabela.
 */
final class TodosAmbientesTabela extends Tabela
{
    private const LOCAL = "CONCAT_WS(' › ', campus.nome, predios.nome)";

    /**
     * @param array{global: bool, campus: list<int>, predio: list<int>} $escopo      onde o usuário tem leitura
     * @param Closure(array<string, mixed>): bool                       $podeEditar  prédio (campus_id, id_predio)
     */
    public function __construct(
        private readonly array $escopo,
        private readonly ?int $campusId,
        private readonly ?int $predioId,
        private readonly Closure $podeEditar,
        private readonly bool $temExclusao,
    ) {
    }

    public function id(): string
    {
        return 'ambientes';
    }

    public function titulo(): string
    {
        return lang('Ambientes.title');
    }

    public function urlLista(): string
    {
        return site_url('ambientes');
    }

    public function urlExportar(): string
    {
        return site_url('ambientes/exportar');
    }

    public function filtros(): array
    {
        return array_filter([
            'campus' => $this->campusId === null ? '' : (string) $this->campusId,
            'predio' => $this->predioId === null ? '' : (string) $this->predioId,
        ], static fn (string $valor): bool => $valor !== '');
    }

    protected function consulta(): BaseBuilder
    {
        $builder = db_connect()->table('ambientes')
            ->select('ambientes.id_ambiente, ambientes.predio_id, ambientes.numero, ambientes.nome')
            ->select('ambientes.capacidade, ambientes.ativo, predios.campus_id')
            ->select('campus.nome AS campus, predios.nome AS predio, categorias_ambientes.nome AS categoria')
            ->join('predios', 'predios.id_predio = ambientes.predio_id AND predios.deletado_em IS NULL')
            ->join('campus', 'campus.id_campus = predios.campus_id AND campus.deletado_em IS NULL')
            ->join(
                'categorias_ambientes',
                'categorias_ambientes.id_categoria_ambiente = ambientes.categoria_ambiente_id'
            )
            ->where('ambientes.deletado_em', null);

        if (! $this->escopo['global']) {
            $builder->groupStart()
                ->whereIn('predios.campus_id', $this->escopo['campus'] === [] ? [0] : $this->escopo['campus'])
                ->orWhereIn('ambientes.predio_id', $this->escopo['predio'] === [] ? [0] : $this->escopo['predio'])
                ->groupEnd();
        }

        if ($this->campusId !== null) {
            $builder->where('predios.campus_id', $this->campusId);
        }

        if ($this->predioId !== null) {
            $builder->where('ambientes.predio_id', $this->predioId);
        }

        return $builder;
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
            Coluna::nova('local', lang('Ambientes.colLocation'), self::LOCAL)
                ->buscaEm('campus.nome', 'predios.nome')
                ->texto(static fn (array $linha): string => $linha['campus'] . ' › ' . $linha['predio'])
                ->html(static fn (array $linha): string => esc((string) $linha['campus'])
                    . '<br><span class="text-sm text-ink-muted">' . esc((string) $linha['predio']) . '</span>'),
            Coluna::nova('categoria', lang('Ambientes.fieldCategory'), 'categorias_ambientes.nome'),
            Coluna::nova('capacidade', lang('Ambientes.fieldCapacity'), 'ambientes.capacidade')->numerica(),
            Coluna::ativo('ambientes.ativo'),
        ];
    }

    public function temExclusao(): bool
    {
        return $this->temExclusao;
    }

    public function acoes(array $linha): array
    {
        $id     = (int) $linha['id_ambiente'];
        $predio = ['campus_id' => $linha['campus_id'], 'id_predio' => $linha['predio_id']];
        $edita  = ($this->podeEditar)($predio);

        return [
            'ver'     => site_url("ambientes/{$id}"),
            'editar'  => $edita ? site_url("ambientes/{$id}/editar") : null,
            'excluir' => $edita ? site_url("ambientes/{$id}") : null,
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
        return lang('Ambientes.empty');
    }
}

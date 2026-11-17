<?php

namespace App\Tabelas\Institucional;

use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use Closure;
use CodeIgniter\Database\BaseBuilder;

/** Listagem dos campi que o usuário enxerga. */
final class CampusTabela extends Tabela
{
    private const CIDADE = "CONCAT_WS(' / ', NULLIF(campus.cidade, ''), NULLIF(campus.uf, ''))";

    private const ENDERECO = "CONCAT_WS(', ', NULLIF(campus.logradouro, ''), NULLIF(campus.numero_endereco, ''),"
        . " NULLIF(campus.complemento, ''), NULLIF(campus.bairro, ''), NULLIF(campus.cidade, ''),"
        . " NULLIF(campus.uf, ''), NULLIF(campus.cep, ''))";

    /**
     * @param list<int>|null        $visiveis    campi visíveis (null = todos)
     * @param Closure(int): bool    $podeEditar  id do campus => pode alterar
     */
    public function __construct(
        private readonly ?array $visiveis,
        private readonly Closure $podeEditar,
        private readonly bool $podeExcluir,
    ) {
    }

    public function id(): string
    {
        return 'campus';
    }

    public function titulo(): string
    {
        return lang('Campus.title');
    }

    public function urlLista(): string
    {
        return site_url('campus');
    }

    public function urlExportar(): string
    {
        return site_url('campus/exportar');
    }

    protected function consulta(): BaseBuilder
    {
        $builder = db_connect()->table('campus')
            ->select(
                'id_campus, nome, abreviacao, ativo, ' . self::CIDADE . ' AS cidade_uf, '
                . self::ENDERECO . ' AS endereco',
                false,
            )
            ->where('campus.deletado_em', null);

        if ($this->visiveis !== null) {
            $this->visiveis === []
                ? $builder->where('1 = 0', null, false)
                : $builder->whereIn('campus.id_campus', $this->visiveis);
        }

        return $builder;
    }

    protected function chavePrimaria(): string
    {
        return 'campus.id_campus';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('nome', lang('Institucional.fieldName'), 'campus.nome')
                ->principal()
                ->buscaEm('campus.nome', self::ENDERECO)
                ->html(static fn (array $linha, string $texto): string => '<a href="'
                    . esc(site_url('campus/' . $linha['id_campus']), 'attr')
                    . '" class="underline">' . esc($texto) . '</a>'
                    . (($linha['endereco'] ?? '') !== ''
                        ? '<br><span class="text-sm text-ink-muted">' . esc((string) $linha['endereco']) . '</span>'
                        : '')),
            Coluna::nova('abreviacao', lang('Institucional.fieldAbbreviation'), 'campus.abreviacao'),
            Coluna::nova('cidade_uf', lang('Institucional.fieldCity'), self::CIDADE),
            Coluna::ativo('campus.ativo'),
        ];
    }

    public function temExclusao(): bool
    {
        return $this->podeExcluir;
    }

    public function acoes(array $linha): array
    {
        $id = (int) $linha['id_campus'];

        return [
            'ver'     => site_url("campus/{$id}"),
            'editar'  => ($this->podeEditar)($id) ? site_url("campus/{$id}/editar") : null,
            'excluir' => $this->podeExcluir ? site_url("campus/{$id}") : null,
        ];
    }

    public function mensagemExclusao(array $linha): string
    {
        return lang('Campus.deleteText', [(string) $linha['nome']]);
    }

    public function tituloExclusao(): string
    {
        return lang('Campus.deleteTitle');
    }

    public function vazio(): string
    {
        return lang('Campus.empty');
    }
}

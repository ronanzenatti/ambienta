<?php

namespace App\Tabelas\Institucional;

use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/** Listagem de perfis (só o superperfil acessa). O superperfil não se altera nem se exclui: só se vê. */
final class PerfisTabela extends Tabela
{
    private const VINCULOS = '(SELECT COUNT(*) FROM usuarios_perfis up
        WHERE up.perfil_id = perfis.id_perfil AND up.deletado_em IS NULL)';

    public function id(): string
    {
        return 'perfis';
    }

    public function titulo(): string
    {
        return lang('Perfis.title');
    }

    public function urlLista(): string
    {
        return site_url('perfis');
    }

    public function urlExportar(): string
    {
        return site_url('perfis/exportar');
    }

    protected function consulta(): BaseBuilder
    {
        return db_connect()->table('perfis')
            ->select('id_perfil, nome, super_perfil, ' . self::VINCULOS . ' AS vinculos', false)
            ->where('perfis.deletado_em', null);
    }

    protected function chavePrimaria(): string
    {
        return 'perfis.id_perfil';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('nome', lang('Perfis.colName'), 'perfis.nome')->principal(),
            Coluna::nova('super_perfil', lang('Perfis.colType'), 'perfis.super_perfil')
                ->rotulos([1 => lang('Perfis.typeSuper'), 0 => lang('Perfis.typeCustom')]),
            Coluna::nova('vinculos', lang('Perfis.colUsers'), self::VINCULOS)->numerica()->semBusca(),
        ];
    }

    public function acoes(array $linha): array
    {
        $id  = (int) $linha['id_perfil'];
        $url = site_url("perfis/{$id}");

        return (bool) $linha['super_perfil']
            ? ['ver' => $url]
            : ['editar' => $url, 'excluir' => $url];
    }

    public function mensagemExclusao(array $linha): string
    {
        return lang('Perfis.deleteText', [(string) $linha['nome']]);
    }

    public function tituloExclusao(): string
    {
        return lang('Perfis.deleteTitle');
    }
}

<?php

namespace App\Tabelas\Institucional;

use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/** Listagem dos domínios de e-mail permitidos no login com Google (só o superperfil acessa). */
final class DominiosTabela extends Tabela
{
    public function id(): string
    {
        return 'dominios';
    }

    public function titulo(): string
    {
        return lang('Dominios.title');
    }

    public function urlLista(): string
    {
        return site_url('dominios');
    }

    public function urlExportar(): string
    {
        return site_url('dominios/exportar');
    }

    protected function consulta(): BaseBuilder
    {
        return db_connect()->table('dominios_email_permitidos')
            ->select('id_dominio_email_permitido, dominio, ativo')
            ->where('dominios_email_permitidos.deletado_em', null);
    }

    protected function chavePrimaria(): string
    {
        return 'dominios_email_permitidos.id_dominio_email_permitido';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('dominio', lang('Dominios.colDomain'), 'dominios_email_permitidos.dominio')->principal(),
            Coluna::ativo('dominios_email_permitidos.ativo'),
        ];
    }

    public function formulariosEmModal(): bool
    {
        return true;
    }

    /** Ativar/desativar é ação própria desta tela: formulário POST com ícone, antes de excluir. */
    public function acoes(array $linha): array
    {
        $id     = (int) $linha['id_dominio_email_permitido'];
        $rotulo = lang((bool) $linha['ativo'] ? 'Dominios.deactivate' : 'Dominios.activate');

        $alternar = '<form method="post" action="' . esc(site_url("dominios/{$id}/alternar"), 'attr') . '">'
            . csrf_field()
            . ui_icon_button('energia', $rotulo, $rotulo . ': ' . $linha['dominio'], 'secondary', ['type' => 'submit'])
            . '</form>';

        return ['extra' => $alternar, 'excluir' => site_url("dominios/{$id}")];
    }

    public function mensagemExclusao(array $linha): string
    {
        return lang('Dominios.deleteText', [(string) $linha['dominio']]);
    }

    public function tituloExclusao(): string
    {
        return lang('Dominios.deleteTitle');
    }

    public function vazio(): string
    {
        return lang('Dominios.empty');
    }
}

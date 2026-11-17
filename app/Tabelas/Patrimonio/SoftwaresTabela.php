<?php

namespace App\Tabelas\Patrimonio;

use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/**
 * Listagem de softwares (catálogo global), com a situação (aprovado, pendente, reprovado) e em quantos ambientes
 * cada um está instalado. $apenasPendentes: a fila de aprovação; $podeAprovar abre a revisão dos pendentes.
 */
final class SoftwaresTabela extends Tabela
{
    public function __construct(
        private readonly bool $podeEditar,
        private readonly bool $podeAprovar = false,
        private readonly bool $apenasPendentes = false,
    ) {
    }

    public function id(): string
    {
        return $this->apenasPendentes ? 'softwares-pendentes' : 'softwares';
    }

    public function titulo(): string
    {
        return lang($this->apenasPendentes ? 'Patrimonio.softwaresQueueTitle' : 'Patrimonio.softwaresTitle');
    }

    public function urlLista(): string
    {
        return site_url('patrimonio/softwares' . ($this->apenasPendentes ? '/pendentes' : ''));
    }

    public function urlExportar(): string
    {
        return site_url('patrimonio/softwares' . ($this->apenasPendentes ? '/pendentes' : '') . '/exportar');
    }

    protected function consulta(): BaseBuilder
    {
        $builder = db_connect()->table('softwares')
            ->select('softwares.id_software, softwares.nome, softwares.versao, softwares.status')
            ->select('softwares.requisitos_tecnicos')
            ->select(
                '(SELECT COUNT(*) FROM ambientes_softwares v JOIN ambientes a ON a.id_ambiente = v.ambiente_id'
                . ' AND a.deletado_em IS NULL WHERE v.software_id = softwares.id_software'
                . ' AND v.deletado_em IS NULL) AS ambientes',
                false
            )
            ->where('softwares.deletado_em', null);

        return $this->apenasPendentes ? $builder->where('softwares.status', 'pendente') : $builder;
    }

    protected function chavePrimaria(): string
    {
        return 'softwares.id_software';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('nome', lang('Institucional.fieldName'), 'softwares.nome')->principal(),
            Coluna::nova('versao', lang('Patrimonio.fieldVersion'), 'softwares.versao'),
            Coluna::nova('requisitos_tecnicos', lang('Patrimonio.fieldRequirements'), 'softwares.requisitos_tecnicos')
                ->semOrdem(),
            Coluna::nova('status', lang('Patrimonio.softwareStatus'), 'softwares.status')
                ->rotulos(opcoes_traduzidas('Patrimonio.softwareStatus_', ['aprovado', 'pendente', 'reprovado']))
                ->html(static fn (array $linha, string $texto): string => ui_status_dot(
                    match ($linha['status']) {
                        'aprovado' => 'confirmed',
                        'pendente' => 'pending',
                        default    => 'critical',
                    },
                    $texto
                )),
            Coluna::nova('ambientes', lang('Patrimonio.environmentsCount'))->numerica(),
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
        $id = (int) $linha['id_software'];

        return [
            'ver'     => $this->podeAprovar && $linha['status'] === 'pendente'
                ? site_url("patrimonio/softwares/{$id}/revisar")
                : null,
            'editar'  => site_url("patrimonio/softwares/{$id}/editar"),
            'excluir' => site_url("patrimonio/softwares/{$id}"),
        ];
    }

    public function mensagemExclusao(array $linha): string
    {
        return lang('Patrimonio.softwareDeleteText', [trim($linha['nome'] . ' ' . ($linha['versao'] ?? ''))]);
    }

    public function tituloExclusao(): string
    {
        return lang('Patrimonio.softwareDeleteTitle');
    }

    public function vazio(): string
    {
        return lang('Patrimonio.softwaresEmpty');
    }
}

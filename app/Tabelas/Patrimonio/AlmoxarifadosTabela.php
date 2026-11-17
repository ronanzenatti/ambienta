<?php

namespace App\Tabelas\Patrimonio;

use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/** Almoxarifados (um por campus) no escopo de leitura do usuário, com a contagem de itens guardados. */
final class AlmoxarifadosTabela extends Tabela
{
    /**
     * @param array{global: bool, campus: list<int>, predio: list<int>} $escopoLeitura
     * @param array{global: bool, campus: list<int>, predio: list<int>} $escopoEdicao
     */
    public function __construct(private readonly array $escopoLeitura, private readonly array $escopoEdicao)
    {
    }

    public function id(): string
    {
        return 'almoxarifados';
    }

    public function titulo(): string
    {
        return lang('Patrimonio.warehousesTitle');
    }

    public function urlLista(): string
    {
        return site_url('patrimonio/almoxarifados');
    }

    public function urlExportar(): string
    {
        return site_url('patrimonio/almoxarifados/exportar');
    }

    protected function consulta(): BaseBuilder
    {
        $builder = db_connect()->table('almoxarifados')
            ->select('almoxarifados.id_almoxarifado, almoxarifados.nome, almoxarifados.campus_id')
            ->select("COALESCE(NULLIF(TRIM(campus.abreviacao), ''), campus.nome) AS campus", false)
            ->select(
                "(SELECT COUNT(*) FROM itens i WHERE i.almoxarifado_id = almoxarifados.id_almoxarifado"
                . ' AND i.deletado_em IS NULL) AS itens',
                false
            )
            ->join('campus', 'campus.id_campus = almoxarifados.campus_id')
            ->where('almoxarifados.deletado_em', null);

        if (! $this->escopoLeitura['global']) {
            $builder->whereIn('almoxarifados.campus_id', $this->escopoLeitura['campus'] ?: [0]);
        }

        return $builder;
    }

    protected function chavePrimaria(): string
    {
        return 'almoxarifados.id_almoxarifado';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('nome', lang('Institucional.fieldName'), 'almoxarifados.nome')->principal(),
            Coluna::nova('campus', lang('Patrimonio.fieldCampus'), 'campus.nome'),
            Coluna::nova('itens', lang('Patrimonio.itemsCount'))->numerica(),
        ];
    }

    public function temAcoes(): bool
    {
        return $this->escopoEdicao['global'] || $this->escopoEdicao['campus'] !== [];
    }

    public function formulariosEmModal(): bool
    {
        return true;
    }

    public function acoes(array $linha): array
    {
        $id   = (int) $linha['id_almoxarifado'];
        $pode = $this->escopoEdicao['global']
            || in_array((int) $linha['campus_id'], $this->escopoEdicao['campus'], true);

        return [
            'editar'  => $pode ? site_url("patrimonio/almoxarifados/{$id}/editar") : null,
            'excluir' => $pode ? site_url("patrimonio/almoxarifados/{$id}") : null,
        ];
    }

    public function mensagemExclusao(array $linha): string
    {
        return lang('Patrimonio.warehouseDeleteText', [(string) $linha['nome'], (string) $linha['campus']]);
    }

    public function tituloExclusao(): string
    {
        return lang('Patrimonio.warehouseDeleteTitle');
    }

    public function vazio(): string
    {
        return lang('Patrimonio.warehousesEmpty');
    }
}

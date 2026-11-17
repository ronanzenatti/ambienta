<?php

namespace App\Tabelas\Patrimonio;

use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/** Softwares instalados num ambiente (cartão da página do ambiente). */
final class AmbienteSoftwaresTabela extends Tabela
{
    public function __construct(
        private readonly int $ambienteId,
        private readonly string $ambienteNome,
        private readonly bool $podeEditar,
    ) {
    }

    public function id(): string
    {
        return 'ambiente-softwares';
    }

    public function titulo(): string
    {
        return lang('Patrimonio.softwaresInstalled');
    }

    public function urlLista(): string
    {
        return site_url("ambientes/{$this->ambienteId}/softwares");
    }

    public function urlExportar(): string
    {
        return site_url("ambientes/{$this->ambienteId}/softwares/exportar");
    }

    public function independente(): bool
    {
        return true;
    }

    protected function consulta(): BaseBuilder
    {
        return db_connect()->table('ambientes_softwares v')
            ->select('v.id_ambiente_software, s.nome, s.versao, s.requisitos_tecnicos')
            ->join('softwares s', 's.id_software = v.software_id AND s.deletado_em IS NULL')
            ->where('v.ambiente_id', $this->ambienteId)
            ->where('v.deletado_em', null);
    }

    protected function chavePrimaria(): string
    {
        return 'v.id_ambiente_software';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('nome', lang('Institucional.fieldName'), 's.nome')->principal(),
            Coluna::nova('versao', lang('Patrimonio.fieldVersion'), 's.versao'),
            Coluna::nova('requisitos_tecnicos', lang('Patrimonio.fieldRequirements'), 's.requisitos_tecnicos')
                ->semOrdem(),
        ];
    }

    public function temAcoes(): bool
    {
        return $this->podeEditar;
    }

    public function acoes(array $linha): array
    {
        $vinculo = (int) $linha['id_ambiente_software'];

        return ['excluir' => site_url("ambientes/{$this->ambienteId}/softwares/{$vinculo}")];
    }

    public function mensagemExclusao(array $linha): string
    {
        $software = trim($linha['nome'] . ' ' . ($linha['versao'] ?? ''));

        return lang('Patrimonio.unlinkSoftwareText', [$software, $this->ambienteNome]);
    }

    public function tituloExclusao(): string
    {
        return lang('Patrimonio.unlinkSoftwareTitle');
    }

    public function vazio(): string
    {
        return lang('Patrimonio.noSoftwareInstalled');
    }
}

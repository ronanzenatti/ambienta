<?php

namespace App\Tabelas\Chamados;

use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/**
 * Softwares exigidos por um requisito de disciplina: cartão da página do requisito. Software proposto
 * (pendente) ou reprovado aparece com a situação em texto e, se reprovado, o motivo.
 */
final class RequisitoSoftwaresTabela extends Tabela
{
    public function __construct(
        private readonly int $requisitoId,
        private readonly string $requisitoNome,
        private readonly bool $podeAlterar,
    ) {
    }

    public function id(): string
    {
        return 'requisito-softwares';
    }

    public function titulo(): string
    {
        return lang('Chamados.requiredSoftware');
    }

    public function urlLista(): string
    {
        return site_url("requisitos/{$this->requisitoId}/softwares");
    }

    public function urlExportar(): string
    {
        return site_url("requisitos/{$this->requisitoId}/softwares/exportar");
    }

    public function independente(): bool
    {
        return true;
    }

    protected function consulta(): BaseBuilder
    {
        return db_connect()->table('requisitos_disciplina_softwares rs')
            ->select('rs.id_requisito_disciplina_software, s.nome, s.versao, s.status, s.motivo_reprovacao')
            ->join('softwares s', 's.id_software = rs.software_id')
            ->where('rs.requisito_disciplina_id', $this->requisitoId)
            ->where('rs.deletado_em', null);
    }

    protected function chavePrimaria(): string
    {
        return 'rs.id_requisito_disciplina_software';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('nome', lang('Chamados.fieldSoftware'), 's.nome')
                ->principal()
                ->buscaEm('s.nome', 's.versao')
                ->texto(static fn (array $linha): string => trim($linha['nome'] . ' ' . ($linha['versao'] ?? ''))),
            Coluna::nova('status', lang('Ui.status'), 's.status')
                ->rotulos([
                    'aprovado'  => lang('Patrimonio.softwareStatus_aprovado'),
                    'pendente'  => lang('Patrimonio.softwareStatus_pendente'),
                    'reprovado' => lang('Patrimonio.softwareStatus_reprovado'),
                ])
                ->html(static fn (array $linha, string $texto): string => ui_status_dot(
                    match ($linha['status']) {
                        'aprovado' => 'confirmed',
                        'pendente' => 'pending',
                        default    => 'critical',
                    },
                    $texto
                ) . ($linha['status'] === 'reprovado' && $linha['motivo_reprovacao'] !== null
                    ? '<span class="block text-caption text-ink-muted">' . esc($linha['motivo_reprovacao']) . '</span>'
                    : '')),
        ];
    }

    public function temAcoes(): bool
    {
        return $this->podeAlterar;
    }

    public function acoes(array $linha): array
    {
        $vinculo = (int) $linha['id_requisito_disciplina_software'];

        return ['excluir' => site_url("requisitos/{$this->requisitoId}/softwares/{$vinculo}")];
    }

    public function mensagemExclusao(array $linha): string
    {
        $software = trim($linha['nome'] . ' ' . ($linha['versao'] ?? ''));

        return lang('Chamados.softwareRemoveText', [$software, $this->requisitoNome]);
    }

    public function tituloExclusao(): string
    {
        return lang('Chamados.softwareRemoveTitle');
    }

    public function vazio(): string
    {
        return lang('Chamados.noRequiredSoftware');
    }
}

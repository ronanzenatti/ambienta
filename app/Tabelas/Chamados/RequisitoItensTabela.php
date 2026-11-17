<?php

namespace App\Tabelas\Chamados;

use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/** Itens (por categoria) exigidos por um requisito de disciplina: cartão da página do requisito. */
final class RequisitoItensTabela extends Tabela
{
    public function __construct(
        private readonly int $requisitoId,
        private readonly string $requisitoNome,
        private readonly bool $podeAlterar,
    ) {
    }

    public function id(): string
    {
        return 'requisito-itens';
    }

    public function titulo(): string
    {
        return lang('Chamados.requiredItems');
    }

    public function urlLista(): string
    {
        return site_url("requisitos/{$this->requisitoId}/itens");
    }

    public function urlExportar(): string
    {
        return site_url("requisitos/{$this->requisitoId}/itens/exportar");
    }

    public function independente(): bool
    {
        return true;
    }

    protected function consulta(): BaseBuilder
    {
        return db_connect()->table('requisitos_disciplina_itens ri')
            ->select('ri.id_requisito_disciplina_item, ri.quantidade, ci.nome')
            ->join('categorias_itens ci', 'ci.id_categoria_item = ri.categoria_item_id')
            ->where('ri.requisito_disciplina_id', $this->requisitoId)
            ->where('ri.deletado_em', null);
    }

    protected function chavePrimaria(): string
    {
        return 'ri.id_requisito_disciplina_item';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('nome', lang('Chamados.fieldItemCategory'), 'ci.nome')->principal(),
            Coluna::nova('quantidade', lang('Chamados.fieldQuantity'), 'ri.quantidade')->numerica(),
        ];
    }

    public function temAcoes(): bool
    {
        return $this->podeAlterar;
    }

    public function acoes(array $linha): array
    {
        $item = (int) $linha['id_requisito_disciplina_item'];

        return ['excluir' => site_url("requisitos/{$this->requisitoId}/itens/{$item}")];
    }

    public function mensagemExclusao(array $linha): string
    {
        return lang('Chamados.itemRemoveText', [(string) $linha['nome'], $this->requisitoNome]);
    }

    public function tituloExclusao(): string
    {
        return lang('Chamados.itemRemoveTitle');
    }

    public function vazio(): string
    {
        return lang('Chamados.noRequiredItems');
    }
}

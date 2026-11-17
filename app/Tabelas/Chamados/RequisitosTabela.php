<?php

namespace App\Tabelas\Chamados;

use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/**
 * Requisitos de disciplina (sem campus: todo leitor do módulo consulta), com quantas categorias de item e
 * quantos softwares cada um exige. Alterar/Excluir só nos próprios, ou em todos para quem gere os de outros.
 */
final class RequisitosTabela extends Tabela
{
    public function __construct(
        private readonly bool $editor,
        private readonly bool $gereTodos,
        private readonly int $usuarioId,
    ) {
    }

    public function id(): string
    {
        return 'requisitos';
    }

    public function titulo(): string
    {
        return lang('Chamados.requirementsTitle');
    }

    public function urlLista(): string
    {
        return site_url('requisitos');
    }

    public function urlExportar(): string
    {
        return site_url('requisitos/exportar');
    }

    protected function consulta(): BaseBuilder
    {
        return db_connect()->table('requisitos_disciplina r')
            ->select('r.id_requisito_disciplina, r.professor_id, r.capacidade_minima')
            ->select('u.nome AS professor, d.sigla, d.nome AS disciplina')
            ->select(
                '(SELECT COUNT(*) FROM requisitos_disciplina_itens ri WHERE ri.requisito_disciplina_id'
                . ' = r.id_requisito_disciplina AND ri.deletado_em IS NULL) AS itens',
                false
            )
            ->select(
                '(SELECT COUNT(*) FROM requisitos_disciplina_softwares rs WHERE rs.requisito_disciplina_id'
                . ' = r.id_requisito_disciplina AND rs.deletado_em IS NULL) AS softwares',
                false
            )
            ->join('usuarios u', 'u.id_usuario = r.professor_id')
            ->join('disciplinas d', 'd.id_disciplina = r.disciplina_id')
            ->where('r.deletado_em', null);
    }

    protected function chavePrimaria(): string
    {
        return 'r.id_requisito_disciplina';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('disciplina', lang('Chamados.fieldDiscipline'), 'd.nome')
                ->principal()
                ->buscaEm('d.nome', 'd.sigla')
                ->texto(static fn (array $linha): string => $linha['sigla'] . ' · ' . $linha['disciplina'])
                ->html(static fn (array $linha, string $texto): string => '<a href="'
                    . esc(site_url('requisitos/' . $linha['id_requisito_disciplina']), 'attr')
                    . '" class="inline-flex min-h-11 items-center underline">' . esc($texto) . '</a>'),
            Coluna::nova('professor', lang('Chamados.fieldTeacher'), 'u.nome'),
            Coluna::nova('capacidade_minima', lang('Chamados.fieldMinCapacity'), 'r.capacidade_minima')->numerica(),
            Coluna::nova('itens', lang('Chamados.itemCategoriesCount'))->numerica(),
            Coluna::nova('softwares', lang('Chamados.softwaresCount'))->numerica(),
        ];
    }

    public function acoes(array $linha): array
    {
        $id     = (int) $linha['id_requisito_disciplina'];
        $altera = $this->podeAlterar($linha);

        return [
            'ver'     => site_url("requisitos/{$id}"),
            'editar'  => $altera ? site_url("requisitos/{$id}/editar") : null,
            'excluir' => $altera ? site_url("requisitos/{$id}") : null,
        ];
    }

    public function temExclusao(): bool
    {
        return $this->editor;
    }

    public function formulariosEmModal(): bool
    {
        return true;
    }

    public function mensagemExclusao(array $linha): string
    {
        return lang(
            'Chamados.requirementDeleteText',
            [$linha['sigla'] . ' · ' . $linha['disciplina'], (string) $linha['professor']]
        );
    }

    public function tituloExclusao(): string
    {
        return lang('Chamados.requirementDeleteTitle');
    }

    public function vazio(): string
    {
        return lang('Chamados.requirementsEmpty');
    }

    /** @param array<string, mixed> $linha */
    private function podeAlterar(array $linha): bool
    {
        return $this->editor && ($this->gereTodos || (int) $linha['professor_id'] === $this->usuarioId);
    }
}

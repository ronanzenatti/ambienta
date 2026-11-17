<?php

namespace App\Tabelas\Institucional;

use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/** Listagem de disciplinas, com o curso de cada uma. */
final class DisciplinasTabela extends Tabela
{
    public function __construct(private readonly bool $podeEditar, private readonly ?int $cursoId = null)
    {
    }

    public function id(): string
    {
        return 'disciplinas';
    }

    public function titulo(): string
    {
        return lang('Disciplinas.title');
    }

    public function urlLista(): string
    {
        return site_url('disciplinas');
    }

    public function urlExportar(): string
    {
        return site_url('disciplinas/exportar');
    }

    public function ordemPadrao(): array
    {
        return ['nome', 'asc'];
    }

    protected function consulta(): BaseBuilder
    {
        $builder = db_connect()->table('disciplinas')
            ->select('disciplinas.id_disciplina, disciplinas.sigla, disciplinas.nome, cursos.nome AS curso')
            ->join('cursos', 'cursos.id_curso = disciplinas.curso_id', 'left')
            ->where('disciplinas.deletado_em', null);

        if ($this->cursoId !== null) {
            $builder->where('disciplinas.curso_id', $this->cursoId);
        }

        return $builder;
    }

    public function filtros(): array
    {
        return $this->cursoId === null ? [] : ['curso' => (string) $this->cursoId];
    }

    protected function chavePrimaria(): string
    {
        return 'disciplinas.id_disciplina';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('sigla', lang('Disciplinas.fieldCode'), 'disciplinas.sigla'),
            Coluna::nova('nome', lang('Institucional.fieldName'), 'disciplinas.nome')
                ->principal()
                ->html(static fn (array $linha, string $texto): string => '<a href="'
                    . esc(site_url('disciplinas/' . $linha['id_disciplina']), 'attr')
                    . '" class="inline-flex min-h-11 items-center underline">' . esc($texto) . '</a>'),
            Coluna::nova('curso', lang('Disciplinas.colCourse'), 'cursos.nome'),
        ];
    }

    public function temExclusao(): bool
    {
        return $this->podeEditar;
    }

    public function formulariosEmModal(): bool
    {
        return true;
    }

    public function acoes(array $linha): array
    {
        $id = (int) $linha['id_disciplina'];

        return [
            'ver'     => site_url("disciplinas/{$id}"),
            'editar'  => $this->podeEditar ? site_url("disciplinas/{$id}/editar") : null,
            'excluir' => $this->podeEditar ? site_url("disciplinas/{$id}") : null,
        ];
    }

    public function mensagemExclusao(array $linha): string
    {
        return lang('Disciplinas.deleteText', [(string) $linha['nome']]);
    }

    public function tituloExclusao(): string
    {
        return lang('Disciplinas.deleteTitle');
    }

    public function vazio(): string
    {
        return lang('Disciplinas.empty');
    }
}

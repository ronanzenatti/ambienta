<?php

namespace App\Tabelas\Institucional;

use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/** Listagem de cursos (catálogo global). */
final class CursosTabela extends Tabela
{
    private const TIPOS = ['extensao', 'graduacao', 'lato_sensu', 'stricto_sensu'];

    public function __construct(private readonly bool $podeEditar)
    {
    }

    public function id(): string
    {
        return 'cursos';
    }

    public function titulo(): string
    {
        return lang('Cursos.title');
    }

    public function urlLista(): string
    {
        return site_url('cursos');
    }

    public function urlExportar(): string
    {
        return site_url('cursos/exportar');
    }

    protected function consulta(): BaseBuilder
    {
        return db_connect()->table('cursos')
            ->select('cursos.id_curso, cursos.nome, cursos.abreviacao, cursos.descricao, cursos.tipo')
            ->select('u.nome AS responsavel')
            ->join('usuarios u', 'u.id_usuario = cursos.responsavel_id')
            ->where('cursos.deletado_em', null);
    }

    protected function chavePrimaria(): string
    {
        return 'cursos.id_curso';
    }

    protected function definirColunas(): array
    {
        $tipos = [];
        foreach (self::TIPOS as $tipo) {
            $tipos[$tipo] = lang('Cursos.type_' . $tipo);
        }

        return [
            Coluna::nova('nome', lang('Institucional.fieldName'), 'cursos.nome')
                ->principal()
                ->buscaEm('cursos.nome', 'cursos.descricao')
                ->html(static function (array $linha, string $texto): string {
                    if (empty($linha['descricao'])) {
                        return esc($texto);
                    }

                    return esc($texto) . '<p class="text-caption font-normal text-ink-muted">'
                        . esc((string) $linha['descricao']) . '</p>';
                }),
            Coluna::nova('abreviacao', lang('Institucional.fieldAbbreviation'), 'cursos.abreviacao'),
            Coluna::nova('tipo', lang('Cursos.fieldType'), 'cursos.tipo')->rotulos($tipos),
            Coluna::nova('responsavel', lang('Cursos.fieldManager'), 'u.nome'),
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
        $id = (int) $linha['id_curso'];

        return ['editar' => site_url("cursos/{$id}/editar"), 'excluir' => site_url("cursos/{$id}")];
    }

    public function mensagemExclusao(array $linha): string
    {
        return lang('Cursos.deleteText', [(string) $linha['nome']]);
    }

    public function tituloExclusao(): string
    {
        return lang('Cursos.deleteTitle');
    }

    public function vazio(): string
    {
        return lang('Cursos.empty');
    }
}

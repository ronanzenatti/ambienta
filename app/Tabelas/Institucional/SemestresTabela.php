<?php

namespace App\Tabelas\Institucional;

use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/** Listagem de semestres letivos, do mais recente ao mais antigo. */
final class SemestresTabela extends Tabela
{
    private const TONS = ['planejado' => 'pending', 'aberto' => 'confirmed', 'encerrado' => 'neutral'];

    public function __construct(private readonly bool $podeEditar)
    {
    }

    public function id(): string
    {
        return 'semestres';
    }

    public function titulo(): string
    {
        return lang('Semestres.title');
    }

    public function urlLista(): string
    {
        return site_url('semestres');
    }

    public function urlExportar(): string
    {
        return site_url('semestres/exportar');
    }

    public function ordemPadrao(): array
    {
        return ['data_inicio', 'desc'];
    }

    protected function consulta(): BaseBuilder
    {
        return db_connect()->table('semestres')
            ->select('id_semestre, nome, data_inicio, data_fim, status')
            ->where('semestres.deletado_em', null);
    }

    protected function chavePrimaria(): string
    {
        return 'semestres.id_semestre';
    }

    protected function definirColunas(): array
    {
        $status = [];
        foreach (array_keys(self::TONS) as $valor) {
            $status[$valor] = lang('Semestres.status_' . $valor);
        }

        return [
            Coluna::nova('nome', lang('Institucional.fieldName'), 'semestres.nome')->principal(),
            Coluna::nova('data_inicio', lang('Semestres.fieldStart'), 'semestres.data_inicio')->data(),
            Coluna::nova('data_fim', lang('Semestres.fieldEnd'), 'semestres.data_fim')->data(),
            Coluna::nova('status', lang('Ui.status'), 'semestres.status')
                ->rotulos($status)
                ->html(static fn (array $linha, string $texto): string => ui_status_dot(
                    self::TONS[$linha['status']] ?? 'neutral',
                    $texto
                )),
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
        $id = (int) $linha['id_semestre'];

        return ['editar' => site_url("semestres/{$id}/editar"), 'excluir' => site_url("semestres/{$id}")];
    }

    public function mensagemExclusao(array $linha): string
    {
        return lang('Semestres.deleteText', [(string) $linha['nome']]);
    }

    public function tituloExclusao(): string
    {
        return lang('Semestres.deleteTitle');
    }

    public function vazio(): string
    {
        return lang('Semestres.empty');
    }
}

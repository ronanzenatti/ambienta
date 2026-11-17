<?php

namespace App\Tabelas\Agendamento;

use App\Libraries\Agendamento\Feriados;
use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use Closure;
use CodeIgniter\Database\BaseBuilder;

/**
 * Feriados: os gerais (API ou manuais, para todos os campi) e os dos campi que o usuário enxerga.
 * Cadastro e alteração em modal; alterar/excluir onde ele pode ($podeGerir: recebe o campus, null = geral).
 */
final class FeriadosTabela extends Tabela
{
    /**
     * @param list<int>|null           $campi     campi visíveis (null = todos)
     * @param Closure(?int): bool      $podeGerir
     */
    public function __construct(private readonly ?array $campi, private readonly Closure $podeGerir)
    {
    }

    public function id(): string
    {
        return 'feriados';
    }

    public function titulo(): string
    {
        return lang('Agendamento.holidaysTitle');
    }

    public function urlLista(): string
    {
        return site_url('agendamento/feriados');
    }

    public function urlExportar(): string
    {
        return site_url('agendamento/feriados/exportar');
    }

    public function ordemPadrao(): array
    {
        return ['data_feriado', 'desc'];
    }

    protected function consulta(): BaseBuilder
    {
        $builder = db_connect()->table('feriados f')
            ->select('f.id_feriado, f.campus_id, f.data_feriado, f.descricao, f.abrangencia, f.origem')
            ->select(
                "COALESCE(NULLIF(TRIM(c.abreviacao), ''), c.nome, "
                . db_connect()->escape(lang('Agendamento.allCampuses')) . ') AS campus',
                false
            )
            ->join('campus c', 'c.id_campus = f.campus_id', 'left')
            ->where('f.deletado_em', null);

        if ($this->campi !== null) {
            $builder->groupStart()
                ->where('f.campus_id', null)
                ->orWhereIn('f.campus_id', $this->campi === [] ? [0] : $this->campi)
                ->groupEnd();
        }

        return $builder;
    }

    protected function chavePrimaria(): string
    {
        return 'f.id_feriado';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('data_feriado', lang('Agendamento.fieldDate'), 'f.data_feriado')->data(),
            Coluna::nova('descricao', lang('Agendamento.fieldDescription'), 'f.descricao')->principal(),
            Coluna::nova('abrangencia', lang('Agendamento.fieldScope'), 'f.abrangencia')
                ->rotulos(opcoes_traduzidas('Agendamento.scope_', Feriados::ABRANGENCIAS)),
            Coluna::nova('campus', lang('Agendamento.fieldCampus'), "COALESCE(NULLIF(TRIM(c.abreviacao), ''), c.nome)"),
            Coluna::nova('origem', lang('Agendamento.fieldOrigin'), 'f.origem')
                ->rotulos(opcoes_traduzidas('Agendamento.origin_', ['api', 'manual'])),
        ];
    }

    public function formulariosEmModal(): bool
    {
        return true;
    }

    public function acoes(array $linha): array
    {
        $id   = (int) $linha['id_feriado'];
        $gere = ($this->podeGerir)($linha['campus_id'] === null ? null : (int) $linha['campus_id']);

        return [
            'editar'  => $gere ? site_url("agendamento/feriados/{$id}/editar") : null,
            'excluir' => $gere ? site_url("agendamento/feriados/{$id}") : null,
        ];
    }

    public function mensagemExclusao(array $linha): string
    {
        return lang('Agendamento.holidayDeleteText', [(string) $linha['descricao']]);
    }

    public function tituloExclusao(): string
    {
        return lang('Agendamento.holidayDeleteTitle');
    }

    public function vazio(): string
    {
        return lang('Agendamento.holidaysEmpty');
    }
}

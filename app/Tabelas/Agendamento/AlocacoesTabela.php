<?php

namespace App\Tabelas\Agendamento;

use App\Libraries\Agendamento\Alocacoes;
use App\Libraries\Patrimonio\Locais;
use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use Closure;
use CodeIgniter\Database\BaseBuilder;

/**
 * Alocações semestrais dos ambientes no escopo de leitura do usuário, mais as em que ele é o professor.
 * Excluir só aparece onde o usuário é editor de agendamento no ambiente ($podeExcluir).
 */
final class AlocacoesTabela extends Tabela
{
    private const DISCIPLINA = "CONCAT(d.sigla, ' - ', d.nome)";

    /**
     * @param array{global: bool, campus: list<int>, predio: list<int>} $escopo
     * @param Closure(int): bool                                        $podeExcluir recebe o ambiente
     */
    public function __construct(
        private readonly array $escopo,
        private readonly int $usuarioId,
        private readonly Closure $podeExcluir
    ) {
    }

    public static function tom(string $status): string
    {
        return match ($status) {
            'ativa'     => 'confirmed',
            'cancelada' => 'critical',
            default     => 'pending',
        };
    }

    public function id(): string
    {
        return 'alocacoes';
    }

    public function titulo(): string
    {
        return lang('Agendamento.allocationsTitle');
    }

    public function urlLista(): string
    {
        return site_url('agendamento/alocacoes');
    }

    public function urlExportar(): string
    {
        return site_url('agendamento/alocacoes/exportar');
    }

    public function ordemPadrao(): array
    {
        return ['semestre', 'desc'];
    }

    protected function consulta(): BaseBuilder
    {
        $builder = db_connect()->table('alocacoes_semestrais a')
            ->select('a.id_alocacao_semestral, a.ambiente_id, a.dia_semana, a.hora_inicio, a.hora_fim, a.status,
                      s.nome AS semestre, c.nome AS curso, d.sigla, d.nome AS disciplina, u.nome AS professor')
            ->select(self::DISCIPLINA . ' AS disciplina_rotulo', false)
            ->select(Locais::rotuloSql('l') . ' AS ambiente', false)
            ->join('semestres s', 's.id_semestre = a.semestre_id')
            ->join('disciplinas d', 'd.id_disciplina = a.disciplina_id')
            ->join('cursos c', 'c.id_curso = d.curso_id')
            ->join('usuarios u', 'u.id_usuario = a.professor_id')
            ->where('a.deletado_em', null);

        Locais::juntar($builder, 'l', 'a.ambiente_id', 'NULL');

        $escopo = Locais::condicaoEscopo($this->escopo, 'l');

        if ($escopo !== null) {
            $builder->where('(' . $escopo . ' OR a.professor_id = ' . $this->usuarioId . ')', null, false);
        }

        return $builder;
    }

    protected function chavePrimaria(): string
    {
        return 'a.id_alocacao_semestral';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('disciplina_rotulo', lang('Agendamento.fieldDiscipline'), self::DISCIPLINA)
                ->principal()
                ->html(static fn (array $linha, string $texto): string => '<a href="'
                    . esc(site_url('agendamento/alocacoes/' . $linha['id_alocacao_semestral']), 'attr')
                    . '" class="inline-flex min-h-11 items-center underline">' . esc($texto) . '</a>'),
            Coluna::nova('semestre', lang('Agendamento.fieldSemester'), 's.data_inicio')
                ->buscaEm('s.nome')
                ->texto(static fn (array $linha): string => (string) $linha['semestre']),
            Coluna::nova('curso', lang('Agendamento.fieldCourse'), 'c.nome'),
            Coluna::nova('professor', lang('Agendamento.fieldTeacher'), 'u.nome'),
            Coluna::nova('ambiente', lang('Agendamento.fieldEnvironment'), Locais::rotuloSql('l')),
            Coluna::nova('dia_semana', lang('Agendamento.fieldWeekday'), 'a.dia_semana')
                ->rotulos(opcoes_traduzidas('Agendamento.weekday_', Alocacoes::DIAS)),
            Coluna::nova('horario', lang('Agendamento.fieldTime'), 'a.hora_inicio')
                ->numerica()
                ->semBusca()
                ->texto(static fn (array $linha): string => substr((string) $linha['hora_inicio'], 0, 5)
                    . '–' . substr((string) $linha['hora_fim'], 0, 5)),
            Coluna::nova('status', lang('Ui.status'), 'a.status')
                ->rotulos(opcoes_traduzidas('Agendamento.allocationStatus_', Alocacoes::STATUS))
                ->html(static fn (array $linha, string $texto): string => ui_status_dot(
                    self::tom((string) $linha['status']),
                    $texto
                )),
        ];
    }

    public function acoes(array $linha): array
    {
        $id  = (int) $linha['id_alocacao_semestral'];
        $url = site_url("agendamento/alocacoes/{$id}");

        return ['ver' => $url, 'excluir' => ($this->podeExcluir)((int) $linha['ambiente_id']) ? $url : null];
    }

    public function mensagemExclusao(array $linha): string
    {
        return lang('Agendamento.allocationDeleteText', [(string) $linha['disciplina_rotulo']]);
    }

    public function tituloExclusao(): string
    {
        return lang('Agendamento.allocationDeleteTitle');
    }

    public function vazio(): string
    {
        return lang('Agendamento.allocationsEmpty');
    }
}

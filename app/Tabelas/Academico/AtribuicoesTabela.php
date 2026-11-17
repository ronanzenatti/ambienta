<?php

namespace App\Tabelas\Academico;

use App\Libraries\Academico\GestaoAtribuicoes;
use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/**
 * Atribuições de aulas por semestre. Quem vê todas (academico.atribuicoes) vê o conjunto inteiro; os demais veem as
 * próprias e as dos cursos de que são responsáveis. Excluir: quem gere o curso da disciplina. O professor da linha
 * abre daqui os requisitos permanentes da disciplina (Ver), se tiver academico.requisitos como editor.
 */
final class AtribuicoesTabela extends Tabela
{
    private const REQUISITO = 'EXISTS (SELECT 1 FROM requisitos_disciplina rd WHERE rd.professor_id = pd.professor_id
        AND rd.disciplina_id = pd.disciplina_id AND rd.deletado_em IS NULL)';

    public function __construct(
        private readonly GestaoAtribuicoes $gestao,
        private readonly int $usuarioId,
        private readonly bool $editaRequisitos
    ) {
    }

    public function id(): string
    {
        return 'atribuicoes';
    }

    public function titulo(): string
    {
        return lang('Agendamento.linksTitle');
    }

    public function urlLista(): string
    {
        return site_url('atribuicoes');
    }

    public function urlExportar(): string
    {
        return site_url('atribuicoes/exportar');
    }

    public function ordemPadrao(): array
    {
        return ['semestre', 'desc'];
    }

    protected function consulta(): BaseBuilder
    {
        $builder = db_connect()->table('professores_disciplinas pd')
            ->select('pd.id_professor_disciplina, pd.professor_id, pd.disciplina_id, d.curso_id')
            ->select('s.nome AS semestre, d.sigla, d.nome AS disciplina, c.nome AS curso, u.nome AS professor')
            ->select(self::REQUISITO . ' AS cadastrado', false)
            ->join('semestres s', 's.id_semestre = pd.semestre_id')
            ->join('disciplinas d', 'd.id_disciplina = pd.disciplina_id')
            ->join('cursos c', 'c.id_curso = d.curso_id')
            ->join('usuarios u', 'u.id_usuario = pd.professor_id')
            ->where('pd.deletado_em', null);

        if (! $this->gestao->veTodas()) {
            $builder->groupStart()
                ->where('pd.professor_id', $this->usuarioId)
                ->orWhere('c.responsavel_id', $this->usuarioId)
                ->groupEnd();
        }

        return $builder;
    }

    protected function chavePrimaria(): string
    {
        return 'pd.id_professor_disciplina';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('semestre', lang('Agendamento.fieldSemester'), 's.data_inicio')
                ->buscaEm('s.nome')
                ->texto(static fn (array $linha): string => (string) $linha['semestre']),
            Coluna::nova('disciplina', lang('Agendamento.fieldDiscipline'), 'd.nome')
                ->principal()
                ->buscaEm('d.nome', 'd.sigla')
                ->texto(static fn (array $linha): string => $linha['sigla'] . ' · ' . $linha['disciplina']),
            Coluna::nova('curso', lang('Agendamento.fieldCourse'), 'c.nome'),
            Coluna::nova('professor', lang('Agendamento.fieldTeacher'), 'u.nome'),
            Coluna::nova('cadastrado', lang('Chamados.myDisciplineRequirements'), self::REQUISITO)
                ->semBusca()
                ->html(static function (array $linha): string {
                    $cadastrado = (bool) $linha['cadastrado'];

                    return ui_status_dot(
                        $cadastrado ? 'confirmed' : 'pending',
                        lang($cadastrado ? 'Chamados.myDisciplineRegistered' : 'Chamados.myDisciplineNotRegistered')
                    );
                }),
        ];
    }

    public function formulariosEmModal(): bool
    {
        return true;
    }

    public function temExclusao(): bool
    {
        return $this->gestao->gereAlgum();
    }

    public function acoes(array $linha): array
    {
        $id      = (int) $linha['id_professor_disciplina'];
        $propria = (int) $linha['professor_id'] === $this->usuarioId;
        $acoes   = [];

        if ($propria && $this->editaRequisitos) {
            $acoes['ver'] = site_url('atribuicoes/' . $linha['disciplina_id'] . '/requisitos');
        }

        $acoes['excluir'] = $this->gestao->gereCurso((int) $linha['curso_id']) ? site_url("atribuicoes/{$id}") : null;

        return $acoes;
    }

    public function mensagemExclusao(array $linha): string
    {
        return lang('Agendamento.linkDeleteText', [(string) $linha['professor'], (string) $linha['disciplina']]);
    }

    public function tituloExclusao(): string
    {
        return lang('Agendamento.linkDeleteTitle');
    }

    public function vazio(): string
    {
        return lang('Agendamento.linksEmpty');
    }
}

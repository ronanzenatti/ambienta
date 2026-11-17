<?php

namespace App\Libraries\Chamados;

use CodeIgniter\Database\BaseConnection;

/**
 * Atendimento de um ambiente aos requisitos permanentes de uma disciplina (do professor): integral (nada
 * falta, ou não há requisito), parcial (falta parte) ou não (falta tudo). Software pendente ou reprovado não
 * entra na conta. Só sinaliza: quem agenda vê o selo e decide.
 *
 * @phpstan-import-type Pendencia from ValidadorRequisitos
 */
final class Atendimento
{
    public const INTEGRAL = 'integral';
    public const PARCIAL  = 'parcial';
    public const NAO      = 'nao';

    public const NIVEIS = [self::INTEGRAL, self::PARCIAL, self::NAO];

    private readonly ValidadorRequisitos $validador;

    public function __construct(private readonly BaseConnection $db)
    {
        $this->validador = new ValidadorRequisitos($db);
    }

    /** Tom do selo (ui_status_dot) de cada nível. */
    public static function tom(string $nivel): string
    {
        return match ($nivel) {
            self::INTEGRAL => 'confirmed',
            self::PARCIAL  => 'pending',
            default        => 'critical',
        };
    }

    /** Texto do selo no idioma da tela. */
    public static function rotulo(string $nivel): string
    {
        return lang('Chamados.fulfilment_' . $nivel);
    }

    /**
     * Professor cujos requisitos valem para a disciplina na data: o atribuído a ela (atribuição de aulas) no
     * semestre que contém a data, ou, sem semestre para a data, na atribuição mais recente. Se $preferido
     * (beneficiário ou solicitante) está entre os atribuídos, é ele; senão o primeiro atribuído. Sem nenhuma
     * atribuição, $preferido (comportamento anterior).
     *
     * @param string $data Y-m-d (ou Y-m-d H:i:s)
     */
    public function professorDaDisciplina(int $disciplinaId, string $data, int $preferido): int
    {
        $data = substr($data, 0, 10);

        $base = fn () => $this->db->table('professores_disciplinas pd')
            ->select('pd.professor_id')
            ->join('semestres s', 's.id_semestre = pd.semestre_id AND s.deletado_em IS NULL')
            ->join('usuarios u', 'u.id_usuario = pd.professor_id AND u.deletado_em IS NULL')
            ->where('pd.disciplina_id', $disciplinaId)
            ->where('pd.deletado_em', null);

        $atribuidos = array_map('intval', array_column(
            $base()->where('s.data_inicio <=', $data)->where('s.data_fim >=', $data)
                ->orderBy('pd.id_professor_disciplina')->get()->getResultArray(),
            'professor_id'
        ));

        if ($atribuidos === []) {
            $ultimo = $base()->orderBy('s.data_inicio', 'DESC')->orderBy('pd.id_professor_disciplina')
                ->get(1)->getRowArray();

            return $ultimo === null ? $preferido : (int) $ultimo['professor_id'];
        }

        return in_array($preferido, $atribuidos, true) ? $preferido : $atribuidos[0];
    }

    /**
     * @return array{nivel: string, pendencias: list<Pendencia>}
     */
    public function avaliar(int $professorId, int $disciplinaId, int $ambienteId): array
    {
        $requisito = $this->db->table('requisitos_disciplina')
            ->select('id_requisito_disciplina')
            ->where('professor_id', $professorId)
            ->where('disciplina_id', $disciplinaId)
            ->where('deletado_em', null)
            ->get()
            ->getRowArray();

        if ($requisito === null) {
            return ['nivel' => self::INTEGRAL, 'pendencias' => []];
        }

        $requisitoId = (int) $requisito['id_requisito_disciplina'];
        $pendencias  = $this->validador->verificarRequisito($requisitoId, $ambienteId);
        $total       = $this->validador->totalExigencias($requisitoId);

        return ['nivel' => self::nivelDe(count($pendencias), $total), 'pendencias' => $pendencias];
    }

    /**
     * Nível de cada ambiente.
     *
     * @param list<int> $ambienteIds
     *
     * @return array<int, string> ambiente => nível
     */
    public function niveis(int $professorId, int $disciplinaId, array $ambienteIds): array
    {
        $niveis = [];

        foreach ($ambienteIds as $ambienteId) {
            $niveis[$ambienteId] = $this->avaliar($professorId, $disciplinaId, $ambienteId)['nivel'];
        }

        return $niveis;
    }

    private static function nivelDe(int $faltando, int $total): string
    {
        if ($faltando === 0) {
            return self::INTEGRAL;
        }

        return $faltando >= $total ? self::NAO : self::PARCIAL;
    }
}

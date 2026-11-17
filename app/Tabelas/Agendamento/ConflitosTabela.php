<?php

namespace App\Tabelas\Agendamento;

use App\Libraries\Patrimonio\Locais;
use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/**
 * Conflitos reserva × alocação semestral ainda sem decisão, nos ambientes onde o usuário pode autorizar
 * (agendamento.autorizar_conflito). A decisão é tomada na agenda da semana ou na página da reserva.
 */
final class ConflitosTabela extends Tabela
{
    private const ALOCACAO = "CONCAT(d.sigla, ' - ', d.nome, ' (', u.nome, ')')";

    /** @param array{global: bool, campus: list<int>, predio: list<int>} $escopo */
    public function __construct(private readonly array $escopo)
    {
    }

    public function id(): string
    {
        return 'conflitos';
    }

    public function titulo(): string
    {
        return lang('Agendamento.conflictsTitle');
    }

    public function urlLista(): string
    {
        return site_url('agendamento/conflitos');
    }

    public function urlExportar(): string
    {
        return site_url('agendamento/conflitos/exportar');
    }

    public function ordemPadrao(): array
    {
        return ['data_hora_inicio', 'asc'];
    }

    protected function consulta(): BaseBuilder
    {
        $builder = db_connect()->table('conflitos_agendamento c')
            ->select('c.id_conflito_agendamento, r.id_reserva_pontual, r.ambiente_id, r.titulo, r.data_hora_inicio,
                      r.data_hora_fim, s.nome AS solicitante')
            ->select(self::ALOCACAO . ' AS alocacao', false)
            ->select(Locais::rotuloSql('l') . ' AS ambiente', false)
            ->join('reservas_pontuais r', 'r.id_reserva_pontual = c.reserva_pontual_id')
            ->join('usuarios s', 's.id_usuario = r.solicitante_id')
            ->join('alocacoes_semestrais a', 'a.id_alocacao_semestral = c.alocacao_semestral_id')
            ->join('disciplinas d', 'd.id_disciplina = a.disciplina_id')
            ->join('usuarios u', 'u.id_usuario = a.professor_id')
            ->where('c.deletado_em', null)
            ->where('c.decisao', 'pendente')
            ->where('r.deletado_em', null)
            ->where('r.status', 'pendente_autorizacao_conflito');

        Locais::juntar($builder, 'l', 'r.ambiente_id', 'NULL');

        $escopo = Locais::condicaoEscopo($this->escopo, 'l');

        if ($escopo !== null) {
            $builder->where($escopo, null, false);
        }

        return $builder;
    }

    protected function chavePrimaria(): string
    {
        return 'c.id_conflito_agendamento';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('titulo', lang('Agendamento.fieldReservation'), 'r.titulo')->principal(),
            Coluna::nova('ambiente', lang('Agendamento.fieldEnvironment'), Locais::rotuloSql('l')),
            Coluna::nova('data_hora_inicio', lang('Agendamento.fieldStart'), 'r.data_hora_inicio')->dataHora(),
            Coluna::nova('data_hora_fim', lang('Agendamento.fieldEnd'), 'r.data_hora_fim')->dataHora(),
            Coluna::nova('alocacao', lang('Agendamento.fieldAllocation'), self::ALOCACAO),
            Coluna::nova('solicitante', lang('Agendamento.fieldRequester'), 's.nome'),
            Coluna::nova('situacao', lang('Ui.status'))
                ->texto(static fn (): string => lang('Agendamento.conflictPending'))
                ->html(static fn (array $linha, string $texto): string => ui_status_dot('pending', $texto)),
        ];
    }

    public function acoes(array $linha): array
    {
        return ['ver' => site_url('agendamento/reservas/' . $linha['id_reserva_pontual'])];
    }

    public function temExclusao(): bool
    {
        return false;
    }

    public function vazio(): string
    {
        return lang('Agendamento.conflictsEmpty');
    }
}

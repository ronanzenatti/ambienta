<?php

namespace App\Tabelas\Relatorios;

use App\Libraries\Patrimonio\Locais;
use App\Libraries\Tabela\Coluna;
use CodeIgniter\Database\BaseBuilder;

/**
 * Reservas pontuais canceladas no período (pela data do cancelamento), com a justificativa e quem cancelou,
 * inclusive os cancelamentos automáticos da fila de confirmação (justificativa cita quem confirmou a outra).
 */
final class CancelamentosTabela extends RelatorioTabela
{
    private const DATA_SQL = 'COALESCE(r.data_cancelamento, r.alterado_em)';

    public function slug(): string
    {
        return 'cancelamentos';
    }

    public function ordemPadrao(): array
    {
        return ['data_cancelamento', 'desc'];
    }

    protected function consulta(): BaseBuilder
    {
        return $this->base()
            ->select('r.id_reserva_pontual, r.titulo, r.data_hora_inicio, r.justificativa_cancelamento')
            ->select(self::DATA_SQL . ' AS data_cancelamento', false)
            ->select('sol.nome AS solicitante, can.nome AS cancelado_por')
            ->select(Locais::rotuloSql('l') . ' AS ambiente', false);
    }

    private function base(): BaseBuilder
    {
        $builder = db_connect()->table('reservas_pontuais r')
            ->join('usuarios sol', 'sol.id_usuario = r.solicitante_id')
            ->join('usuarios can', 'can.id_usuario = r.cancelado_por_id', 'left')
            ->where('r.deletado_em', null)
            ->where('r.status', 'cancelada')
            ->where($this->filtrosRelatorio->condicaoPeriodo(self::DATA_SQL), null, false);

        Locais::juntar($builder, 'l', 'r.ambiente_id', 'NULL');

        $condicao = $this->filtrosRelatorio->condicaoLocal('l_ca.id_campus', 'l_amb.predio_id');

        if ($condicao !== null) {
            $builder->where($condicao, null, false);
        }

        return $builder;
    }

    protected function chavePrimaria(): string
    {
        return 'r.id_reserva_pontual';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('titulo', lang('Relatorios.fieldReservation'), 'r.titulo')->principal(),
            Coluna::nova('ambiente', lang('Relatorios.fieldEnvironment'), Locais::rotuloSql('l')),
            Coluna::nova('data_hora_inicio', lang('Relatorios.fieldReservedFor'), 'r.data_hora_inicio')->dataHora(),
            Coluna::nova('solicitante', lang('Relatorios.fieldRequester'), 'sol.nome'),
            Coluna::nova('cancelado_por', lang('Relatorios.fieldCancelledBy'), 'can.nome'),
            Coluna::nova('data_cancelamento', lang('Relatorios.fieldCancelledAt'), self::DATA_SQL)->dataHora(),
            Coluna::nova(
                'justificativa_cancelamento',
                lang('Relatorios.fieldJustification'),
                'r.justificativa_cancelamento'
            )->semOrdem(),
        ];
    }

    protected function calcularResumo(): array
    {
        $total = $this->base()->countAllResults();

        return [[lang('Relatorios.fieldTotal'), ui_numero($total)]];
    }
}

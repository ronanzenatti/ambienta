<?php

namespace App\Controllers\Agendamento;

use App\Libraries\Agendamento\Agenda as AgendaServico;
use App\Libraries\Autorizacao;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;

/**
 * Agenda da semana de um ambiente: alocações, reservas, bloqueios e feriados, dia a dia. Os conflitos
 * reserva × alocação aparecem marcados como pendentes, com Autorizar/Negar para quem pode decidir.
 */
class Agenda extends ControladorAgendamento
{
    public function index(): ResponseInterface|string
    {
        $ambienteId = (int) $this->request->getGet('ambiente');
        $ambientes  = [];

        if ($ambienteId !== 0) {
            $nome = service('opcoes')->ambientes($this->escopo())->rotuloDe($ambienteId);

            if ($nome === null) {
                return $this->negar();
            }

            $ambientes[$ambienteId] = $nome;
        }

        $data    = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $this->request->getGet('semana'))
            ?: new DateTimeImmutable('today');
        $segunda = AgendaServico::segunda($data);

        return view('agendamento/agenda/index', [
            'ambienteId'  => $ambienteId,
            'ambientes'   => $ambientes,
            'segunda'     => $segunda,
            'dias'        => $ambienteId === 0 ? [] : (new AgendaServico(db_connect()))->semana($ambienteId, $segunda),
            'podeDecidir'  => $ambienteId !== 0
                && $this->podeNoAmbiente($ambienteId, Autorizacao::EDITOR, self::AUTORIZAR),
            'podeReservar' => $ambienteId !== 0 && $this->podeNoAmbiente($ambienteId, Autorizacao::EDITOR),
        ]);
    }
}

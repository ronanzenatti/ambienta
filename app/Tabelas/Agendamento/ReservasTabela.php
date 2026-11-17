<?php

namespace App\Tabelas\Agendamento;

use App\Libraries\Agendamento\Reservas;
use App\Libraries\Patrimonio\Locais;
use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/**
 * Reservas pontuais. Duas listagens com a mesma forma:
 * - 'todas': as dos ambientes no escopo de leitura do usuário, mais as que ele fez ou que são para ele;
 * - 'fila': as pendentes de confirmação nos ambientes onde ele pode confirmar (agendamento.confirmar_reserva),
 *   com o botão Confirmar na linha.
 * Sem exclusão: o cancelamento (com justificativa) cumpre esse papel e preserva o histórico.
 */
final class ReservasTabela extends Tabela
{
    public const TODAS = 'todas';
    public const FILA  = 'fila';

    /** @param array{global: bool, campus: list<int>, predio: list<int>} $escopo */
    public function __construct(
        private readonly array $escopo,
        private readonly int $usuarioId,
        private readonly string $modo = self::TODAS
    ) {
    }

    public static function tom(string $status): string
    {
        return match ($status) {
            'confirmada' => 'confirmed',
            'cancelada'  => 'critical',
            default      => 'pending',
        };
    }

    public function id(): string
    {
        return $this->modo === self::FILA ? 'reservas-fila' : 'reservas';
    }

    public function titulo(): string
    {
        return lang($this->modo === self::FILA ? 'Agendamento.queueTitle' : 'Agendamento.reservationsTitle');
    }

    public function urlLista(): string
    {
        return site_url($this->modo === self::FILA ? 'agendamento/confirmacoes' : 'agendamento/reservas');
    }

    public function urlExportar(): string
    {
        return $this->urlLista() . '/exportar';
    }

    public function ordemPadrao(): array
    {
        return ['data_hora_inicio', $this->modo === self::FILA ? 'asc' : 'desc'];
    }

    protected function consulta(): BaseBuilder
    {
        $builder = db_connect()->table('reservas_pontuais r')
            ->select('r.id_reserva_pontual, r.titulo, r.data_hora_inicio, r.data_hora_fim, r.status, r.recorrencia_id,
                      r.solicitante_id, r.beneficiario_id, r.criado_em')
            ->select('COALESCE(b.nome, s.nome) AS pessoa', false)
            ->select(Locais::rotuloSql('l') . ' AS ambiente', false)
            ->join('usuarios s', 's.id_usuario = r.solicitante_id')
            ->join('usuarios b', 'b.id_usuario = r.beneficiario_id', 'left')
            ->where('r.deletado_em', null);

        Locais::juntar($builder, 'l', 'r.ambiente_id', 'NULL');

        $escopo = Locais::condicaoEscopo($this->escopo, 'l');

        if ($this->modo === self::FILA) {
            $builder->where('r.status', 'pendente_confirmacao');

            if ($escopo !== null) {
                $builder->where($escopo, null, false);
            }

            return $builder;
        }

        if ($escopo !== null) {
            $proprias = 'r.solicitante_id = ' . $this->usuarioId . ' OR r.beneficiario_id = ' . $this->usuarioId;
            $builder->where('(' . $escopo . ' OR ' . $proprias . ')', null, false);
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
            Coluna::nova('titulo', lang('Agendamento.fieldTitle'), 'r.titulo')
                ->principal()
                ->html(static fn (array $linha, string $texto): string => '<a href="'
                    . esc(site_url('agendamento/reservas/' . $linha['id_reserva_pontual']), 'attr')
                    . '" class="inline-flex min-h-11 items-center underline">' . esc($texto) . '</a>'
                    . ($linha['recorrencia_id'] !== null ? ' <span class="text-caption text-ink-muted">('
                        . esc(lang('Agendamento.series')) . ')</span>' : '')),
            Coluna::nova('ambiente', lang('Agendamento.fieldEnvironment'), Locais::rotuloSql('l')),
            Coluna::nova('data_hora_inicio', lang('Agendamento.fieldStart'), 'r.data_hora_inicio')->dataHora(),
            Coluna::nova('data_hora_fim', lang('Agendamento.fieldEnd'), 'r.data_hora_fim')->dataHora(),
            Coluna::nova('pessoa', lang('Agendamento.fieldFor'), 'COALESCE(b.nome, s.nome)'),
            Coluna::nova('status', lang('Ui.status'), 'r.status')
                ->rotulos(opcoes_traduzidas('Agendamento.reservationStatus_', Reservas::STATUS))
                ->html(static fn (array $linha, string $texto): string => ui_status_dot(
                    self::tom((string) $linha['status']),
                    $texto
                )),
        ];
    }

    public function acoes(array $linha): array
    {
        $id    = (int) $linha['id_reserva_pontual'];
        $acoes = ['ver' => site_url("agendamento/reservas/{$id}")];

        if ($this->modo === self::FILA) {
            $rotulo         = lang('Agendamento.confirm');
            $acao           = site_url("agendamento/reservas/{$id}/confirmar");
            $acoes['extra'] = '<form method="post" action="' . esc($acao, 'attr') . '">'
                . csrf_field()
                . '<input type="hidden" name="voltar" value="/agendamento/confirmacoes">'
                . ui_icon_button('check', $rotulo, $rotulo . ': ' . $linha['titulo'], 'primary', ['type' => 'submit'])
                . '</form>';
        }

        return $acoes;
    }

    public function temExclusao(): bool
    {
        return false;
    }

    public function vazio(): string
    {
        return lang($this->modo === self::FILA ? 'Agendamento.queueEmpty' : 'Agendamento.reservationsEmpty');
    }
}

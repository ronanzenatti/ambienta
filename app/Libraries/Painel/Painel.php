<?php

namespace App\Libraries\Painel;

use App\Libraries\Agendamento\Alocacoes;
use App\Libraries\Autorizacao;
use App\Libraries\Patrimonio\LembreteAtrasos;
use App\Libraries\Patrimonio\Locais;
use App\Libraries\Tabela\Abrangencia;
use App\Tabelas\Agendamento\ConflitosTabela;
use App\Tabelas\Agendamento\ReservasTabela;
use App\Tabelas\Patrimonio\ItensSemNumeroTabela;
use App\Tabelas\Patrimonio\MovimentacoesTabela;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Página inicial de quem está logado: o que espera uma ação da pessoa, a agenda dela nos próximos dias e o
 * andamento do que ela pediu. Tudo sai do que ela já pode ver nas telas: cada pendência respeita a permissão e o
 * escopo da fila correspondente (as contagens das filas vêm das próprias listagens, para o número do cartão ser
 * sempre o da tela que ele abre).
 *
 * @phpstan-type Pendencia array{chave: string, total: int, url: string, atrasadas: int}
 * @phpstan-type Evento array{
 *     tipo: string, inicio: string, fim: string, titulo: string, detalhe: string, local: string, url: ?string,
 *     status: ?string, tom: ?string
 * }
 * @phpstan-type Dia array{data: DateTimeImmutable, feriado: ?string, eventos: list<Evento>}
 * @phpstan-type Linha array{titulo: string, detalhe: string, status: string, tom: string, url: ?string}
 * @phpstan-type Grupo array{chave: string, total: int, url: ?string, linhas: list<Linha>}
 */
final class Painel
{
    public const DIAS_AGENDA = 7;

    /** Linhas de cada grupo de "minhas solicitações"; o restante fica no link para a listagem. */
    private const LIMITE = 5;

    private DateTimeImmutable $agora;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Autorizacao $autorizacao,
        private readonly int $usuarioId,
        ?DateTimeImmutable $agora = null,
    ) {
        $this->agora = $agora ?? new DateTimeImmutable('now', new DateTimeZone(app_timezone()));
    }

    public function hoje(): DateTimeImmutable
    {
        return $this->agora->setTime(0, 0);
    }

    /**
     * Semestre letivo em andamento (aberto e com a data de hoje dentro do período).
     *
     * @return array{nome: string, data_fim: string}|null
     */
    public function semestre(): ?array
    {
        $hoje  = $this->hoje()->format('Y-m-d');
        $linha = $this->db->query(
            "SELECT nome, data_fim FROM semestres
             WHERE deletado_em IS NULL AND status = 'aberto' AND data_inicio <= ? AND data_fim >= ?
             ORDER BY data_inicio DESC LIMIT 1",
            [$hoje, $hoje]
        )->getRowArray();

        return $linha === null ? null : ['nome' => (string) $linha['nome'], 'data_fim' => (string) $linha['data_fim']];
    }

    /**
     * Atalhos para o que a pessoa mais faz, conforme as permissões dela.
     *
     * @return list<array{rotulo: string, url: string}>
     */
    public function atalhos(): array
    {
        $atalhos = [];

        if ($this->autorizacao->pode('agendamento.acesso')) {
            $atalhos['Agendamento.reservationNew'] = 'agendamento/reservas/novo';
            $atalhos['Agendamento.navAgenda']      = 'agendamento/agenda';
        }

        if ($this->autorizacao->pode('chamados.acesso', Autorizacao::EDITOR)) {
            $atalhos['Chamados.ticketNew'] = 'chamados/novo';
        }

        $atalhos['Ui.navConsulta'] = 'consulta';

        return array_map(
            static fn (string $texto, string $rota): array => ['rotulo' => lang($texto), 'url' => site_url($rota)],
            array_keys($atalhos),
            $atalhos
        );
    }

    /**
     * O que espera uma ação da pessoa, só o que tem ao menos um registro.
     *
     * @return list<Pendencia>
     */
    public function pendencias(): array
    {
        $pendencias = [];
        $incluir    = static function (
            string $chave,
            int $total,
            string $rota,
            int $atrasadas = 0
        ) use (&$pendencias): void {
            if ($total > 0) {
                $pendencias[] = [
                    'chave'     => $chave,
                    'total'     => $total,
                    'url'       => site_url($rota),
                    'atrasadas' => $atrasadas,
                ];
            }
        };

        $patrimonio  = $this->autorizacao->pode('patrimonio.acesso');
        $agendamento = $this->autorizacao->pode('agendamento.acesso');

        if ($agendamento && $this->autorizacao->pode('agendamento.confirmar_reserva', Autorizacao::EDITOR)) {
            $escopo = $this->autorizacao->escopoPara('agendamento.confirmar_reserva', Autorizacao::EDITOR);
            $fila   = new ReservasTabela($escopo, $this->usuarioId, ReservasTabela::FILA);
            $incluir('confirmar', $fila->contarExportacao(Abrangencia::Tudo), 'agendamento/confirmacoes');
        }

        if ($agendamento && $this->autorizacao->pode('agendamento.autorizar_conflito', Autorizacao::EDITOR)) {
            $escopo = $this->autorizacao->escopoPara('agendamento.autorizar_conflito', Autorizacao::EDITOR);
            $incluir(
                'conflitos',
                (new ConflitosTabela($escopo))->contarExportacao(Abrangencia::Tudo),
                'agendamento/conflitos'
            );
        }

        if ($patrimonio) {
            $aReceber = new MovimentacoesTabela(
                $this->autorizacao->escopoPara('patrimonio.acesso'),
                $this->usuarioId,
                null,
                true
            );
            $incluir(
                'receber',
                $aReceber->contarExportacao(Abrangencia::Tudo),
                'patrimonio/movimentacoes/a-receber',
                (new LembreteAtrasos($this->db))->atrasadasParaReceber($this->usuarioId)
            );
        }

        if ($patrimonio && $this->autorizacao->pode('patrimonio.aprovar_descarte', Autorizacao::EDITOR)) {
            $incluir('descartes', $this->descartesPendentes(), 'patrimonio/descartes');
        }

        if ($patrimonio && $this->autorizacao->pode('patrimonio.aprovar_software', Autorizacao::EDITOR)) {
            $total = $this->db->table('softwares')->where('status', 'pendente')->where('deletado_em', null)
                ->countAllResults();
            $incluir('softwares', $total, 'patrimonio/softwares/pendentes');
        }

        if (
            $this->autorizacao->pode('chamados.acesso')
            && $this->autorizacao->pode('chamados.resolver_cancelar', Autorizacao::EDITOR)
        ) {
            $incluir('chamados', $this->chamadosParaAtender(), 'chamados');
        }

        if ($this->autorizacao->pode('patrimonio.acesso', Autorizacao::EDITOR)) {
            $semNumero = new ItensSemNumeroTabela(
                $this->autorizacao->escopoPara('patrimonio.acesso', Autorizacao::EDITOR)
            );
            $incluir('numeros', $semNumero->contarExportacao(Abrangencia::Tudo), 'patrimonio/numeros');
        }

        if ($this->autorizacao->pode('academico.requisitos', Autorizacao::EDITOR)) {
            $incluir('requisitos', $this->disciplinasSemRequisitos(), 'atribuicoes');
        }

        return $pendencias;
    }

    /**
     * Aulas (alocações em que a pessoa é o professor) e reservas (que pediu ou de que é beneficiária) de hoje aos
     * próximos dias. Hoje vem sempre, mesmo vazio; dos demais dias, só os que têm algo. Aula em data com exceção
     * ou feriado não entra (o feriado aparece como aviso do dia).
     *
     * @return list<Dia>
     */
    public function agenda(): array
    {
        $hoje   = $this->hoje();
        $fim    = $hoje->modify('+' . (self::DIAS_AGENDA - 1) . ' days');
        $podeVer = $this->autorizacao->pode('agendamento.acesso');

        /** @var array<string, Dia> $dias */
        $dias = [];

        for ($data = $hoje; $data <= $fim; $data = $data->modify('+1 day')) {
            $dias[$data->format('Y-m-d')] = ['data' => $data, 'feriado' => null, 'eventos' => []];
        }

        $this->incluirAulas($dias, $hoje, $fim, $podeVer);
        $this->incluirReservas($dias, $hoje, $fim, $podeVer);

        $agenda = [];

        foreach ($dias as $chave => $dia) {
            if ($dia['eventos'] === [] && $dia['feriado'] === null && $chave !== $hoje->format('Y-m-d')) {
                continue;
            }

            usort(
                $dia['eventos'],
                static fn (array $a, array $b): int => [$a['inicio'], $a['fim']] <=> [$b['inicio'], $b['fim']]
            );
            $agenda[] = $dia;
        }

        return $agenda;
    }

    /**
     * O que a pessoa pediu e ainda não foi resolvido: reservas aguardando decisão, chamados que abriu e itens
     * que enviou e ninguém recebeu. Só os grupos com algo.
     *
     * @return list<Grupo>
     */
    public function solicitacoes(): array
    {
        return array_values(array_filter(
            [$this->minhasReservas(), $this->meusChamados(), $this->meusEnvios()],
            static fn (array $grupo): bool => $grupo['total'] > 0
        ));
    }

    private function descartesPendentes(): int
    {
        $builder = $this->db->table('descartes ds')
            ->join('itens i', 'i.id_item = ds.item_id')
            ->where('ds.deletado_em', null)
            ->where('ds.status', 'pendente');

        Locais::juntar($builder, 'l', 'i.ambiente_id', 'i.almoxarifado_id');

        $escopo = Locais::condicaoEscopo(
            $this->autorizacao->escopoPara('patrimonio.aprovar_descarte', Autorizacao::EDITOR),
            'l'
        );

        if ($escopo !== null) {
            $builder->where($escopo, null, false);
        }

        return (int) $builder->countAllResults();
    }

    /** Chamados em aberto ou em andamento nos ambientes onde a pessoa resolve chamados. */
    private function chamadosParaAtender(): int
    {
        $builder = $this->db->table('chamados c')
            ->where('c.deletado_em', null)
            ->whereIn('c.status', ['aberto', 'em_andamento']);

        Locais::juntar($builder, 'l', 'c.ambiente_id', 'NULL');

        $escopo = Locais::condicaoEscopo(
            $this->autorizacao->escopoPara('chamados.resolver_cancelar', Autorizacao::EDITOR),
            'l'
        );

        if ($escopo !== null) {
            $builder->where($escopo, null, false);
        }

        return (int) $builder->countAllResults();
    }

    /** Disciplinas atribuídas à pessoa em semestre não encerrado para as quais ela ainda não registrou requisitos. */
    private function disciplinasSemRequisitos(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(DISTINCT pd.disciplina_id) AS total
             FROM professores_disciplinas pd
             JOIN semestres s ON s.id_semestre = pd.semestre_id AND s.deletado_em IS NULL AND s.status <> 'encerrado'
             JOIN disciplinas d ON d.id_disciplina = pd.disciplina_id AND d.deletado_em IS NULL
             LEFT JOIN requisitos_disciplina rd ON rd.professor_id = pd.professor_id
                  AND rd.disciplina_id = pd.disciplina_id AND rd.deletado_em IS NULL
             WHERE pd.professor_id = ? AND pd.deletado_em IS NULL AND rd.id_requisito_disciplina IS NULL",
            [$this->usuarioId]
        )->getRow()->total;
    }

    /**
     * @param array<string, Dia> $dias
     */
    private function incluirAulas(array &$dias, DateTimeImmutable $hoje, DateTimeImmutable $fim, bool $podeVer): void
    {
        $alocacoes = $this->db->query(
            "SELECT a.id_alocacao_semestral, a.dia_semana, a.hora_inicio, a.hora_fim, a.status, s.data_inicio,
                    s.data_fim, d.sigla, d.nome AS disciplina, c.nome AS curso, p.campus_id,
                    CONCAT(amb.numero, ' ', amb.nome, ' · ', p.abreviacao, ' · ', cp.abreviacao) AS local
             FROM alocacoes_semestrais a
             JOIN semestres s   ON s.id_semestre = a.semestre_id AND s.deletado_em IS NULL
             JOIN disciplinas d ON d.id_disciplina = a.disciplina_id
             JOIN cursos c      ON c.id_curso = d.curso_id
             JOIN ambientes amb ON amb.id_ambiente = a.ambiente_id
             JOIN predios p     ON p.id_predio = amb.predio_id
             JOIN campus cp     ON cp.id_campus = p.campus_id
             WHERE a.professor_id = ? AND a.deletado_em IS NULL AND a.status <> 'cancelada'
               AND s.data_inicio <= ? AND s.data_fim >= ?",
            [$this->usuarioId, $fim->format('Y-m-d'), $hoje->format('Y-m-d')]
        )->getResultArray();

        if ($alocacoes === []) {
            return;
        }

        $ids      = implode(', ', array_map('intval', array_column($alocacoes, 'id_alocacao_semestral')));
        $periodo  = [$hoje->format('Y-m-d'), $fim->format('Y-m-d')];
        $excecoes = [];
        $feriados = [];

        $linhas = $this->db->query(
            "SELECT alocacao_semestral_id, data_excecao FROM alocacoes_semestrais_excecoes
             WHERE deletado_em IS NULL AND alocacao_semestral_id IN ({$ids}) AND data_excecao BETWEEN ? AND ?",
            $periodo
        )->getResultArray();

        foreach ($linhas as $linha) {
            $excecoes[(int) $linha['alocacao_semestral_id']][(string) $linha['data_excecao']] = true;
        }

        $linhas = $this->db->query(
            'SELECT campus_id, data_feriado, descricao FROM feriados
             WHERE deletado_em IS NULL AND data_feriado BETWEEN ? AND ?',
            $periodo
        )->getResultArray();

        // Campus 0 = feriado de todos os campi (campus_id nulo).
        foreach ($linhas as $linha) {
            $feriados[(int) $linha['campus_id']][(string) $linha['data_feriado']] = (string) $linha['descricao'];
        }

        foreach ($dias as $chave => &$dia) {
            $diaSemana = Alocacoes::diaDaSemana($dia['data']);

            foreach ($alocacoes as $a) {
                if ($a['dia_semana'] !== $diaSemana || $chave < $a['data_inicio'] || $chave > $a['data_fim']) {
                    continue;
                }

                $feriado = $feriados[0][$chave] ?? $feriados[(int) $a['campus_id']][$chave] ?? null;

                if ($feriado !== null) {
                    $dia['feriado'] = $feriado;

                    continue;
                }

                if (isset($excecoes[(int) $a['id_alocacao_semestral']][$chave])) {
                    continue;
                }

                $pendente         = $a['status'] === 'pendente_adequacao';
                $dia['eventos'][] = [
                    'tipo'    => 'aula',
                    'inicio'  => substr((string) $a['hora_inicio'], 0, 5),
                    'fim'     => substr((string) $a['hora_fim'], 0, 5),
                    'titulo'  => $a['sigla'] . ' — ' . $a['disciplina'],
                    'detalhe' => (string) $a['curso'],
                    'local'   => (string) $a['local'],
                    'url'     => $podeVer ? site_url('agendamento/alocacoes/' . $a['id_alocacao_semestral']) : null,
                    'status'  => $pendente ? lang('Agendamento.allocationStatus_pendente_adequacao') : null,
                    'tom'     => $pendente ? 'pending' : null,
                ];
            }
        }
    }

    /**
     * @param array<string, Dia> $dias
     */
    private function incluirReservas(array &$dias, DateTimeImmutable $hoje, DateTimeImmutable $fim, bool $podeVer): void
    {
        $reservas = $this->db->query(
            "SELECT r.id_reserva_pontual, r.titulo, r.status, r.data_hora_inicio, r.data_hora_fim,
                    CONCAT(amb.numero, ' ', amb.nome, ' · ', p.abreviacao, ' · ', cp.abreviacao) AS local
             FROM reservas_pontuais r
             JOIN ambientes amb ON amb.id_ambiente = r.ambiente_id
             JOIN predios p     ON p.id_predio = amb.predio_id
             JOIN campus cp     ON cp.id_campus = p.campus_id
             WHERE r.deletado_em IS NULL AND r.status <> 'cancelada'
               AND (r.solicitante_id = ? OR r.beneficiario_id = ?)
               AND r.data_hora_inicio < ? AND r.data_hora_fim > ?
             ORDER BY r.data_hora_inicio",
            [
                $this->usuarioId,
                $this->usuarioId,
                $fim->modify('+1 day')->format('Y-m-d 00:00:00'),
                $hoje->format('Y-m-d 00:00:00'),
            ]
        )->getResultArray();

        foreach ($reservas as $r) {
            // Reserva que começou antes de hoje e ainda não acabou aparece em hoje.
            $chave = max(substr((string) $r['data_hora_inicio'], 0, 10), $hoje->format('Y-m-d'));

            if (! isset($dias[$chave])) {
                continue;
            }

            /** @var Evento $evento */
            $evento = [
                'tipo'    => 'reserva',
                'inicio'  => substr((string) $r['data_hora_inicio'], 11, 5),
                'fim'     => substr((string) $r['data_hora_fim'], 11, 5),
                'titulo'  => (string) $r['titulo'],
                'detalhe' => '',
                'local'   => (string) $r['local'],
                'url'     => $podeVer ? site_url('agendamento/reservas/' . $r['id_reserva_pontual']) : null,
                'status'  => lang('Agendamento.reservationStatus_' . $r['status']),
                'tom'     => ReservasTabela::tom((string) $r['status']),
            ];

            $dias[$chave]['eventos'][] = $evento;
        }
    }

    /** @return Grupo */
    private function minhasReservas(): array
    {
        $filtro = "r.deletado_em IS NULL AND r.status IN ('pendente_confirmacao', 'pendente_autorizacao_conflito')
                   AND (r.solicitante_id = ? OR r.beneficiario_id = ?) AND r.data_hora_fim > ?";
        $binds  = [$this->usuarioId, $this->usuarioId, $this->agora->format('Y-m-d H:i:s')];

        $total = (int) $this->db->query("SELECT COUNT(*) AS total FROM reservas_pontuais r WHERE {$filtro}", $binds)
            ->getRow()->total;

        $reservas = $total === 0 ? [] : $this->db->query(
            "SELECT r.id_reserva_pontual, r.titulo, r.status, r.data_hora_inicio,
                    CONCAT(amb.numero, ' ', amb.nome) AS ambiente
             FROM reservas_pontuais r
             JOIN ambientes amb ON amb.id_ambiente = r.ambiente_id
             WHERE {$filtro} ORDER BY r.data_hora_inicio LIMIT " . self::LIMITE,
            $binds
        )->getResultArray();

        $podeVer = $this->autorizacao->pode('agendamento.acesso');
        $formato = lang('Institucional.dateFormat') . ' H:i';

        return [
            'chave'  => 'reservas',
            'total'  => $total,
            'url'    => $podeVer ? site_url('agendamento/reservas') : null,
            'linhas' => array_map(static fn (array $r): array => [
                'titulo'  => (string) $r['titulo'],
                'detalhe' => date($formato, (int) strtotime((string) $r['data_hora_inicio'])) . ' · ' . $r['ambiente'],
                'status'  => lang('Agendamento.reservationStatus_' . $r['status']),
                'tom'     => 'pending',
                'url'     => $podeVer ? site_url('agendamento/reservas/' . $r['id_reserva_pontual']) : null,
            ], $reservas),
        ];
    }

    /** @return Grupo */
    private function meusChamados(): array
    {
        $filtro = "c.deletado_em IS NULL AND c.status IN ('aberto', 'em_andamento') AND c.aberto_por_id = ?";

        $total = (int) $this->db->query("SELECT COUNT(*) AS total FROM chamados c WHERE {$filtro}", [$this->usuarioId])
            ->getRow()->total;

        $chamados = $total === 0 ? [] : $this->db->query(
            "SELECT c.id_chamado, c.status, c.descricao, CONCAT(amb.numero, ' ', amb.nome) AS ambiente
             FROM chamados c
             JOIN ambientes amb ON amb.id_ambiente = c.ambiente_id
             WHERE {$filtro} ORDER BY c.alterado_em DESC, c.id_chamado DESC LIMIT " . self::LIMITE,
            [$this->usuarioId]
        )->getResultArray();

        $podeVer = $this->autorizacao->pode('chamados.acesso');

        return [
            'chave'  => 'chamados',
            'total'  => $total,
            'url'    => $podeVer ? site_url('chamados') : null,
            'linhas' => array_map(static fn (array $c): array => [
                'titulo'  => '#' . $c['id_chamado'] . ' · ' . $c['ambiente'],
                'detalhe' => mb_strimwidth((string) $c['descricao'], 0, 90, '…'),
                'status'  => lang('Chamados.status_' . $c['status']),
                'tom'     => 'pending',
                'url'     => $podeVer ? site_url('chamados/' . $c['id_chamado']) : null,
            ], $chamados),
        ];
    }

    /** @return Grupo */
    private function meusEnvios(): array
    {
        $filtro = "m.deletado_em IS NULL AND m.status = 'pendente' AND m.remetente_id = ? AND m.destinatario_id <> ?";
        $binds  = [$this->usuarioId, $this->usuarioId];

        $total = (int) $this->db->query("SELECT COUNT(*) AS total FROM movimentacoes_itens m WHERE {$filtro}", $binds)
            ->getRow()->total;

        $envios = $total === 0 ? [] : $this->db->query(
            "SELECT m.id_movimentacao_item, i.numero_patrimonio, i.patrimoniavel, i.nome, i.quantidade,
                    u.nome AS destinatario,
                    (m.data_limite_confirmacao IS NOT NULL AND m.data_limite_confirmacao < NOW()) AS atrasada
             FROM movimentacoes_itens m
             JOIN itens i    ON i.id_item = m.item_id
             JOIN usuarios u ON u.id_usuario = m.destinatario_id
             WHERE {$filtro} ORDER BY m.data_envio LIMIT " . self::LIMITE,
            $binds
        )->getResultArray();

        $podeVer = $this->autorizacao->pode('patrimonio.acesso');

        return [
            'chave'  => 'envios',
            'total'  => $total,
            'url'    => $podeVer ? site_url('patrimonio/movimentacoes') : null,
            'linhas' => array_map(static function (array $m) use ($podeVer): array {
                $atrasada = (int) $m['atrasada'] === 1;

                return [
                    'titulo'  => item_rotulo($m),
                    'detalhe' => lang('Painel.toRecipient', [(string) $m['destinatario']]),
                    'status'  => lang('Patrimonio.movement_' . ($atrasada ? 'atrasada' : 'pendente')),
                    'tom'     => MovimentacoesTabela::tom($atrasada ? 'atrasada' : 'pendente'),
                    'url'     => $podeVer ? site_url('patrimonio/movimentacoes/' . $m['id_movimentacao_item']) : null,
                ];
            }, $envios),
        ];
    }
}

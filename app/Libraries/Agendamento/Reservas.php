<?php

namespace App\Libraries\Agendamento;

use App\Libraries\Auditoria\Auditoria;
use App\Libraries\Chamados\Atendimento;
use App\Libraries\Chamados\Chamados;
use App\Libraries\Notificacoes\Destinatarios;
use App\Libraries\Notificacoes\Notificador;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use DateTimeImmutable;
use Throwable;

/**
 * Reservas pontuais (REQUISITOS §7). A criação e a confirmação passam pelas procedures do banco
 * (sp_criar_reserva_pontual, sp_confirmar_reserva_pontual), que travam o horário com FOR UPDATE: aqui
 * ficam só as checagens que não disputam corrida (antecedência, feriado) e o que vem depois (série,
 * extras, cancelamento, decisão de conflito com alocação semestral).
 *
 * As procedures abrem e fecham a própria transação: nunca chame criar()/confirmar() dentro de outra
 * transação do PHP (o START TRANSACTION da procedure confirmaria a de fora sem aviso).
 *
 * Disciplina (opcional): quando a reserva fica confirmada (já na criação, ou na confirmação da fila, ou ao
 * autorizar o conflito) e o ambiente não atende integralmente aos requisitos do professor (beneficiário, ou
 * quem reservou), abre-se sozinho um chamado de adequação, a não ser que a reserva marque "ignorar requisitos".
 *
 * Notificações: o beneficiário de uma reserva feita em nome dele; quem confirma (reserva pendente) e quem
 * autoriza (conflito com alocação) no ambiente; quem reservou e o beneficiário na confirmação, no cancelamento
 * (inclusive pela fila) e na decisão do conflito. Quem fez a ação nunca é avisado dela.
 *
 * @phpstan-type Pedido array{
 *     ambiente_id: int, solicitante_id: int, beneficiario_id: ?int, titulo: string,
 *     inicio: DateTimeImmutable, fim: DateTimeImmutable,
 *     recorrencia?: array{tipo: string, data_fim: ?DateTimeImmutable, numero: ?int}|null,
 *     itens?: array<int, int>, softwares?: list<int>,
 *     disciplina_id?: ?int, ignorar_requisitos?: bool
 * }
 * @phpstan-type Criada array{id: int, inicio: DateTimeImmutable, status: string}
 * @phpstan-type Recusada array{inicio: DateTimeImmutable, motivo: string}
 */
final class Reservas
{
    public const STATUS = ['pendente_confirmacao', 'confirmada', 'pendente_autorizacao_conflito', 'cancelada'];

    /** Escopo de uma alteração/cancelamento numa série. */
    public const ESCOPOS = ['esta', 'seguintes', 'serie'];

    private readonly Configuracoes $configuracoes;
    private readonly Auditoria $auditoria;
    private readonly Notificador $notificador;

    public function __construct(private readonly BaseConnection $db, private readonly ?DateTimeImmutable $agora = null)
    {
        $this->configuracoes = new Configuracoes($db);
        $this->auditoria     = new Auditoria($db);
        $this->notificador   = new Notificador($db);
    }

    /**
     * Cria a reserva (ou a série). Numa série, as ocorrências livres são criadas e as demais voltam em
     * 'recusadas' com o motivo; nenhuma criada = Recusa com o motivo da primeira.
     *
     * @param Pedido $pedido
     *
     * @return array{criadas: list<Criada>, recusadas: list<Recusada>, recorrencia_id: ?int}
     *
     * @throws Recusa
     */
    public function criar(array $pedido): array
    {
        if ($pedido['fim'] <= $pedido['inicio']) {
            throw new Recusa('Agendamento.endBeforeStart');
        }

        if ($pedido['inicio']->format('Y-m-d') !== $pedido['fim']->format('Y-m-d')) {
            throw new Recusa('Agendamento.sameDayOnly');
        }

        $recorrencia = $pedido['recorrencia'] ?? null;
        $inicios     = $recorrencia === null
            ? [$pedido['inicio']]
            : Recorrencia::datas(
                $pedido['inicio'],
                $recorrencia['tipo'],
                $recorrencia['data_fim'],
                $recorrencia['numero']
            );
        $duracao     = $pedido['fim']->getTimestamp() - $pedido['inicio']->getTimestamp();
        $config      = $this->configuracoes->doAmbiente($pedido['ambiente_id']);
        $campusId    = (int) $config['campus_id'];

        $recorrenciaId = null;
        if ($recorrencia !== null) {
            $this->db->table('recorrencias')->insert([
                'tipo'               => $recorrencia['tipo'],
                'data_fim'           => $recorrencia['data_fim']?->format('Y-m-d'),
                'numero_ocorrencias' => $recorrencia['data_fim'] === null ? $recorrencia['numero'] : null,
            ]);
            $recorrenciaId = (int) $this->db->insertID();
        }

        $criadas   = [];
        $recusadas = [];

        foreach ($inicios as $inicio) {
            $fim    = $inicio->modify("+{$duracao} seconds");
            $motivo = $this->motivoDaData($config, $campusId, $inicio);
            $id     = null;
            $status = '';

            if ($motivo === null) {
                [$id, $status] = $this->chamarCriacao($pedido, $inicio, $fim);
            }

            if ($id === null) {
                $recusadas[] = ['inicio' => $inicio, 'motivo' => $motivo ?? 'Agendamento.result_' . $status];

                continue;
            }

            $this->depoisDeCriar($id, $recorrenciaId, $pedido);

            if ($status === 'confirmada') {
                $this->abrirChamadoSeNecessario($id);
            }

            $criadas[] = ['id' => $id, 'inicio' => $inicio, 'status' => $status];
        }

        if ($criadas === []) {
            if ($recorrenciaId !== null) {
                $this->db->table('recorrencias')->where('id_recorrencia', $recorrenciaId)->delete();
            }

            $motivo = lang($recusadas[0]['motivo'] ?? 'Agendamento.result_rejeitada_conflito');

            throw new Recusa('Agendamento.refusedBecause', [is_string($motivo) ? $motivo : '']);
        }

        $this->notificarCriacao($pedido, $criadas);

        return ['criadas' => $criadas, 'recusadas' => $recusadas, 'recorrencia_id' => $recorrenciaId];
    }

    /**
     * Confirma uma reserva pendente; as concorrentes do mesmo horário são canceladas pela procedure, com a
     * justificativa citando quem confirmou (fila de confirmação).
     *
     * @throws Recusa
     */
    public function confirmar(int $reservaId, int $confirmadorId): void
    {
        $reserva = $this->reserva($reservaId);

        if ($reserva['status'] !== 'pendente_confirmacao') {
            throw new Recusa('Agendamento.notPending');
        }

        $pessoas = [(int) $reserva['solicitante_id'], (int) ($reserva['beneficiario_id'] ?? 0)];
        $propria = in_array($confirmadorId, $pessoas, true);
        $config  = $this->configuracoes->doAmbiente((int) $reserva['ambiente_id']);

        if ($propria && ! (bool) $config['confirmador_pode_confirmar_propria']) {
            throw new Recusa('Agendamento.cannotConfirmOwn');
        }

        // As concorrentes que a procedure vai cancelar (mesmo critério dela), para o log e o aviso.
        $concorrentes = $this->db->query(
            "SELECT * FROM reservas_pontuais
             WHERE ambiente_id = ? AND id_reserva_pontual <> ? AND deletado_em IS NULL
               AND status = 'pendente_confirmacao' AND data_hora_inicio < ? AND data_hora_fim > ?",
            [(int) $reserva['ambiente_id'], $reservaId, $reserva['data_hora_fim'], $reserva['data_hora_inicio']]
        )->getResultArray();

        $this->db->query('CALL sp_confirmar_reserva_pontual(?, ?)', [$reservaId, $confirmadorId]);

        if ($this->reserva($reservaId)['status'] !== 'confirmada') {
            throw new Recusa('Agendamento.notPending');
        }

        $this->auditoria->registrarDepois('reservas_pontuais', $reservaId, $reserva);
        $this->avisarDonos($reserva, 'reserva_confirmada', $confirmadorId);
        $this->abrirChamadoSeNecessario($reservaId);

        foreach ($concorrentes as $concorrente) {
            $id = (int) $concorrente['id_reserva_pontual'];

            if ($this->reserva($id)['status'] === 'cancelada') {
                $this->auditoria->registrarDepois('reservas_pontuais', $id, $concorrente);
                $this->avisarDonos($concorrente, 'reserva_cancelada_fila', $confirmadorId);
            }
        }
    }

    /**
     * Cancela a reserva ou parte da série ('esta', 'seguintes', 'serie'; da série, só as que ainda não
     * começaram). A antecedência mínima de cancelamento do campus vale para quem cancela a própria; quem tem
     * a permissão de cancelar reservas de terceiros não está sujeito a ela ($ignorarPrazo).
     *
     * @return int quantas foram canceladas
     *
     * @throws Recusa
     */
    public function cancelar(
        int $reservaId,
        int $usuarioId,
        string $justificativa,
        string $escopo = 'esta',
        bool $ignorarPrazo = false
    ): int {
        if (trim($justificativa) === '') {
            throw new Recusa('Agendamento.justificationRequired');
        }

        $reserva = $this->reserva($reservaId);
        $alvos   = $this->ocorrencias($reserva, $escopo);

        if ($alvos === []) {
            throw new Recusa('Agendamento.nothingToCancel');
        }

        if (! $ignorarPrazo) {
            $this->exigirPrazoCancelamento($reserva, $alvos);
        }

        return $this->emTransacao(function () use ($alvos, $usuarioId, $justificativa): int {
            foreach ($alvos as $alvo) {
                $this->marcarCancelada((int) $alvo['id_reserva_pontual'], $usuarioId, $justificativa);
            }

            $this->avisarDonos($alvos[0], 'reserva_cancelada', $usuarioId, [
                'justificativa' => $justificativa,
                'quantidade'    => count($alvos),
            ]);

            return count($alvos);
        });
    }

    /**
     * Altera título e horário (mesmo dia de cada ocorrência). Só o título: atualiza direto. Horário novo:
     * cada ocorrência é cancelada e criada de novo pela procedure (que confere o horário novo); se a
     * procedure recusar, a ocorrência antiga volta como estava.
     *
     * @return array{alteradas: int, recusadas: list<Recusada>}
     *
     * @throws Recusa
     */
    public function alterar(
        int $reservaId,
        int $usuarioId,
        string $escopo,
        string $titulo,
        string $horaInicio,
        string $horaFim,
        string $justificativa
    ): array {
        $reserva = $this->reserva($reservaId);
        $alvos   = $this->ocorrencias($reserva, $escopo);

        if ($alvos === []) {
            throw new Recusa('Agendamento.nothingToEdit');
        }

        if ($horaFim <= $horaInicio) {
            throw new Recusa('Agendamento.endBeforeStart');
        }

        $mesmoHorario = substr((string) $reserva['data_hora_inicio'], 11, 5) === $horaInicio
            && substr((string) $reserva['data_hora_fim'], 11, 5) === $horaFim;

        if ($mesmoHorario) {
            $ids = array_map(static fn (array $a): int => (int) $a['id_reserva_pontual'], $alvos);
            $this->db->table('reservas_pontuais')->whereIn('id_reserva_pontual', $ids)->update(['titulo' => $titulo]);

            foreach ($alvos as $alvo) {
                $this->auditoria->registrarDepois('reservas_pontuais', (int) $alvo['id_reserva_pontual'], $alvo);
            }

            return ['alteradas' => count($ids), 'recusadas' => []];
        }

        $config    = $this->configuracoes->doAmbiente((int) $reserva['ambiente_id']);
        $alteradas = 0;
        $recusadas = [];

        foreach ($alvos as $alvo) {
            $dia    = substr((string) $alvo['data_hora_inicio'], 0, 10);
            $inicio = new DateTimeImmutable("{$dia} {$horaInicio}");
            $fim    = new DateTimeImmutable("{$dia} {$horaFim}");
            $motivo = $this->motivoDaData($config, (int) $config['campus_id'], $inicio);

            if ($motivo !== null) {
                $recusadas[] = ['inicio' => $inicio, 'motivo' => $motivo];

                continue;
            }

            $antigoId = (int) $alvo['id_reserva_pontual'];
            $this->marcarCancelada($antigoId, $usuarioId, $justificativa);

            $pedido = [
                'ambiente_id'     => (int) $alvo['ambiente_id'],
                'solicitante_id'  => (int) $alvo['solicitante_id'],
                'beneficiario_id' => $alvo['beneficiario_id'] === null ? null : (int) $alvo['beneficiario_id'],
                'titulo'          => $titulo,
                'inicio'          => $inicio,
                'fim'             => $fim,
            ];
            [$novoId, $status] = $this->chamarCriacao($pedido, $inicio, $fim);

            if ($novoId === null) {
                $this->restaurar($alvo);
                $recusadas[] = ['inicio' => $inicio, 'motivo' => 'Agendamento.result_' . $status];

                continue;
            }

            $recorrencia = $alvo['recorrencia_id'] === null ? null : (int) $alvo['recorrencia_id'];
            $this->depoisDeCriar($novoId, $recorrencia, []);
            $this->copiarExtras($antigoId, $novoId);
            $alteradas++;
        }

        return ['alteradas' => $alteradas, 'recusadas' => $recusadas];
    }

    /**
     * Decide o conflito entre a reserva e a alocação semestral. Autorizar: a alocação é excepcionada naquele
     * dia (todas as que ocupam o horário) e a reserva segue o fluxo normal (confirmada, ou pendente de
     * confirmação se o ambiente exige). Negar: a reserva é cancelada por falta de aprovação.
     *
     * @throws Recusa
     */
    public function decidirConflito(int $conflitoId, int $autorizadorId, bool $autorizar, string $observacao = ''): void
    {
        $nome = (string) ($this->db->query('SELECT nome FROM usuarios WHERE id_usuario = ?', [$autorizadorId])
            ->getRow()->nome ?? '');

        $confirmada = null;

        $dados = [$conflitoId, $autorizadorId, $autorizar, $observacao, $nome];

        $this->emTransacao(function () use ($dados, &$confirmada): void {
            [$conflitoId, $autorizadorId, $autorizar, $observacao, $nome] = $dados;

            $conflito = $this->db->query(
                "SELECT c.id_conflito_agendamento, c.reserva_pontual_id, r.ambiente_id,
                        r.data_hora_inicio, r.data_hora_fim
                 FROM conflitos_agendamento c
                 JOIN reservas_pontuais r ON r.id_reserva_pontual = c.reserva_pontual_id
                 WHERE c.id_conflito_agendamento = ? AND c.deletado_em IS NULL AND c.decisao = 'pendente'
                   AND r.status = 'pendente_autorizacao_conflito' AND r.deletado_em IS NULL
                 FOR UPDATE",
                [$conflitoId]
            )->getRowArray();

            if ($conflito === null) {
                throw new Recusa('Agendamento.conflictAlreadyDecided');
            }

            $reservaId = (int) $conflito['reserva_pontual_id'];
            $antes     = $this->auditoria->ler('conflitos_agendamento', $conflitoId);
            $reserva   = $this->reserva($reservaId);

            $this->db->query(
                'UPDATE conflitos_agendamento SET decisao = ?, autorizador_id = ?, decidido_em = NOW()
                 WHERE id_conflito_agendamento = ?',
                [$autorizar ? 'autorizado' : 'negado', $autorizadorId, $conflitoId]
            );
            $this->auditoria->registrarDepois('conflitos_agendamento', $conflitoId, $antes);
            $this->avisarDonos($reserva, $autorizar ? 'conflito_autorizado' : 'conflito_negado', $autorizadorId);

            if (! $autorizar) {
                $texto = lang('Agendamento.conflictDeniedJustification', [$nome]);
                $this->marcarCancelada($reservaId, $autorizadorId, trim($texto . ' ' . $observacao));

                return;
            }

            $this->exigirHorarioLivre($conflito);
            $this->excepcionarAlocacoes($conflito, $reservaId, $observacao);

            $exige = (bool) $this->db
                ->query('SELECT fn_ambiente_exige_confirmacao(?) AS exige', [(int) $conflito['ambiente_id']])
                ->getRow()->exige;

            $this->db->query(
                'UPDATE reservas_pontuais SET status = ?, confirmado_por_id = ?, data_confirmacao = ?
                 WHERE id_reserva_pontual = ?',
                $exige
                    ? ['pendente_confirmacao', null, null, $reservaId]
                    : ['confirmada', $autorizadorId, date('Y-m-d H:i:s'), $reservaId]
            );
            $this->auditoria->registrarDepois('reservas_pontuais', $reservaId, $reserva);

            if (! $exige) {
                $confirmada = $reservaId;
            }
        });

        if ($confirmada !== null) {
            $this->abrirChamadoSeNecessario($confirmada);
        }
    }

    /**
     * Abre o chamado de adequação da reserva confirmada quando o atendimento não é integral (uma vez só; nada
     * a fazer sem disciplina ou com "ignorar requisitos").
     */
    private function abrirChamadoSeNecessario(int $reservaId): ?int
    {
        $reserva = $this->reserva($reservaId);

        if ($reserva['disciplina_id'] === null || (bool) $reserva['ignorar_requisitos']) {
            return null;
        }

        $jaAberto = $this->db->table('chamados')
            ->where('reserva_pontual_id', $reservaId)
            ->where('status <>', 'cancelado')
            ->where('deletado_em', null)
            ->countAllResults() > 0;

        if ($jaAberto) {
            return null;
        }

        $atendimento = new Atendimento($this->db);
        $professorId = $atendimento->professorDaDisciplina(
            (int) $reserva['disciplina_id'],
            (string) $reserva['data_hora_inicio'],
            (int) ($reserva['beneficiario_id'] ?? $reserva['solicitante_id'])
        );
        $avaliacao   = $atendimento
            ->avaliar($professorId, (int) $reserva['disciplina_id'], (int) $reserva['ambiente_id']);

        if ($avaliacao['nivel'] === Atendimento::INTEGRAL) {
            return null;
        }

        $linhas = [lang('Agendamento.ticketFromReservation', [(string) $reserva['titulo']])];

        foreach ($avaliacao['pendencias'] as $pendencia) {
            $linhas[] = '- ' . Alocacoes::textoPendencia($pendencia);
        }

        return (new Chamados($this->db))->abrir(
            (int) $reserva['ambiente_id'],
            implode("
", $linhas),
            (int) $reserva['solicitante_id'],
            null,
            $reservaId
        );
    }

    /** @return array<string, mixed> */
    public function reserva(int $reservaId): array
    {
        $reserva = $this->db->table('reservas_pontuais')
            ->where('id_reserva_pontual', $reservaId)
            ->where('deletado_em', null)
            ->get()
            ->getRowArray();

        return $reserva ?? throw new Recusa('Agendamento.reservationNotFound');
    }

    /**
     * Ocorrências afetadas por uma alteração/cancelamento, ainda não canceladas. 'esta' só olha a própria;
     * 'seguintes' e 'serie' pegam as da série a partir desta (ou todas) que ainda não começaram.
     *
     * @param array<string, mixed> $reserva
     *
     * @return list<array<string, mixed>>
     */
    public function ocorrencias(array $reserva, string $escopo): array
    {
        if (! in_array($escopo, self::ESCOPOS, true)) {
            throw new Recusa('Agendamento.scopeInvalid');
        }

        if ($escopo === 'esta' || $reserva['recorrencia_id'] === null) {
            return $reserva['status'] === 'cancelada' ? [] : [$reserva];
        }

        $builder = $this->db->table('reservas_pontuais')
            ->where('recorrencia_id', (int) $reserva['recorrencia_id'])
            ->where('deletado_em', null)
            ->where('status <>', 'cancelada')
            ->where('data_hora_inicio >', $this->agora()->format('Y-m-d H:i:s'))
            ->orderBy('data_hora_inicio');

        if ($escopo === 'seguintes') {
            $builder->where('data_hora_inicio >=', (string) $reserva['data_hora_inicio']);
        }

        return $builder->get()->getResultArray();
    }

    /**
     * Motivo pelo qual a data não aceita reserva (antecedência mínima/máxima, feriado), ou null.
     *
     * @param array<string, mixed> $config
     */
    private function motivoDaData(array $config, int $campusId, DateTimeImmutable $inicio): ?string
    {
        $agora  = $this->agora();
        $minimo = $config['tempo_minimo_antecedencia_reserva_minutos'];
        $maximo = $config['tempo_maximo_antecedencia_reserva_dias'];

        if ($inicio <= $agora) {
            return 'Agendamento.inPast';
        }

        if ($minimo !== null && $inicio < $agora->modify('+' . (int) $minimo . ' minutes')) {
            return 'Agendamento.tooSoon';
        }

        if ($maximo !== null && $inicio > $agora->modify('+' . (int) $maximo . ' days')) {
            return 'Agendamento.tooFar';
        }

        $letivo = $this->db->query('SELECT fn_dia_letivo(?, ?) AS letivo', [$campusId, $inicio->format('Y-m-d')])
            ->getRow()->letivo;

        return (bool) $letivo ? null : 'Agendamento.holiday';
    }

    /**
     * @param array<string, mixed> $pedido
     *
     * @return array{0: ?int, 1: string} [id criado ou null, status devolvido pela procedure]
     */
    private function chamarCriacao(array $pedido, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $this->db->query(
            'CALL sp_criar_reserva_pontual(?, ?, ?, ?, ?, ?, @ambienta_id, @ambienta_status)',
            [
                $pedido['ambiente_id'],
                $pedido['solicitante_id'],
                $pedido['beneficiario_id'],
                $pedido['titulo'],
                $inicio->format('Y-m-d H:i:s'),
                $fim->format('Y-m-d H:i:s'),
            ]
        );

        $saida = $this->db->query('SELECT @ambienta_id AS id, @ambienta_status AS status')->getRowArray();

        return [$saida['id'] === null ? null : (int) $saida['id'], (string) $saida['status']];
    }

    /** @param array<string, mixed> $pedido */
    private function depoisDeCriar(int $reservaId, ?int $recorrenciaId, array $pedido): void
    {
        if (($pedido['disciplina_id'] ?? null) !== null) {
            $this->db->table('reservas_pontuais')
                ->where('id_reserva_pontual', $reservaId)
                ->update([
                    'disciplina_id'      => $pedido['disciplina_id'],
                    'ignorar_requisitos' => ($pedido['ignorar_requisitos'] ?? false) ? 1 : 0,
                ]);
        }

        if ($recorrenciaId !== null) {
            $this->db->table('reservas_pontuais')
                ->where('id_reserva_pontual', $reservaId)
                ->update(['recorrencia_id' => $recorrenciaId]);
        }

        foreach ($pedido['itens'] ?? [] as $categoriaId => $quantidade) {
            $this->db->table('reservas_itens_extras')->insert([
                'reserva_pontual_id' => $reservaId,
                'categoria_item_id'  => (int) $categoriaId,
                'quantidade'         => max(1, (int) $quantidade),
            ]);
        }

        foreach ($pedido['softwares'] ?? [] as $softwareId) {
            $this->db->table('reservas_softwares_extras')->insert([
                'reserva_pontual_id' => $reservaId,
                'software_id'        => (int) $softwareId,
            ]);
        }

        $this->auditoria->registrarDepois('reservas_pontuais', $reservaId, null);

        foreach ($this->conflitosDa($reservaId) as $conflitoId) {
            $this->auditoria->registrarDepois('conflitos_agendamento', $conflitoId, null);
        }
    }

    /** @return list<int> conflitos com alocação gravados pela procedure para a reserva */
    private function conflitosDa(int $reservaId): array
    {
        $linhas = $this->db->table('conflitos_agendamento')
            ->select('id_conflito_agendamento')
            ->where('reserva_pontual_id', $reservaId)
            ->where('deletado_em', null)
            ->get()
            ->getResultArray();

        return array_map('intval', array_column($linhas, 'id_conflito_agendamento'));
    }

    /**
     * Avisos de uma criação (uma notificação por pedido, mesmo numa série): ao beneficiário quando outra pessoa
     * reservou por ele; a quem confirma, se ficou pendente; a quem autoriza, se caiu em conflito com alocação.
     *
     * @param array<string, mixed> $pedido
     * @param list<Criada>         $criadas
     */
    private function notificarCriacao(array $pedido, array $criadas): void
    {
        $solicitante = (int) $pedido['solicitante_id'];
        $ambiente    = (int) $pedido['ambiente_id'];
        $primeira    = $this->reserva($criadas[0]['id']);
        $parametros  = $this->parametrosReserva($primeira, $solicitante) + ['quantidade' => count($criadas)];
        $porStatus   = [];

        foreach ($criadas as $criada) {
            $porStatus[$criada['status']][] = $criada['id'];
        }

        $beneficiario = $pedido['beneficiario_id'] ?? null;

        if ($beneficiario !== null && $beneficiario !== $solicitante) {
            $this->notificador->notificar(
                [$beneficiario],
                'reserva_em_seu_nome',
                'reserva_pontual',
                $criadas[0]['id'],
                $parametros,
                $solicitante
            );
        }

        $destinatarios = new Destinatarios($this->db);

        if (isset($porStatus['pendente_confirmacao'])) {
            $this->notificador->notificar(
                $destinatarios->noAmbiente('agendamento.confirmar_reserva', $ambiente),
                'reserva_pendente',
                'reserva_pontual',
                $porStatus['pendente_confirmacao'][0],
                $parametros,
                $solicitante
            );
        }

        if (isset($porStatus['pendente_autorizacao_conflito'])) {
            $conflitos = $this->conflitosDa($porStatus['pendente_autorizacao_conflito'][0]);

            $this->notificador->notificar(
                $destinatarios->noAmbiente('agendamento.autorizar_conflito', $ambiente),
                'conflito_pendente',
                'conflito_agendamento',
                $conflitos[0] ?? $porStatus['pendente_autorizacao_conflito'][0],
                $parametros,
                $solicitante
            );
        }
    }

    /**
     * Avisa quem reservou e o beneficiário (menos quem fez a ação).
     *
     * @param array<string, mixed> $reserva
     * @param array<string, mixed> $extra   parâmetros além de título, ambiente, início e autor
     */
    private function avisarDonos(array $reserva, string $chave, int $autorId, array $extra = []): void
    {
        $this->notificador->notificar(
            [(int) $reserva['solicitante_id'], (int) ($reserva['beneficiario_id'] ?? 0)],
            $chave,
            'reserva_pontual',
            (int) $reserva['id_reserva_pontual'],
            $this->parametrosReserva($reserva, $autorId) + $extra,
            $autorId
        );
    }

    /**
     * @param array<string, mixed> $reserva
     *
     * @return array<string, string>
     */
    private function parametrosReserva(array $reserva, int $autorId): array
    {
        return [
            'titulo'   => (string) $reserva['titulo'],
            'ambiente' => $this->notificador->ambiente((int) $reserva['ambiente_id']),
            'inicio'   => (string) $reserva['data_hora_inicio'],
            'autor'    => $this->notificador->nome($autorId),
        ];
    }

    /** A ocorrência recriada numa alteração de horário leva os pedidos de extras ainda pendentes. */
    private function copiarExtras(int $de, int $para): void
    {
        $this->db->query(
            "INSERT INTO reservas_itens_extras (reserva_pontual_id, categoria_item_id, quantidade)
             SELECT ?, categoria_item_id, quantidade FROM reservas_itens_extras
             WHERE reserva_pontual_id = ? AND status = 'pendente' AND deletado_em IS NULL",
            [$para, $de]
        );
        $this->db->query(
            "INSERT INTO reservas_softwares_extras (reserva_pontual_id, software_id)
             SELECT ?, software_id FROM reservas_softwares_extras
             WHERE reserva_pontual_id = ? AND status = 'pendente' AND deletado_em IS NULL",
            [$para, $de]
        );
    }

    /**
     * Cancelar devolve à alocação semestral o dia que havia sido excepcionado para esta reserva.
     */
    private function marcarCancelada(int $reservaId, int $usuarioId, string $justificativa): void
    {
        $antes = $this->auditoria->ler('reservas_pontuais', $reservaId);

        $this->db->query(
            "UPDATE reservas_pontuais
             SET status = 'cancelada', justificativa_cancelamento = ?, cancelado_por_id = ?, data_cancelamento = NOW()
             WHERE id_reserva_pontual = ? AND status <> 'cancelada'",
            [$justificativa, $usuarioId, $reservaId]
        );
        $this->db->query(
            'UPDATE alocacoes_semestrais_excecoes SET deletado_em = NOW()
             WHERE reserva_pontual_id = ? AND deletado_em IS NULL',
            [$reservaId]
        );

        if ($antes !== null) {
            $this->auditoria->registrarDepois('reservas_pontuais', $reservaId, $antes);
        }
    }

    /** @param array<string, mixed> $original a linha como estava antes do cancelamento */
    private function restaurar(array $original): void
    {
        $id    = (int) $original['id_reserva_pontual'];
        $antes = $this->auditoria->ler('reservas_pontuais', $id);

        $this->db->table('reservas_pontuais')
            ->where('id_reserva_pontual', $id)
            ->update([
                'status'                     => $original['status'],
                'justificativa_cancelamento' => $original['justificativa_cancelamento'],
                'cancelado_por_id'           => $original['cancelado_por_id'],
                'data_cancelamento'          => $original['data_cancelamento'],
            ]);

        if ($antes !== null) {
            $this->auditoria->registrarDepois('reservas_pontuais', $id, $antes);
        }

        try {
            $this->db->query(
                'UPDATE alocacoes_semestrais_excecoes SET deletado_em = NULL
                 WHERE reserva_pontual_id = ? AND deletado_em IS NOT NULL',
                [(int) $original['id_reserva_pontual']]
            );
        } catch (DatabaseException) {
            // O dia já foi excepcionado de novo por outra decisão: nada a devolver.
        }
    }

    /**
     * @param array<string, mixed>       $reserva
     * @param list<array<string, mixed>> $alvos
     */
    private function exigirPrazoCancelamento(array $reserva, array $alvos): void
    {
        $config = $this->configuracoes->doAmbiente((int) $reserva['ambiente_id']);
        $minimo = $config['tempo_minimo_cancelamento_minutos'];

        if ($minimo === null) {
            return;
        }

        $limite = $this->agora()->modify('+' . (int) $minimo . ' minutes');

        foreach ($alvos as $alvo) {
            if (new DateTimeImmutable((string) $alvo['data_hora_inicio']) < $limite) {
                throw new Recusa('Agendamento.cancelTooLate', [(int) $minimo]);
            }
        }
    }

    /**
     * Enquanto o conflito esperava, o horário pode ter sido tomado (reserva confirmada ou bloqueio).
     *
     * @param array<string, mixed> $conflito
     */
    private function exigirHorarioLivre(array $conflito): void
    {
        $binds = [(int) $conflito['ambiente_id'], $conflito['data_hora_fim'], $conflito['data_hora_inicio']];

        $ocupado = $this->db->query(
            "SELECT COUNT(*) AS total FROM reservas_pontuais
             WHERE ambiente_id = ? AND deletado_em IS NULL AND status = 'confirmada'
               AND data_hora_inicio < ? AND data_hora_fim > ? FOR UPDATE",
            $binds
        )->getRow()->total;

        $bloqueado = $this->db->query(
            'SELECT COUNT(*) AS total FROM bloqueios_ambientes
             WHERE ambiente_id = ? AND deletado_em IS NULL AND data_hora_inicio < ? AND data_hora_fim > ? FOR UPDATE',
            $binds
        )->getRow()->total;

        if ((int) $ocupado > 0 || (int) $bloqueado > 0) {
            throw new Recusa('Agendamento.slotTaken');
        }
    }

    /** @param array<string, mixed> $conflito */
    private function excepcionarAlocacoes(array $conflito, int $reservaId, string $observacao): void
    {
        $inicio = new DateTimeImmutable((string) $conflito['data_hora_inicio']);

        $fim        = new DateTimeImmutable((string) $conflito['data_hora_fim']);
        $ocupando   = Alocacoes::ocupandoNoDia($this->db, (int) $conflito['ambiente_id'], $inicio, $fim);

        foreach ($ocupando as $alocacaoId) {
            $this->db->table('alocacoes_semestrais_excecoes')->insert([
                'alocacao_semestral_id' => $alocacaoId,
                'reserva_pontual_id'    => $reservaId,
                'data_excecao'          => $inicio->format('Y-m-d'),
                'motivo'                => 'conflito_autorizado',
                'observacao'            => $observacao === '' ? null : $observacao,
            ]);
        }
    }

    private function agora(): DateTimeImmutable
    {
        return $this->agora ?? new DateTimeImmutable('now');
    }

    /**
     * @template T
     *
     * @param callable(): T $operacao
     *
     * @return T
     */
    private function emTransacao(callable $operacao): mixed
    {
        $this->db->transBegin();

        try {
            $resultado = $operacao();
            $this->db->transCommit();

            return $resultado;
        } catch (Throwable $erro) {
            $this->db->transRollback();

            throw $erro;
        }
    }
}

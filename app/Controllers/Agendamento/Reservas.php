<?php

namespace App\Controllers\Agendamento;

use App\Libraries\Agendamento\Agenda;
use App\Libraries\Agendamento\Extras;
use App\Libraries\Agendamento\Recorrencia;
use App\Libraries\Agendamento\Recusa;
use App\Libraries\Agendamento\Reservas as ReservasServico;
use App\Libraries\Autorizacao;
use App\Libraries\Chamados\Atendimento;
use App\Models\Patrimonio\CategoriaItemModel;
use App\Models\Patrimonio\SoftwareModel;
use App\Tabelas\Agendamento\ReservasTabela;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;

/**
 * Reservas pontuais: listagem, fila de confirmação, nova reserva (busca de ambientes livres no horário, com
 * seleção explícita para ver os ocupados), página da reserva (confirmar, alterar, cancelar, extras e a decisão
 * do conflito com alocação semestral).
 */
class Reservas extends ControladorAgendamento
{
    private const LISTA = 'agendamento/reservas';

    public function index(): string
    {
        $tabela = $this->tabela($this->novaTabela(ReservasTabela::TODAS));

        return $this->pedidoParcial() ? $tabela->fragmento() : view('patrimonio/_lista', [
            'tabela' => $tabela,
            'titulo' => lang('Agendamento.reservationsTitle'),
            'intro'  => lang('Agendamento.reservationsIntro'),
            'novo'   => $this->autorizacao->pode(self::CHAVE, Autorizacao::EDITOR)
                ? [lang('Agendamento.reservationNew'), site_url(self::LISTA . '/novo')]
                : null,
        ]);
    }

    public function exportar(): ResponseInterface
    {
        return $this->exportarTabela($this->novaTabela(ReservasTabela::TODAS));
    }

    public function fila(): ResponseInterface|string
    {
        if (! $this->autorizacao->pode(self::CONFIRMAR, Autorizacao::EDITOR)) {
            return $this->negar();
        }

        $tabela = $this->tabela($this->novaTabela(ReservasTabela::FILA));

        return $this->pedidoParcial() ? $tabela->fragmento() : view('patrimonio/_lista', [
            'tabela' => $tabela,
            'titulo' => lang('Agendamento.queueTitle'),
            'intro'  => lang('Agendamento.queueIntro'),
        ]);
    }

    public function exportarFila(): ResponseInterface
    {
        if (! $this->autorizacao->pode(self::CONFIRMAR, Autorizacao::EDITOR)) {
            return $this->negar();
        }

        return $this->exportarTabela($this->novaTabela(ReservasTabela::FILA));
    }

    public function novo(): ResponseInterface|string
    {
        if (! $this->autorizacao->pode(self::CHAVE, Autorizacao::EDITOR)) {
            return $this->negar();
        }

        $busca = [
            'data'     => (string) $this->request->getGet('data'),
            'inicio'   => (string) $this->request->getGet('inicio'),
            'fim'      => (string) $this->request->getGet('fim'),
            'ocupados' => $this->request->getGet('ocupados') === '1',
            'disciplina' => $this->disciplinaValida((string) $this->request->getGet('disciplina')),
        ];

        return $this->formulario($busca, ['ambiente_id' => (string) $this->request->getGet('ambiente')]);
    }

    public function criar(): ResponseInterface|RedirectResponse|string
    {
        $busca = [
            'data'     => $this->texto('data'),
            'inicio'   => $this->texto('inicio'),
            'fim'      => $this->texto('fim'),
            'ocupados' => $this->request->getPost('ocupados') === '1',
            'disciplina' => $this->disciplinaValida($this->texto('disciplina')),
        ];
        $dados = [
            'ambiente_id'     => $this->texto('ambiente_id'),
            'titulo'          => $this->texto('titulo'),
            'beneficiario_id' => $this->texto('beneficiario_id'),
            'repetir'         => $this->texto('repetir'),
            'repetir_ate'     => $this->texto('repetir_ate'),
            'repetir_vezes'   => $this->texto('repetir_vezes'),
            'itens'           => (array) ($this->request->getPost('itens') ?? []),
            'softwares'       => (array) ($this->request->getPost('softwares') ?? []),
            'ignorar'         => $this->request->getPost('ignorar') === '1',
            'ciente'          => $this->request->getPost('ciente') === '1',
        ];

        $erros = $this->validar($dados, [
            'ambiente_id' => $this->regra('Agendamento.fieldEnvironment', 'required|is_natural_no_zero'),
            'titulo'      => $this->regra('Agendamento.fieldTitle', 'required|max_length[255]'),
        ]);

        $inicio = $this->dataHora($busca['data'], $busca['inicio']);
        $fim    = $this->dataHora($busca['data'], $busca['fim']);

        if ($inicio === null || $fim === null) {
            $erros['data'] = lang('Agendamento.dateTimeInvalid');
        }

        $ambienteId = (int) $dados['ambiente_id'];

        if (! isset($erros['ambiente_id']) && ! $this->podeNoAmbiente($ambienteId, Autorizacao::EDITOR)) {
            $erros['ambiente_id'] = lang('Agendamento.environmentInvalid');
        }

        $beneficiario = null;
        $recorrencia  = null;
        $resultado    = null;

        try {
            $beneficiario = $this->beneficiario($dados['beneficiario_id'], $ambienteId);
        } catch (Recusa $recusa) {
            $erros['beneficiario_id'] = $recusa->texto();
        }

        try {
            $recorrencia = $this->recorrencia($dados);
        } catch (Recusa $recusa) {
            $erros['repetir'] = $recusa->texto();
        }

        $disciplinaId = $busca['disciplina'] === '' ? null : (int) $busca['disciplina'];

        if ($erros === [] && $disciplinaId !== null && ! $dados['ignorar'] && ! $dados['ciente']) {
            $atendimento = new Atendimento(db_connect());
            $nivel       = $atendimento->avaliar(
                $atendimento->professorDaDisciplina($disciplinaId, $busca['data'], $beneficiario ?? $this->usuarioId()),
                $disciplinaId,
                $ambienteId
            )['nivel'];

            if ($nivel !== Atendimento::INTEGRAL) {
                $erros['ciente'] = lang('Agendamento.fulfilmentAsk', [Atendimento::rotulo($nivel)]);
            }
        }

        if ($erros === [] && $inicio !== null && $fim !== null) {
            try {
                $resultado = (new ReservasServico(db_connect()))->criar([
                    'ambiente_id'     => $ambienteId,
                    'solicitante_id'  => $this->usuarioId(),
                    'beneficiario_id' => $beneficiario,
                    'titulo'          => $dados['titulo'],
                    'inicio'          => $inicio,
                    'fim'             => $fim,
                    'recorrencia'     => $recorrencia,
                    'itens'           => $this->itensPedidos($dados['itens']),
                    'softwares'       => $this->softwaresPedidos($dados['softwares']),
                    'disciplina_id'      => $disciplinaId,
                    'ignorar_requisitos' => $dados['ignorar'],
                ]);
            } catch (Recusa $recusa) {
                $erros['geral'] = $recusa->texto();
            }
        }

        if ($erros !== [] || $resultado === null) {
            return $this->formulario($busca, $dados, $erros, 422);
        }

        return $this->aposCriar($resultado);
    }

    public function ver(int $id): ResponseInterface|RedirectResponse|string
    {
        [$reserva, $recusa] = $this->carregar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $db         = db_connect();
        $ambienteId = (int) $reserva['ambiente_id'];
        $conflito   = $db->query(
            "SELECT c.id_conflito_agendamento, c.decisao, c.decidido_em, u.nome AS autorizador,
                    d.sigla, d.nome AS disciplina, p.nome AS professor, a.hora_inicio, a.hora_fim
             FROM conflitos_agendamento c
             JOIN alocacoes_semestrais a ON a.id_alocacao_semestral = c.alocacao_semestral_id
             JOIN disciplinas d ON d.id_disciplina = a.disciplina_id
             JOIN usuarios p ON p.id_usuario = a.professor_id
             LEFT JOIN usuarios u ON u.id_usuario = c.autorizador_id
             WHERE c.reserva_pontual_id = ? AND c.deletado_em IS NULL",
            [$id]
        )->getRowArray();

        $serie = $reserva['recorrencia_id'] === null ? [] : $db->table('reservas_pontuais')
            ->select('id_reserva_pontual, data_hora_inicio, data_hora_fim, status')
            ->where('recorrencia_id', (int) $reserva['recorrencia_id'])
            ->where('deletado_em', null)
            ->orderBy('data_hora_inicio')
            ->get()
            ->getResultArray();

        return view('agendamento/reservas/ver', [
            'reserva'      => $reserva,
            'ambiente'     => $this->locais->rotulo($ambienteId, null),
            'conflito'     => $conflito,
            'serie'        => $serie,
            'extras'       => (new Extras($db))->daReserva($id),
            'podeConfirmar' => $reserva['status'] === 'pendente_confirmacao'
                && $this->podeNoAmbiente($ambienteId, Autorizacao::EDITOR, self::CONFIRMAR),
            'podeDecidir'  => $conflito !== null && $conflito['decisao'] === 'pendente'
                && $this->podeNoAmbiente($ambienteId, Autorizacao::EDITOR, self::AUTORIZAR),
            'podeMexer'    => $reserva['status'] !== 'cancelada' && $this->podeMexer($reserva),
            'podeExtras'   => $this->podeNoAmbiente($ambienteId, Autorizacao::EDITOR, self::PATRIMONIO),
        ]);
    }

    public function confirmar(int $id): RedirectResponse|ResponseInterface
    {
        [$reserva, $recusa] = $this->carregar($id);

        if ($recusa !== null) {
            return $recusa;
        }

        if (! $this->podeNoAmbiente((int) $reserva['ambiente_id'], Autorizacao::EDITOR, self::CONFIRMAR)) {
            return $this->negar();
        }

        $destino = redirect()->to($this->destinoLocal($this->request->getPost('voltar'), '/' . self::LISTA . "/{$id}"));

        try {
            (new ReservasServico(db_connect()))->confirmar($id, $this->usuarioId());
        } catch (Recusa $recusa) {
            return $destino->with('erro', $recusa->texto());
        }

        return $destino->with('sucesso', lang('Agendamento.reservationConfirmed', [(string) $reserva['titulo']]));
    }

    public function cancelamento(int $id): ResponseInterface|RedirectResponse|string
    {
        [$reserva, $recusa] = $this->carregarParaMexer($id);

        return $recusa ?? $this->formularioCancelamento($reserva, []);
    }

    public function cancelar(int $id): ResponseInterface|RedirectResponse|string
    {
        [$reserva, $recusa] = $this->carregarParaMexer($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $dados = ['escopo' => $this->texto('escopo') ?: 'esta', 'justificativa' => $this->texto('justificativa')];
        $erros = $this->validar($dados, [
            'justificativa' => $this->regra('Agendamento.fieldJustification', 'required|max_length[5000]'),
        ]);

        $total = null;

        if ($erros === []) {
            try {
                $total = (new ReservasServico(db_connect()))->cancelar(
                    $id,
                    $this->usuarioId(),
                    $dados['justificativa'],
                    $dados['escopo'],
                    $this->podeNoAmbiente((int) $reserva['ambiente_id'], Autorizacao::EDITOR, self::CANCELAR)
                );
            } catch (Recusa $recusa) {
                $erros['justificativa'] = $recusa->texto();
            }
        }

        if ($total === null) {
            return $this->formularioCancelamento($reserva, $dados, $erros, 422);
        }

        $mensagem = lang('Agendamento.reservationsCancelled', [$total]);

        return $this->depoisDoModal(site_url(self::LISTA . "/{$id}"), $mensagem);
    }

    public function edicao(int $id): ResponseInterface|RedirectResponse|string
    {
        [$reserva, $recusa] = $this->carregarParaMexer($id);

        return $recusa ?? $this->formularioEdicao($reserva, [
            'escopo'      => 'esta',
            'titulo'      => $reserva['titulo'],
            'hora_inicio' => substr((string) $reserva['data_hora_inicio'], 11, 5),
            'hora_fim'    => substr((string) $reserva['data_hora_fim'], 11, 5),
        ]);
    }

    public function alterar(int $id): ResponseInterface|RedirectResponse|string
    {
        [$reserva, $recusa] = $this->carregarParaMexer($id);

        if ($recusa !== null) {
            return $recusa;
        }

        $dados = [
            'escopo'        => $this->texto('escopo') ?: 'esta',
            'titulo'        => $this->texto('titulo'),
            'hora_inicio'   => $this->texto('hora_inicio'),
            'hora_fim'      => $this->texto('hora_fim'),
            'justificativa' => $this->texto('justificativa'),
        ];
        $erros = $this->validar($dados, [
            'titulo'      => $this->regra('Agendamento.fieldTitle', 'required|max_length[255]'),
            'hora_inicio' => $this->regra('Agendamento.fieldStartTime', 'required'),
            'hora_fim'    => $this->regra('Agendamento.fieldEndTime', 'required'),
        ]);

        if (! isset($erros['hora_inicio']) && ! $this->horaValida($dados['hora_inicio'])) {
            $erros['hora_inicio'] = lang('Agendamento.dateTimeInvalid');
        }

        if (! isset($erros['hora_fim']) && ! $this->horaValida($dados['hora_fim'])) {
            $erros['hora_fim'] = lang('Agendamento.dateTimeInvalid');
        }

        $resultado = null;

        if ($erros === []) {
            try {
                $resultado = (new ReservasServico(db_connect()))->alterar(
                    $id,
                    $this->usuarioId(),
                    $dados['escopo'],
                    $dados['titulo'],
                    $dados['hora_inicio'],
                    $dados['hora_fim'],
                    $dados['justificativa'] !== '' ? $dados['justificativa'] : lang('Agendamento.editedJustification')
                );

                if ($resultado['alteradas'] === 0) {
                    $erros['hora_inicio'] = $this->textoRecusas($resultado['recusadas']);
                }
            } catch (Recusa $recusa) {
                $erros['hora_inicio'] = $recusa->texto();
            }
        }

        if ($erros !== [] || $resultado === null) {
            return $this->formularioEdicao($reserva, $dados, $erros, 422);
        }

        $mensagem = lang('Agendamento.reservationsEdited', [$resultado['alteradas']]);

        if ($resultado['recusadas'] !== []) {
            $mensagem .= ' ' . $this->textoRecusas($resultado['recusadas']);
        }

        // Horário novo = ocorrência nova: a página da antiga não é mais a da reserva ativa.
        return $this->depoisDoModal(site_url(self::LISTA), $mensagem);
    }

    public function decidirItem(int $extraId): RedirectResponse|ResponseInterface
    {
        return $this->decidirExtra($extraId, 'item');
    }

    public function decidirSoftware(int $extraId): RedirectResponse|ResponseInterface
    {
        return $this->decidirExtra($extraId, 'software');
    }

    public function devolverItem(int $extraId): RedirectResponse|ResponseInterface
    {
        return $this->decidirExtra($extraId, 'devolucao');
    }

    private function decidirExtra(int $extraId, string $tipo): RedirectResponse|ResponseInterface
    {
        $extras    = new Extras(db_connect());
        $reservaId = $tipo === 'software' ? $extras->reservaDoSoftware($extraId) : $extras->reservaDoItem($extraId);

        if ($reservaId === null) {
            return redirect()->to(site_url(self::LISTA))->with('erro', lang('Agendamento.reservationNotFound'));
        }

        [$reserva, $recusa] = $this->carregar($reservaId);

        if ($recusa !== null) {
            return $recusa;
        }

        if (! $this->podeNoAmbiente((int) $reserva['ambiente_id'], Autorizacao::EDITOR, self::PATRIMONIO)) {
            return $this->negar();
        }

        $destino = redirect()->to(site_url(self::LISTA . "/{$reservaId}"));
        $deferir = $this->request->getPost('decisao') === 'deferido';
        $nota    = $this->texto('justificativa');

        try {
            match ($tipo) {
                'item'     => $extras->decidirItem($extraId, $this->usuarioId(), $deferir, $nota),
                'software' => $extras->decidirSoftware($extraId, $this->usuarioId(), $deferir, $nota),
                default    => $extras->confirmarDevolucao($extraId, $this->usuarioId()),
            };
        } catch (Recusa $recusa) {
            return $destino->with('erro', $recusa->texto());
        }

        $mensagem = $tipo === 'devolucao' ? 'Agendamento.returnConfirmed' : 'Agendamento.extraDecided';

        return $destino->with('sucesso', lang($mensagem));
    }

    /**
     * @param array{
     *     criadas: list<array<string, mixed>>, recusadas: list<array<string, mixed>>, recorrencia_id: ?int
     * } $resultado
     */
    private function aposCriar(array $resultado): RedirectResponse
    {
        $criadas  = $resultado['criadas'];
        $primeira = $criadas[0];
        $status   = array_values(array_unique(array_column($criadas, 'status')));

        $mensagem = count($criadas) === 1
            ? lang('Agendamento.created_' . $primeira['status'])
            : lang('Agendamento.seriesCreated', [count($criadas)]);

        if (count($criadas) > 1 && in_array('pendente_autorizacao_conflito', $status, true)) {
            $mensagem .= ' ' . lang('Agendamento.seriesHasConflicts');
        }

        $redirecionar = redirect()->to(site_url(self::LISTA . '/' . $primeira['id']))->with('sucesso', $mensagem);

        return $resultado['recusadas'] === []
            ? $redirecionar
            : $redirecionar->with('erro', $this->textoRecusas($resultado['recusadas']));
    }

    /** Disciplina do filtro da busca: só um valor que a fonte do select conhece, senão vazio. */
    private function disciplinaValida(string $valor): string
    {
        $valor = trim($valor);

        $fonte = $this->autorizacao->pode(self::TERCEIROS, Autorizacao::EDITOR)
            ? service('opcoes')->disciplinas()
            : service('opcoes')->disciplinasDoProfessor($this->usuarioId());

        return $valor !== '' && $fonte->contem($valor) ? $valor : '';
    }

    /** @param list<array<string, mixed>> $recusadas */
    private function textoRecusas(array $recusadas): string
    {
        $formato = lang('Institucional.dateFormat') . ' H:i';
        $partes  = [];

        foreach ($recusadas as $recusada) {
            $partes[] = $recusada['inicio']->format($formato) . ' (' . lang($recusada['motivo']) . ')';
        }

        return lang('Agendamento.occurrencesRefused', [count($recusadas), implode('; ', $partes)]);
    }

    /**
     * Beneficiário escolhido: vazio (a própria pessoa) ou alguém, só com a permissão no ambiente.
     *
     * @throws Recusa
     */
    private function beneficiario(string $valor, int $ambienteId): ?int
    {
        if ($valor === '' || (int) $valor === $this->usuarioId()) {
            return null;
        }

        if (
            ! $this->podeNoAmbiente($ambienteId, Autorizacao::EDITOR, self::TERCEIROS)
            || ! service('opcoes')->usuariosAtivos()->contem((int) $valor)
        ) {
            throw new Recusa('Agendamento.beneficiaryInvalid');
        }

        return (int) $valor;
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array{tipo: string, data_fim: ?DateTimeImmutable, numero: ?int}|null
     *
     * @throws Recusa
     */
    private function recorrencia(array $dados): ?array
    {
        $tipo = (string) $dados['repetir'];

        if ($tipo === '') {
            return null;
        }

        if (! in_array($tipo, Recorrencia::TIPOS, true)) {
            throw new Recusa('Agendamento.recurrenceTypeInvalid');
        }

        $ate    = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $dados['repetir_ate']) ?: null;
        $vezes  = ctype_digit((string) $dados['repetir_vezes']) ? (int) $dados['repetir_vezes'] : null;

        if ($ate === null && ($vezes === null || $vezes < 2)) {
            throw new Recusa('Agendamento.recurrenceEndRequired');
        }

        return ['tipo' => $tipo, 'data_fim' => $ate, 'numero' => $ate === null ? $vezes : null];
    }

    /**
     * @param array<mixed> $postados categoria => quantidade
     *
     * @return array<int, int>
     */
    private function itensPedidos(array $postados): array
    {
        $validas = (new CategoriaItemModel())->opcoes();
        $itens   = [];

        foreach ($postados as $categoria => $quantidade) {
            $valida = array_key_exists((int) $categoria, $validas);

            if ($valida && ctype_digit((string) $quantidade) && (int) $quantidade > 0) {
                $itens[(int) $categoria] = min((int) $quantidade, 999);
            }
        }

        return $itens;
    }

    /**
     * @param array<mixed> $postados
     *
     * @return list<int>
     */
    private function softwaresPedidos(array $postados): array
    {
        $validos = (new SoftwareModel())->opcoes();

        return array_values(array_filter(
            array_unique(array_map('intval', $postados)),
            static fn (int $id): bool => array_key_exists($id, $validos)
        ));
    }

    private function novaTabela(string $modo): ReservasTabela
    {
        $escopo = $modo === ReservasTabela::FILA
            ? $this->escopo(Autorizacao::EDITOR, self::CONFIRMAR)
            : $this->escopo();

        return new ReservasTabela($escopo, $this->usuarioId(), $modo);
    }

    /** @param array<string, mixed> $reserva */
    private function ehPropria(array $reserva): bool
    {
        $pessoas = [(int) $reserva['solicitante_id'], (int) ($reserva['beneficiario_id'] ?? 0)];

        return in_array($this->usuarioId(), $pessoas, true);
    }

    /** @param array<string, mixed> $reserva */
    private function podeMexer(array $reserva): bool
    {
        return $this->ehPropria($reserva)
            || $this->podeNoAmbiente((int) $reserva['ambiente_id'], Autorizacao::EDITOR, self::CANCELAR);
    }

    /**
     * Reserva visível: ambiente no escopo de leitura, ou feita por/para o usuário.
     *
     * @return array{0: array<string, mixed>, 1: ResponseInterface|RedirectResponse|null}
     */
    private function carregar(int $id): array
    {
        $reserva = db_connect()->table('reservas_pontuais r')
            ->select('r.*, s.nome AS solicitante, b.nome AS beneficiario, cf.nome AS confirmado_por,
                      ca.nome AS cancelado_por')
            ->join('usuarios s', 's.id_usuario = r.solicitante_id')
            ->join('usuarios b', 'b.id_usuario = r.beneficiario_id', 'left')
            ->join('usuarios cf', 'cf.id_usuario = r.confirmado_por_id', 'left')
            ->join('usuarios ca', 'ca.id_usuario = r.cancelado_por_id', 'left')
            ->where('r.id_reserva_pontual', $id)
            ->where('r.deletado_em', null)
            ->get()
            ->getRowArray();

        if ($reserva === null) {
            return [[], redirect()->to(site_url(self::LISTA))->with('erro', lang('Agendamento.reservationNotFound'))];
        }

        $visivel = $this->ehPropria($reserva) || $this->podeNoAmbiente((int) $reserva['ambiente_id']);

        return [$reserva, $visivel ? null : $this->negar()];
    }

    /** @return array{0: array<string, mixed>, 1: ResponseInterface|RedirectResponse|null} */
    private function carregarParaMexer(int $id): array
    {
        [$reserva, $recusa] = $this->carregar($id);

        if ($recusa === null && ($reserva['status'] === 'cancelada' || ! $this->podeMexer($reserva))) {
            $recusa = $this->negar();
        }

        return [$reserva, $recusa];
    }

    /** No modal, a página da reserva recarrega (a mensagem vai na sessão); sem JS, redireciona. */
    private function depoisDoModal(string $destino, string $mensagem): ResponseInterface
    {
        if ($this->pedidoModal()) {
            session()->setFlashdata('sucesso', $mensagem);
        }

        return $this->salvo($destino, $mensagem);
    }

    /**
     * @param array{data: string, inicio: string, fim: string, ocupados: bool, disciplina: string} $busca
     * @param array<string, mixed>                                             $dados
     * @param array<string, string>                                            $erros
     */
    private function formulario(
        array $busca,
        array $dados,
        array $erros = [],
        int $status = 200
    ): ResponseInterface|string {
        $ambientes = $this->opcoesAmbientes($this->escopo(Autorizacao::EDITOR));
        $inicio    = $this->dataHora($busca['data'], $busca['inicio']);
        $fim       = $this->dataHora($busca['data'], $busca['fim']);
        $situacao  = null;
        $niveis    = [];

        if ($inicio !== null && $fim !== null && $fim > $inicio) {
            $situacao = (new Agenda(db_connect()))->situacaoNoHorario(array_keys($ambientes), $inicio, $fim);

            if ($busca['disciplina'] !== '') {
                $atendimento = new Atendimento(db_connect());
                $niveis      = $atendimento->niveis(
                    $atendimento->professorDaDisciplina((int) $busca['disciplina'], $busca['data'], $this->usuarioId()),
                    (int) $busca['disciplina'],
                    array_keys($ambientes)
                );
            }
        } elseif ($busca['data'] !== '' || $busca['inicio'] !== '' || $busca['fim'] !== '') {
            $erros['data'] ??= lang('Agendamento.dateTimeInvalid');
        }

        return $this->responder('agendamento/reservas/form', [
            'busca'        => $busca,
            'dados'        => $dados,
            'ambientes'    => $ambientes,
            'situacao'     => $situacao,
            'niveis'       => $niveis,
            'terceiros'    => $this->autorizacao->pode(self::TERCEIROS, Autorizacao::EDITOR),
            'categorias'   => (new CategoriaItemModel())->opcoes(),
            'softwares'    => (new SoftwareModel())->opcoes(),
            'resumos'      => $situacao === null ? [] : $this->resumosAmbientes(array_keys($ambientes)),
            'passoInicial' => $this->passoInicial($situacao !== null, $erros, $dados),
        ], $erros, $status);
    }

    /**
     * Etapa em que a tela abre: a 1 sem busca; depois da busca, a 2; com erro de validação, a etapa do campo.
     *
     * @param array<string, string> $erros
     * @param array<string, mixed>  $dados
     */
    private function passoInicial(bool $buscou, array $erros, array $dados): int
    {
        if (! $buscou || isset($erros['data'])) {
            return 1;
        }

        return match (true) {
            isset($erros['ambiente_id']) => 2,
            array_intersect_key($erros, array_flip(['titulo', 'beneficiario_id', 'repetir', 'ciente'])) !== [] => 3,
            $erros !== [] => 5,
            default => 2,
        };
    }

    /**
     * Resumo de cada ambiente para o modal da etapa 2: local, categoria, capacidade, softwares e equipamentos.
     *
     * @param list<int|string> $ids
     *
     * @return array<int, array<string, mixed>>
     */
    private function resumosAmbientes(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $db      = db_connect();
        $resumos = [];

        $linhas = $db->table('ambientes am')
            ->select('am.id_ambiente, am.numero, am.nome, am.descricao, am.capacidade, am.tamanho_m2')
            ->select("p.nome AS predio, ca.nome AS categoria")
            ->select("COALESCE(NULLIF(TRIM(c.abreviacao), ''), c.nome) AS campus", false)
            ->join('predios p', 'p.id_predio = am.predio_id')
            ->join('campus c', 'c.id_campus = p.campus_id')
            ->join('categorias_ambientes ca', 'ca.id_categoria_ambiente = am.categoria_ambiente_id')
            ->whereIn('am.id_ambiente', $ids)
            ->get()
            ->getResultArray();

        foreach ($linhas as $l) {
            $resumos[(int) $l['id_ambiente']] = [
                'nome'       => trim($l['numero'] . ' ' . $l['nome']),
                'local'      => $l['campus'] . ' › ' . $l['predio'],
                'categoria'  => $l['categoria'],
                'capacidade' => (int) $l['capacidade'],
                'tamanho'    => $l['tamanho_m2'] === null ? '' : (float) $l['tamanho_m2'] . ' m²',
                'descricao'  => (string) $l['descricao'],
                'softwares'  => [],
                'itens'      => [],
            ];
        }

        $softwares = $db->table('ambientes_softwares a')
            ->select('a.ambiente_id, s.nome, s.versao')
            ->join('softwares s', 's.id_software = a.software_id AND s.deletado_em IS NULL')
            ->whereIn('a.ambiente_id', $ids)
            ->where('a.deletado_em', null)
            ->orderBy('s.nome')
            ->get()
            ->getResultArray();

        foreach ($softwares as $s) {
            $resumos[(int) $s['ambiente_id']]['softwares'][] = trim($s['nome'] . ' ' . (string) $s['versao']);
        }

        $itens = $db->table('itens i')
            ->select('i.ambiente_id, ci.nome, SUM(i.quantidade) AS total')
            ->join('categorias_itens ci', 'ci.id_categoria_item = i.categoria_item_id')
            ->whereIn('i.ambiente_id', $ids)
            ->where('i.deletado_em', null)
            ->groupBy('i.ambiente_id, ci.id_categoria_item, ci.nome')
            ->orderBy('ci.nome')
            ->get()
            ->getResultArray();

        foreach ($itens as $i) {
            $resumos[(int) $i['ambiente_id']]['itens'][] = ['nome' => $i['nome'], 'total' => (int) $i['total']];
        }

        return $resumos;
    }

    /**
     * @param array<string, mixed>  $reserva
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    private function formularioCancelamento(
        array $reserva,
        array $dados,
        array $erros = [],
        int $status = 200
    ): ResponseInterface|string {
        return $this->telaFormulario('agendamento/reservas/cancelar', [
            'reserva' => $reserva,
            'dados'   => $dados,
        ], $erros, $status);
    }

    /**
     * @param array<string, mixed>  $reserva
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    private function formularioEdicao(
        array $reserva,
        array $dados,
        array $erros = [],
        int $status = 200
    ): ResponseInterface|string {
        return $this->telaFormulario('agendamento/reservas/editar', [
            'reserva' => $reserva,
            'dados'   => $dados,
        ], $erros, $status);
    }
}

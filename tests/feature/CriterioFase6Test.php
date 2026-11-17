<?php

use Tests\Support\FeatureBancoTestCase;

/**
 * Critério de conclusão da Fase 6: os cenários funcionais já validados no SQL (fila de reserva, conflito de
 * alocação, feriado) se comportam do mesmo jeito passando pela interface. Mais: conflito reserva × alocação
 * (autorizar e negar), bloqueio, série, cancelamento e as configurações do campus.
 *
 * As datas são sempre futuras (segunda-feira daqui a duas semanas), dentro de um semestre aberto.
 *
 * @internal
 */
final class CriterioFase6Test extends FeatureBancoTestCase
{
    private int $campus;
    private int $laboratorio;
    private int $sala;
    private int $semestre;
    private int $curso;
    private int $disciplina;
    private int $vinculo;
    private string $segunda;

    /** @var array<string, mixed> reserva; sem permissões específicas */
    private array $docente;

    /** @var array<string, mixed> reserva; outro docente */
    private array $docenteB;

    /** @var array<string, mixed> confirma reservas (Auxiliares) */
    private array $auxiliar;

    /** @var array<string, mixed> autoriza conflitos, gere o campus (Coordenação) */
    private array $coordenador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus      = $this->criarCampus('Campus A');
        $predio            = $this->criarPredio($this->campus, 'Prédio 1');
        $this->laboratorio = $this->criarAmbiente($predio, '101', 'Laboratório 1');
        $this->sala        = $this->criarAmbiente($predio, '102', 'Sala 2');
        $this->curso       = $this->criarCurso('Análise e Desenvolvimento de Sistemas');
        $this->disciplina  = $this->criarDisciplina('ALG', 'Algoritmos', $this->curso);
        $this->segunda     = (new DateTimeImmutable('monday next week'))->modify('+7 days')->format('Y-m-d');

        $this->db->table('semestres')->insert([
            'nome'        => 'Semestre de teste',
            'data_inicio' => date('Y-m-d', strtotime('-30 days')),
            'data_fim'    => date('Y-m-d', strtotime('+180 days')),
            'status'      => 'aberto',
        ]);
        $this->semestre = (int) $this->db->insertID();

        $this->docente = $this->criarUsuario(['nome' => 'Professora Ana']);
        $this->vinculo = $this->vincularProfessor($this->semestre, $this->disciplina, $this->docente['id_usuario']);
        $this->vincular($this->docente['id_usuario'], 'Docentes', 'campus', $this->campus);
        $this->docenteB = $this->criarUsuario(['nome' => 'Professor Beto']);
        $this->vincular($this->docenteB['id_usuario'], 'Docentes', 'campus', $this->campus);
        $this->auxiliar = $this->criarUsuario(['nome' => 'Auxiliar Caio']);
        $this->vincular($this->auxiliar['id_usuario'], 'Auxiliares', 'campus', $this->campus);
        $this->coordenador = $this->criarUsuario(['nome' => 'Coordenadora Dora']);
        $this->vincular($this->coordenador['id_usuario'], 'Coordenação', 'campus', $this->campus);
    }

    private function dia(string $passo = '+0 days'): string
    {
        return (new DateTimeImmutable($this->segunda))->modify($passo)->format('Y-m-d');
    }

    /** @param array<string, mixed> $extra */
    private function reservar(array $usuario, int $ambiente, string $data, string $inicio, string $fim, array $extra = []): \CodeIgniter\Test\TestResponse
    {
        return $this->como($usuario)->post('agendamento/reservas', $extra + [
            'data'        => $data,
            'inicio'      => $inicio,
            'fim'         => $fim,
            'ambiente_id' => (string) $ambiente,
            'titulo'      => 'Reunião de ' . $usuario['nome'],
        ]);
    }

    /** @return array<string, mixed> a última reserva gravada */
    private function ultimaReserva(): array
    {
        return $this->db->table('reservas_pontuais')->orderBy('id_reserva_pontual', 'DESC')->get()->getRowArray();
    }

    /** @param array<string, string> $campos */
    private function configurar(array $campos): void
    {
        $this->como($this->coordenador)->post("campus/{$this->campus}/configuracoes", $campos)
            ->assertRedirectTo(site_url("campus/{$this->campus}"));
    }

    private function alocar(string $dia, string $inicio, string $fim, ?int $ambiente = null): \CodeIgniter\Test\TestResponse
    {
        return $this->como($this->coordenador)->post('agendamento/alocacoes', [
            'semestre_id'   => (string) $this->semestre,
            'vinculo_id'    => (string) $this->vinculo,
            'ambiente_id'   => (string) ($ambiente ?? $this->laboratorio),
            'dia_semana'    => $dia,
            'hora_inicio'   => $inicio,
            'hora_fim'      => $fim,
        ]);
    }

    /** Cenário 1 (fila): com fila ativa, dois pedidos para o mesmo horário; confirmar um cancela o outro citando quem confirmou. */
    public function testFilaDeConfirmacaoCancelaAConcorrenteCitandoQuemConfirmou(): void
    {
        $this->configurar(['exige_confirmacao_reserva' => '1', 'fila_confirmacao_ativa' => '1']);

        $this->reservar($this->docente, $this->laboratorio, $this->dia('+1 day'), '14:00', '16:00')->assertRedirect();
        $primeira = $this->ultimaReserva();
        $this->reservar($this->docenteB, $this->laboratorio, $this->dia('+1 day'), '15:00', '17:00')->assertRedirect();
        $segunda = $this->ultimaReserva();

        $this->assertSame('pendente_confirmacao', $primeira['status']);
        $this->assertSame('pendente_confirmacao', $segunda['status']);

        // A fila de confirmação mostra as duas para quem confirma; docente não confirma.
        $this->como($this->auxiliar)->get('agendamento/confirmacoes')->assertSee('Reunião de Professora Ana');
        $this->como($this->docente)->get('agendamento/confirmacoes')->assertStatus(403);
        $this->como($this->docente)->post('agendamento/reservas/' . $primeira['id_reserva_pontual'] . '/confirmar')->assertStatus(403);

        $this->como($this->auxiliar)->post('agendamento/reservas/' . $segunda['id_reserva_pontual'] . '/confirmar')
            ->assertRedirectTo(site_url('agendamento/reservas/' . $segunda['id_reserva_pontual']));

        $this->seeInDatabase('reservas_pontuais', [
            'id_reserva_pontual' => $segunda['id_reserva_pontual'],
            'status'             => 'confirmada',
            'confirmado_por_id'  => $this->auxiliar['id_usuario'],
        ]);
        $cancelada = $this->db->table('reservas_pontuais')->where('id_reserva_pontual', $primeira['id_reserva_pontual'])->get()->getRowArray();
        $this->assertSame('cancelada', $cancelada['status']);
        $this->assertStringContainsString('Auxiliar Caio', (string) $cancelada['justificativa_cancelamento']);
    }

    /** Sem fila: o segundo pedido para um horário já pendente é recusado. */
    public function testSemFilaOSegundoPedidoParaOMesmoHorarioERecusado(): void
    {
        $this->configurar(['exige_confirmacao_reserva' => '1']);

        $this->reservar($this->docente, $this->laboratorio, $this->dia('+1 day'), '14:00', '16:00')->assertRedirect();
        $r = $this->reservar($this->docenteB, $this->laboratorio, $this->dia('+1 day'), '15:00', '17:00');

        $r->assertStatus(422);
        $r->assertSee('Não foi possível reservar: horário já reservado.');
        $this->assertSame(1, $this->db->table('reservas_pontuais')->countAllResults());
    }

    /** Cenário 2 (conflito de alocação): mesmo ambiente, dia e horário sobreposto é recusado; outro dia, não. */
    public function testConflitoDeAlocacaoSemestral(): void
    {
        // Alocar exige agendamento.alocar: docente e auxiliar não alocam.
        $this->como($this->docente)->get('agendamento/alocacoes/novo')->assertStatus(403);
        $this->como($this->auxiliar)->get('agendamento/alocacoes/novo')->assertStatus(403);

        $this->alocar('segunda', '08:00', '10:00')->assertRedirect();
        $this->seeInDatabase('alocacoes_semestrais', ['ambiente_id' => $this->laboratorio, 'dia_semana' => 'segunda', 'status' => 'ativa']);

        $r = $this->alocar('segunda', '09:00', '11:00');
        $r->assertStatus(422);
        $r->assertSee('O ambiente já está alocado nesse dia e horário no período do semestre.');

        // Outro dia: passa. Mesma disciplina, mesmo horário, outro ambiente: só com ciência.
        $this->alocar('terca', '09:00', '11:00')->assertRedirect();
        $resposta = $this->alocar('terca', '09:00', '11:00', $this->sala);
        $resposta->assertStatus(422);
        $resposta->assertSee('Marque a ciência para continuar.');

        $this->como($this->coordenador)->post('agendamento/alocacoes', [
            'semestre_id' => (string) $this->semestre, 'vinculo_id' => (string) $this->vinculo,
            'ambiente_id' => (string) $this->sala, 'dia_semana' => 'terca', 'hora_inicio' => '09:00', 'hora_fim' => '11:00',
            'ciencia' => '1',
        ])->assertRedirect();

        $this->assertNotNull($this->db->table('alocacoes_semestrais')->where('ambiente_id', $this->sala)->get()->getRow()->ciencia_conflito_horario_em);
        $this->assertSame(3, $this->db->table('alocacoes_semestrais')->countAllResults());
    }

    /** Cenário 3 (feriado): recusado por padrão; aceito quando o campus permite agendamento em feriado. */
    public function testFeriadoBloqueiaReservaAteOCampusPermitir(): void
    {
        $quarta = $this->dia('+2 days');

        $this->como($this->coordenador)->post('agendamento/feriados', [
            'data_feriado' => $quarta, 'descricao' => 'Aniversário da cidade', 'abrangencia' => 'municipal',
            'campus_id' => (string) $this->campus,
        ])->assertRedirectTo(site_url('agendamento/feriados'));

        $r = $this->reservar($this->docente, $this->laboratorio, $quarta, '10:00', '11:00');
        $r->assertStatus(422);
        $r->assertSee('Não foi possível reservar: feriado, sem agendamento neste campus.');

        $this->configurar(['permite_agendamento_feriado' => '1']);

        $this->reservar($this->docente, $this->laboratorio, $quarta, '10:00', '11:00')->assertRedirect();
        $this->assertSame('confirmada', $this->ultimaReserva()['status']);

        // Mesmo feriado de novo para o campus: não duplica.
        $this->como($this->coordenador)->post('agendamento/feriados?modal=1', [
            'data_feriado' => $quarta, 'descricao' => 'Repetido', 'abrangencia' => 'municipal', 'campus_id' => (string) $this->campus,
        ])->assertStatus(422);
    }

    /** Reserva que esbarra em alocação: fica aguardando; autorizar excepciona a alocação no dia; negar cancela. */
    public function testConflitoComAlocacaoAutorizarENegar(): void
    {
        $this->alocar('segunda', '08:00', '12:00')->assertRedirect();
        $alocacao = (int) $this->db->table('alocacoes_semestrais')->get()->getRow()->id_alocacao_semestral;

        $this->reservar($this->docenteB, $this->laboratorio, $this->dia(), '09:00', '10:00')->assertRedirect();
        $reserva = $this->ultimaReserva();
        $this->assertSame('pendente_autorizacao_conflito', $reserva['status']);
        $conflito = (int) $this->db->table('conflitos_agendamento')->get()->getRow()->id_conflito_agendamento;

        // A agenda da semana mostra o conflito como pendente, com Autorizar para quem pode.
        $agenda = $this->como($this->coordenador)->get('agendamento/agenda?ambiente=' . $this->laboratorio . '&semana=' . $this->segunda);
        $agenda->assertOK();
        $agenda->assertSee('Em conflito, aguardando decisão');
        $agenda->assertSee('agendamento/conflitos/' . $conflito . '/autorizar');
        $this->como($this->docente)->get('agendamento/agenda?ambiente=' . $this->laboratorio . '&semana=' . $this->segunda)
            ->assertDontSee('agendamento/conflitos/' . $conflito . '/autorizar');

        // Auxiliar não autoriza conflito.
        $this->como($this->auxiliar)->post("agendamento/conflitos/{$conflito}/autorizar")->assertStatus(403);

        $this->como($this->coordenador)->post("agendamento/conflitos/{$conflito}/autorizar")
            ->assertRedirectTo(site_url('agendamento/reservas/' . $reserva['id_reserva_pontual']));

        $this->seeInDatabase('reservas_pontuais', ['id_reserva_pontual' => $reserva['id_reserva_pontual'], 'status' => 'confirmada']);
        $this->seeInDatabase('conflitos_agendamento', ['id_conflito_agendamento' => $conflito, 'decisao' => 'autorizado']);
        $this->seeInDatabase('alocacoes_semestrais_excecoes', [
            'alocacao_semestral_id' => $alocacao, 'data_excecao' => $this->segunda, 'motivo' => 'conflito_autorizado',
        ]);

        // Na semana seguinte, outra reserva no mesmo horário: negar cancela por falta de aprovação.
        $this->reservar($this->docenteB, $this->laboratorio, $this->dia('+7 days'), '09:00', '10:00')->assertRedirect();
        $outra = $this->ultimaReserva();
        $conflito2 = (int) $this->db->table('conflitos_agendamento')->where('reserva_pontual_id', $outra['id_reserva_pontual'])->get()->getRow()->id_conflito_agendamento;

        $r = $this->como($this->coordenador)->post("agendamento/conflitos/{$conflito2}/negar?modal=1", ['observacao' => 'Aula de reposição']);
        $r->assertOK();
        $r->assertJSONFragment(['mensagem' => 'Conflito negado: a reserva foi cancelada.']);

        $negada = $this->db->table('reservas_pontuais')->where('id_reserva_pontual', $outra['id_reserva_pontual'])->get()->getRowArray();
        $this->assertSame('cancelada', $negada['status']);
        $this->assertStringContainsString('Coordenadora Dora', (string) $negada['justificativa_cancelamento']);

        // Cancelar a reserva autorizada devolve o dia à alocação.
        $this->como($this->docenteB)->post('agendamento/reservas/' . $reserva['id_reserva_pontual'] . '/cancelar', ['justificativa' => 'Não vou mais usar'])
            ->assertRedirect();
        $this->dontSeeInDatabase('alocacoes_semestrais_excecoes', ['alocacao_semestral_id' => $alocacao, 'deletado_em' => null]);
    }

    /** Bloqueio: cancela as reservas do período e libera a alocação nos dias afetados. */
    public function testBloqueioTemPrioridade(): void
    {
        $this->alocar('segunda', '08:00', '10:00')->assertRedirect();
        $this->reservar($this->docente, $this->laboratorio, $this->dia('+1 day'), '14:00', '15:00')->assertRedirect();
        $reserva = $this->ultimaReserva();

        // Docente não gere o ambiente: não bloqueia.
        $this->como($this->docente)->get('agendamento/bloqueios/novo')->assertStatus(403);

        $r = $this->como($this->coordenador)->post('agendamento/bloqueios?modal=1', [
            'ambiente_id' => (string) $this->laboratorio,
            'tipo'        => 'manutencao',
            'motivo'      => 'Troca da rede elétrica',
            'inicio'      => $this->segunda . 'T07:00',
            'fim'         => $this->dia('+1 day') . 'T18:00',
        ]);
        $r->assertOK();
        $r->assertJSONFragment(['mensagem' => 'Bloqueio criado. Reservas canceladas: 1. Dias de alocação liberados: 1.']);

        $cancelada = $this->db->table('reservas_pontuais')->where('id_reserva_pontual', $reserva['id_reserva_pontual'])->get()->getRowArray();
        $this->assertSame('cancelada', $cancelada['status']);
        $this->assertStringContainsString('Troca da rede elétrica', (string) $cancelada['justificativa_cancelamento']);

        // No período bloqueado, nova reserva é recusada.
        $resposta = $this->reservar($this->docente, $this->laboratorio, $this->dia('+1 day'), '16:00', '17:00')
            ;
        $resposta->assertStatus(422);
        $resposta->assertSee('ambiente bloqueado no horário');

        // Excluir o bloqueio (CONFIRMO) devolve o dia à alocação.
        $bloqueio = (int) $this->db->table('bloqueios_ambientes')->get()->getRow()->id_bloqueio_ambiente;
        $this->como($this->coordenador)->excluir("agendamento/bloqueios/{$bloqueio}")->assertRedirectTo(site_url('agendamento/bloqueios'));
        $this->dontSeeInDatabase('alocacoes_semestrais_excecoes', ['motivo' => 'bloqueio_ambiente', 'deletado_em' => null]);
    }

    /** Série semanal: cria as livres, lista as recusadas; cancelar "esta e as seguintes". */
    public function testSerieSemanalCancelamentoPorEscopo(): void
    {
        // A terceira terça é feriado: essa ocorrência fica de fora.
        $this->db->table('feriados')->insert([
            'data_feriado' => $this->dia('+15 days'), 'descricao' => 'Feriado', 'abrangencia' => 'nacional',
        ]);

        $r = $this->reservar($this->docente, $this->sala, $this->dia('+1 day'), '19:00', '21:00', [
            'repetir' => 'semanal', 'repetir_vezes' => '4',
        ]);
        $r->assertRedirect();
        $r->assertSessionHas('sucesso', '3 ocorrências reservadas.');

        $serie = $this->db->table('reservas_pontuais')->orderBy('data_hora_inicio')->get()->getResultArray();
        $this->assertCount(3, $serie);
        $this->assertCount(1, array_unique(array_column($serie, 'recorrencia_id')));

        // Outra pessoa (sem permissão de cancelar reservas de terceiros) não cancela.
        $this->como($this->docenteB)->post('agendamento/reservas/' . $serie[1]['id_reserva_pontual'] . '/cancelar', [
            'justificativa' => 'x',
        ])->assertStatus(403);

        $r = $this->como($this->docente)->post('agendamento/reservas/' . $serie[1]['id_reserva_pontual'] . '/cancelar?modal=1', [
            'escopo' => 'seguintes', 'justificativa' => 'Fim do projeto',
        ]);
        $r->assertOK();
        $r->assertJSONFragment(['mensagem' => '2 reserva(s) cancelada(s).']);

        $status = array_column($this->db->table('reservas_pontuais')->orderBy('data_hora_inicio')->get()->getResultArray(), 'status');
        $this->assertSame(['confirmada', 'cancelada', 'cancelada'], $status);

        // Justificativa é obrigatória.
        $this->como($this->docente)->post('agendamento/reservas/' . $serie[0]['id_reserva_pontual'] . '/cancelar?modal=1', [
            'justificativa' => '',
        ])->assertStatus(422);
    }

    /** Antecedência mínima de cancelamento e reserva em nome de terceiros. */
    public function testAntecedenciaECancelamentoPorTerceiros(): void
    {
        $this->configurar(['tempo_minimo_cancelamento_minutos' => (string) (60 * 24 * 60)]);

        // Docente não reserva em nome de outra pessoa; coordenadora pode.
        $resposta = $this->reservar($this->docente, $this->sala, $this->dia(), '10:00', '11:00', ['beneficiario_id' => (string) $this->docenteB['id_usuario']])
            ;
        $resposta->assertStatus(422);
        $resposta->assertSee('Você não pode reservar para outra pessoa neste ambiente.');
        $this->reservar($this->coordenador, $this->sala, $this->dia(), '10:00', '11:00', ['beneficiario_id' => (string) $this->docenteB['id_usuario']])
            ->assertRedirect();
        $reserva = $this->ultimaReserva();
        $this->assertSame((int) $this->docenteB['id_usuario'], (int) $reserva['beneficiario_id']);

        // O beneficiário cancela a própria, mas o prazo do campus (60 dias) já passou.
        $resposta = $this->como($this->docenteB)->post('agendamento/reservas/' . $reserva['id_reserva_pontual'] . '/cancelar?modal=1', [
            'justificativa' => 'Imprevisto',
        ]);
        $resposta->assertStatus(422);
        $resposta->assertSee('pelo menos 86400 minutos');

        // Quem cancela reservas de terceiros não está sujeito ao prazo.
        $this->como($this->coordenador)->post('agendamento/reservas/' . $reserva['id_reserva_pontual'] . '/cancelar?modal=1', [
            'justificativa' => 'Evento da direção',
        ])->assertOK();
        $this->seeInDatabase('reservas_pontuais', ['id_reserva_pontual' => $reserva['id_reserva_pontual'], 'status' => 'cancelada']);
    }

    /** Alterar só o título atualiza; mudar o horário recria pela procedure (e volta atrás se o horário novo estiver ocupado). */
    public function testAlterarTituloEHorario(): void
    {
        $this->reservar($this->docente, $this->sala, $this->dia(), '10:00', '11:00')->assertRedirect();
        $original = $this->ultimaReserva();
        $this->reservar($this->docenteB, $this->sala, $this->dia(), '14:00', '15:00')->assertRedirect();
        $url = 'agendamento/reservas/' . $original['id_reserva_pontual'] . '/alterar?modal=1';

        $this->como($this->docente)->post($url, ['titulo' => 'Novo título', 'hora_inicio' => '10:00', 'hora_fim' => '11:00'])->assertOK();
        $this->seeInDatabase('reservas_pontuais', ['id_reserva_pontual' => $original['id_reserva_pontual'], 'titulo' => 'Novo título', 'status' => 'confirmada']);

        // Horário ocupado pela outra reserva: recusa e a original continua como estava.
        $this->como($this->docente)->post($url, ['titulo' => 'Novo título', 'hora_inicio' => '14:30', 'hora_fim' => '15:30'])->assertStatus(422);
        $this->seeInDatabase('reservas_pontuais', ['id_reserva_pontual' => $original['id_reserva_pontual'], 'status' => 'confirmada']);

        $this->como($this->docente)->post($url, ['titulo' => 'Novo título', 'hora_inicio' => '12:00', 'hora_fim' => '13:00'])->assertOK();
        $this->seeInDatabase('reservas_pontuais', ['id_reserva_pontual' => $original['id_reserva_pontual'], 'status' => 'cancelada']);
        $this->seeInDatabase('reservas_pontuais', ['data_hora_inicio' => $this->dia() . ' 12:00:00', 'status' => 'confirmada', 'titulo' => 'Novo título']);
    }

    public function testConfiguracoesDoCampus(): void
    {
        $this->como($this->docente)->get("campus/{$this->campus}/configuracoes")->assertStatus(403);

        $resposta = $this->como($this->coordenador)->get("campus/{$this->campus}/configuracoes");
        $resposta->assertOK();
        $resposta->assertSee('Configurações do campus');

        $resposta = $this->como($this->coordenador)->post("campus/{$this->campus}/configuracoes", [
            'horario_fim_manha' => '13:00', 'horario_fim_tarde' => '12:00',
        ]);
        $resposta->assertStatus(422);
        $resposta->assertSee('Os horários de corte precisam estar em ordem');

        $this->configurar([
            'exige_confirmacao_reserva'                 => '1',
            'tempo_minimo_antecedencia_reserva_minutos' => '30',
            'tempo_maximo_antecedencia_reserva_dias'    => '90',
            'horario_fim_manha'                         => '12:30',
            'prazo_resposta_devolucao_horas'            => '24',
        ]);

        $this->seeInDatabase('configuracoes_campus', [
            'campus_id'                                 => $this->campus,
            'exige_confirmacao_reserva'                 => 1,
            'fila_confirmacao_ativa'                    => 0,
            'tempo_minimo_antecedencia_reserva_minutos' => 30,
            'tempo_maximo_antecedencia_reserva_dias'    => 90,
            'horario_fim_manha'                         => '12:30:00',
            'prazo_resposta_devolucao_horas'            => 24,
        ]);

        // Antecedência máxima de 90 dias: reserva daqui a 100 dias é recusada.
        $longe = date('Y-m-d', strtotime('+100 days'));
        $resposta = $this->reservar($this->docente, $this->sala, $longe, '10:00', '11:00');
        $resposta->assertStatus(422);
        $resposta->assertSee('além da antecedência máxima do campus');
    }

    /** Extras: pedido na reserva, deferimento por quem gere o patrimônio e confirmação da devolução. */
    public function testExtrasDeferirEDevolver(): void
    {
        $categoria = $this->criarCategoriaItem('Projetor');

        $this->reservar($this->docente, $this->sala, $this->dia(), '10:00', '11:00', ['itens' => [$categoria => '2']])->assertRedirect();
        $reserva = $this->ultimaReserva();
        $extra   = (int) $this->db->table('reservas_itens_extras')->get()->getRow()->id_reserva_item_extra;

        $this->como($this->docente)->post("agendamento/extras/itens/{$extra}", ['decisao' => 'deferido'])->assertStatus(403);
        $this->como($this->auxiliar)->post("agendamento/extras/itens/{$extra}", ['decisao' => 'deferido'])
            ->assertRedirectTo(site_url('agendamento/reservas/' . $reserva['id_reserva_pontual']));

        $linha = $this->db->table('reservas_itens_extras')->get()->getRowArray();
        $this->assertSame('deferido', $linha['status']);
        $this->assertSame((string) $reserva['data_hora_fim'], (string) $linha['data_limite_devolucao']);

        $this->como($this->auxiliar)->post("agendamento/extras/itens/{$extra}/devolucao")->assertRedirect();
        $this->assertNotNull($this->db->table('reservas_itens_extras')->get()->getRow()->devolucao_confirmada_em);
    }

    public function testTelasAbremParaQuemTemAcesso(): void
    {
        foreach (
            ['agendamento/agenda', 'agendamento/reservas', 'agendamento/reservas/novo', 'agendamento/alocacoes',
            'agendamento/alocacoes/novo', 'agendamento/bloqueios', 'agendamento/feriados', 'agendamento/conflitos',
            'agendamento/confirmacoes'] as $url
        ) {
            $this->como($this->coordenador)->get($url)->assertOK();
        }

        // Busca de ambientes: livres por padrão; ocupados só com a seleção explícita.
        $this->alocar('segunda', '08:00', '10:00')->assertRedirect();
        $busca = 'agendamento/reservas/novo?data=' . $this->segunda . '&inicio=08:00&fim=09:00';
        $resposta = $this->como($this->docente)->get($busca);
        $resposta->assertOK();
        $resposta->assertSee('Sala 2');
        $resposta->assertDontSee('ocupado por alocação semestral');
        $resposta = $this->como($this->docente)->get($busca . '&ocupados=1');
        $resposta->assertOK();
        $resposta->assertSee('ocupado por alocação semestral');

        // Páginas de detalhe e formulários (página e modal) renderizam.
        $this->reservar($this->docente, $this->laboratorio, $this->segunda, '09:00', '10:00', [
            'repetir' => 'semanal', 'repetir_vezes' => '2',
        ])->assertRedirect();
        $reserva  = (int) $this->db->table('reservas_pontuais')->orderBy('id_reserva_pontual')->get()->getRow()->id_reserva_pontual;
        $alocacao = (int) $this->db->table('alocacoes_semestrais')->get()->getRow()->id_alocacao_semestral;
        $conflito = (int) $this->db->table('conflitos_agendamento')->get()->getRow()->id_conflito_agendamento;
        $this->db->table('feriados')->insert(['campus_id' => $this->campus, 'data_feriado' => '2031-01-20', 'descricao' => 'Local', 'abrangencia' => 'municipal']);
        $feriado = (int) $this->db->insertID();

        $detalhe = $this->como($this->coordenador)->get("agendamento/reservas/{$reserva}");
        $detalhe->assertOK();
        $detalhe->assertSee('Conflito com alocação semestral, aguardando decisão');
        $detalhe->assertSee('Ocorrências da série (2)');
        $this->como($this->coordenador)->get("agendamento/alocacoes/{$alocacao}")->assertOK();
        foreach (
            ["agendamento/reservas/{$reserva}/cancelar", "agendamento/reservas/{$reserva}/alterar",
            "agendamento/conflitos/{$conflito}/negar", 'agendamento/bloqueios/novo', 'agendamento/feriados/novo',
            "agendamento/feriados/{$feriado}/editar"] as $url
        ) {
            $this->como($this->coordenador)->get($url)->assertOK();
            $this->como($this->coordenador)->get($url . (str_contains($url, '?') ? '&' : '?') . 'modal=1')->assertOK();
        }

        // Menu: docente não vê Confirmações nem Conflitos.
        $pagina = $this->como($this->docente)->get('agendamento/reservas');
        $pagina->assertSee('Alocações semestrais');
        $pagina->assertDontSee(site_url('agendamento/conflitos'));

        // Sem agendamento.acesso, nada do módulo.
        $semAcesso = $this->criarUsuario();
        $this->vincular($semAcesso['id_usuario'], $this->criarPerfilSemAgenda(), 'campus', $this->campus);
        $this->como($semAcesso)->get('agendamento/reservas')->assertStatus(403);
    }

    private function criarPerfilSemAgenda(): string
    {
        $this->criarPerfil('Só institucional', ['institucional.acesso' => 'leitor']);

        return 'Só institucional';
    }
}

<?php

use App\Libraries\Notificacoes\EnvioEmails;
use App\Libraries\Notificacoes\Mensagem;
use App\Libraries\Notificacoes\Notificador;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\Mock\MockEmail;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Email;
use Tests\Support\FeatureBancoTestCase;

/**
 * Notificações: texto montado na leitura (três idiomas), caixa individual, preferências com termo de ciência,
 * fila de e-mail e os avisos dos fluxos de reserva, bloqueio, alocação e chamado.
 *
 * @internal
 */
final class NotificacoesTest extends FeatureBancoTestCase
{
    use StreamFilterTrait;

    private int $campus;
    private int $laboratorio;
    private string $segunda;

    /** @var array<string, mixed> */
    private array $docente;

    /** @var array<string, mixed> */
    private array $docenteB;

    /** @var array<string, mixed> confirma reservas, resolve chamados (Auxiliares) */
    private array $auxiliar;

    /** @var array<string, mixed> Coordenação: reserva para terceiros, bloqueia, aloca */
    private array $coordenador;

    /** @var list<array<string, mixed>> */
    private array $enviados = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus      = $this->criarCampus('Campus A');
        $this->laboratorio = $this->criarAmbiente($this->criarPredio($this->campus, 'P1'), '101', 'Laboratório 1');
        $this->segunda     = (new DateTimeImmutable('monday next week'))->modify('+7 days')->format('Y-m-d');

        $this->docente = $this->criarUsuario(['nome' => 'Professora Ana', 'email' => 'ana@fatec.sp.gov.br']);
        $this->vincular($this->docente['id_usuario'], 'Docentes', 'campus', $this->campus);
        $this->docenteB = $this->criarUsuario(['nome' => 'Professor Beto', 'email' => 'beto@fatec.sp.gov.br']);
        $this->vincular($this->docenteB['id_usuario'], 'Docentes', 'campus', $this->campus);
        $this->auxiliar = $this->criarUsuario(['nome' => 'Auxiliar Caio']);
        $this->vincular($this->auxiliar['id_usuario'], 'Auxiliares', 'campus', $this->campus);
        $this->coordenador = $this->criarUsuario(['nome' => 'Coordenadora Dora']);
        $this->vincular($this->coordenador['id_usuario'], 'Coordenação', 'campus', $this->campus);

        Events::on('email', function (array $email): void {
            $this->enviados[] = $email;
        });
    }

    protected function tearDown(): void
    {
        Events::removeAllListeners('email');

        parent::tearDown();
    }

    /** @return list<string> chaves das notificações do usuário, em ordem */
    private function chaves(array $usuario): array
    {
        $linhas = $this->db->query(
            'SELECT t.chave FROM notificacoes n JOIN tipos_notificacoes t ON t.id_tipo_notificacao = n.tipo_notificacao_id
             WHERE n.usuario_id = ? ORDER BY n.id_notificacao',
            [$usuario['id_usuario']]
        )->getResultArray();

        return array_column($linhas, 'chave');
    }

    private function avisar(array $usuario, ?string $criadoEm = null): int
    {
        (new Notificador($this->db))->notificar([(int) $usuario['id_usuario']], 'descarte_aprovado', 'descarte', 1, [
            'item' => 'FJ-1 — Notebook', 'autor' => 'Alguém',
        ]);
        $id = (int) $this->db->insertID();

        if ($criadoEm !== null) {
            $this->db->table('notificacoes')->where('id_notificacao', $id)->update(['criado_em' => $criadoEm]);
        }

        return $id;
    }

    private function enviar(): array
    {
        return (new EnvioEmails($this->db))->enviar(new MockEmail(config(Email::class)));
    }

    public function testTextoMontadoNaLeituraNoIdiomaDeQuemLe(): void
    {
        $parametros = [
            'autor' => 'Dora', 'ambiente' => '101 Lab · P1 · FJ', 'tipo_bloqueio' => 'manutencao',
            'motivo' => 'Troca da rede', 'inicio' => '2026-10-05 07:00:00', 'fim' => '2026-10-06 18:00:00',
        ];

        $this->assertSame(
            'Dora bloqueou 101 Lab · P1 · FJ de 05/10/2026 07:00 a 06/10/2026 18:00 (Manutenção): Troca da rede. Reservas e aulas nesse período foram afetadas.',
            Mensagem::texto('bloqueio_ambiente', $parametros, 'pt-BR')
        );
        $this->assertStringContainsString('(Maintenance): Troca da rede', Mensagem::texto('bloqueio_ambiente', $parametros, 'en'));
        $this->assertSame('Ambiente bloqueado', Mensagem::titulo('bloqueio_ambiente', [], 'es'));

        $serie = ['autor' => 'Dora', 'ambiente' => 'Lab', 'titulo' => 'Aula', 'inicio' => '2026-10-05 07:00:00'];
        $this->assertStringEndsWith('07:00 (3 ocorrências).', Mensagem::texto('reserva_em_seu_nome', $serie + ['quantidade' => 3], 'pt-BR'));
        $this->assertStringEndsWith('07:00.', Mensagem::texto('reserva_em_seu_nome', $serie + ['quantidade' => 1], 'pt-BR'));
    }

    public function testQuemFezAAcaoInativosERepetidosNaoRecebem(): void
    {
        $inativo = $this->criarUsuario(['ativo' => 0]);

        $total = (new Notificador($this->db))->notificar(
            [(int) $this->docente['id_usuario'], (int) $this->docente['id_usuario'], (int) $inativo['id_usuario'], null, (int) $this->auxiliar['id_usuario']],
            'descarte_aprovado',
            null,
            null,
            [],
            (int) $this->auxiliar['id_usuario']
        );

        $this->assertSame(1, $total);
        $this->assertSame(['descarte_aprovado'], $this->chaves($this->docente));
    }

    public function testCadaUmSoVeEAbreAsSuas(): void
    {
        $id = $this->avisar($this->docente);

        $this->como($this->docenteB)->get("notificacoes/{$id}")->assertRedirectTo(site_url('notificacoes'));
        $this->seeInDatabase('notificacoes', ['id_notificacao' => $id, 'lida' => 0]);
        $this->como($this->docenteB)->get('notificacoes')->assertDontSee('FJ-1 — Notebook');

        $this->como($this->docente)->get('notificacoes')->assertSee('FJ-1 — Notebook');
        $this->como(null)->get('notificacoes')->assertRedirectTo(site_url('entrar'));

        $this->avisar($this->docente);
        $this->como($this->docente)->post('notificacoes/lidas')->assertRedirectTo(site_url('notificacoes'));
        $this->assertSame(0, $this->db->table('notificacoes')->where('lida', 0)->countAllResults());
    }

    public function testDesativarEmailExigeTermoDeCiencia(): void
    {
        $this->como($this->docente)->get('notificacoes/preferencias')->assertSee('Receber as notificações também por e-mail');

        // Sem aceitar o termo: recusa e nada muda.
        $this->como($this->docente)->post('notificacoes/preferencias', [])->assertStatus(422);
        $this->assertSame(0, $this->db->table('preferencias_notificacao')->countAllResults());

        $this->como($this->docente)->post('notificacoes/preferencias', ['ciencia' => '1'])->assertRedirect();
        $linha = $this->db->table('preferencias_notificacao')->get()->getRowArray();
        $this->assertSame(0, (int) $linha['canal_email_ativo']);
        $this->assertSame('1', $linha['termo_ciencia_versao']);
        $this->assertNotNull($linha['termo_ciencia_aceito_em']);
        $this->como($this->docente)->get('notificacoes/preferencias')->assertSee('E-mail desativado em');

        // Com o e-mail desativado, a notificação continua no sistema, mas não sai por e-mail.
        $this->avisar($this->docente);
        $this->avisar($this->docenteB);
        $this->assertSame(['enviadas' => 1, 'falhas' => 0], $this->enviar());
        $this->assertSame(['beto@fatec.sp.gov.br'], (array) $this->enviados[0]['recipients']);

        // Reativar é livre; o que ainda está na janela de envio sai.
        $this->como($this->docente)->post('notificacoes/preferencias', ['email' => '1'])->assertRedirect();
        $this->seeInDatabase('preferencias_notificacao', ['usuario_id' => $this->docente['id_usuario'], 'canal_email_ativo' => 1]);
        $this->assertSame(['enviadas' => 1, 'falhas' => 0], $this->enviar());
    }

    public function testFilaDeEmailRespeitaAJanelaEOComandoInforma(): void
    {
        $this->avisar($this->docente, date('Y-m-d H:i:s', strtotime('-3 days')));
        $this->assertSame(['enviadas' => 0, 'falhas' => 0], $this->enviar());

        $this->avisar($this->docente);
        command('notificacoes:enviar');
        $this->assertStringContainsString('E-mails enviados: 1. Falhas: 0.', $this->getStreamFilterBuffer());
        $this->assertSame(0, $this->db->table('notificacoes')->where('enviada_email', 0)->where('criado_em >', date('Y-m-d H:i:s', strtotime('-1 hour')))->countAllResults());
    }

    public function testReservaDeTerceirosFilaECancelamentoAvisamOsEnvolvidos(): void
    {
        $this->como($this->coordenador)->post("campus/{$this->campus}/configuracoes", [
            'exige_confirmacao_reserva' => '1', 'fila_confirmacao_ativa' => '1', 'confirmador_pode_confirmar_propria' => '1',
        ])->assertRedirect();

        $reservar = fn (array $quem, string $inicio, string $fim, array $extra = []) => $this->como($quem)->post('agendamento/reservas', $extra + [
            'data' => $this->segunda, 'inicio' => $inicio, 'fim' => $fim, 'ambiente_id' => (string) $this->laboratorio,
            'titulo' => 'Reunião de ' . $quem['nome'],
        ])->assertRedirect();

        // Coordenação reserva em nome do Beto: ele é avisado; quem confirma (Auxiliares) vê o pedido pendente.
        $reservar($this->coordenador, '14:00', '15:00', ['beneficiario_id' => (string) $this->docenteB['id_usuario']]);
        $this->assertSame(['reserva_em_seu_nome'], $this->chaves($this->docenteB));
        $this->assertSame(['reserva_pendente'], $this->chaves($this->auxiliar));
        $this->assertSame([], $this->chaves($this->coordenador));
        $daCoordenacao = (int) $this->db->table('reservas_pontuais')->orderBy('id_reserva_pontual', 'DESC')->get()->getRow()->id_reserva_pontual;

        // Ana pede o mesmo horário; o Caio confirma a da Ana e a outra cai pela fila.
        $reservar($this->docente, '14:30', '15:30');
        $daAna = (int) $this->db->table('reservas_pontuais')->orderBy('id_reserva_pontual', 'DESC')->get()->getRow()->id_reserva_pontual;
        $this->como($this->auxiliar)->post("agendamento/reservas/{$daAna}/confirmar")->assertRedirect();

        $this->assertSame(['reserva_confirmada'], $this->chaves($this->docente));
        $this->assertSame(['reserva_em_seu_nome', 'reserva_cancelada_fila'], $this->chaves($this->docenteB));
        // A Coordenação também confirma reservas: foi avisada do pedido da Ana (o dela mesma, não).
        $this->assertSame(['reserva_pendente', 'reserva_cancelada_fila'], $this->chaves($this->coordenador));
        $this->seeInDatabase('logs_auditoria', ['tabela_afetada' => 'reservas_pontuais', 'registro_id' => $daCoordenacao, 'acao' => 'atualizacao']);

        // Quem cancela a própria não é avisado; o outro envolvido é.
        $this->como($this->docente)->post("agendamento/reservas/{$daAna}/cancelar", ['justificativa' => 'Mudou a data'])->assertRedirect();
        $this->assertSame(['reserva_confirmada'], $this->chaves($this->docente));
    }

    public function testBloqueioAvisaQuemReservouEOProfessorDaAlocacaoUmaVezSo(): void
    {
        $this->db->table('semestres')->insert([
            'nome' => 'Semestre', 'data_inicio' => date('Y-m-d', strtotime('-30 days')),
            'data_fim' => date('Y-m-d', strtotime('+180 days')), 'status' => 'aberto',
        ]);
        $semestre = (int) $this->db->insertID();

        $vinculo = $this->vincularProfessor($semestre, $this->criarDisciplina('ALG', 'Algoritmos'), $this->docente['id_usuario']);

        $this->como($this->coordenador)->post('agendamento/alocacoes', [
            'semestre_id' => (string) $semestre, 'vinculo_id' => (string) $vinculo,
            'ambiente_id' => (string) $this->laboratorio,
            'dia_semana' => 'segunda', 'hora_inicio' => '08:00', 'hora_fim' => '10:00',
        ])->assertRedirect();
        $this->assertSame(['alocacao_criada'], $this->chaves($this->docente));

        $this->como($this->docente)->post('agendamento/reservas', [
            'data' => $this->segunda, 'inicio' => '14:00', 'fim' => '15:00', 'ambiente_id' => (string) $this->laboratorio,
            'titulo' => 'Reposição',
        ])->assertRedirect();

        $this->como($this->coordenador)->post('agendamento/bloqueios', [
            'ambiente_id' => (string) $this->laboratorio, 'tipo' => 'manutencao', 'motivo' => 'Troca da rede elétrica',
            'inicio' => $this->segunda . 'T07:00', 'fim' => $this->segunda . 'T18:00',
        ])->assertRedirect();

        $this->assertSame(['alocacao_criada', 'bloqueio_ambiente'], $this->chaves($this->docente));
        $caixa = $this->como($this->docente)->get('notificacoes');
        $caixa->assertSee('Coordenadora Dora bloqueou 101 Laboratório 1');
        $caixa->assertSee('(Manutenção): Troca da rede elétrica.');
    }

    public function testChamadoAvisaQuemResolveEQuemAbriu(): void
    {
        $this->como($this->docente)->post('chamados', [
            'ambiente_id' => (string) $this->laboratorio, 'descricao' => 'Faltam 5 computadores',
        ])->assertRedirect();
        $id = (int) $this->db->table('chamados')->get()->getRow()->id_chamado;

        $this->assertSame(['chamado_aberto'], $this->chaves($this->auxiliar));
        $this->assertSame(['chamado_aberto'], $this->chaves($this->coordenador));
        $this->assertSame([], $this->chaves($this->docente));

        $this->como($this->auxiliar)->post("chamados/{$id}/situacao", ['status' => 'em_andamento', 'observacao' => 'Pedido feito'])->assertRedirect();

        $this->assertSame(['chamado_status'], $this->chaves($this->docente));
        $this->como($this->docente)->get('notificacoes')->assertSee('passou para “Em andamento”, por Auxiliar Caio: Pedido feito');
    }
}

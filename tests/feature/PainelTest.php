<?php

use App\Libraries\Agendamento\Alocacoes;
use Tests\Support\FeatureBancoTestCase;

/**
 * Página inicial de quem está logado: pendências conforme a permissão e o escopo, agenda da pessoa e o
 * andamento do que ela pediu. Sem login, continua a apresentação pública.
 *
 * @internal
 */
final class PainelTest extends FeatureBancoTestCase
{
    private int $campus;
    private int $sala;
    private int $salaDeOutroCampus;

    /** @var array<string, mixed> */
    private array $docente;

    /** @var array<string, mixed> */
    private array $coordenacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus            = $this->criarCampus('Fatec Jahu');
        $this->sala              = $this->criarAmbiente($this->criarPredio($this->campus, 'Bloco A'), '101', 'Laboratório 1');
        $outroCampus             = $this->criarCampus('Fatec Bauru');
        $this->salaDeOutroCampus = $this->criarAmbiente($this->criarPredio($outroCampus, 'Bloco B'), '201', 'Sala 201');

        $this->docente = $this->criarUsuario(['nome' => 'Dora Docente']);
        $this->vincular($this->docente['id_usuario'], 'Docentes', 'campus', $this->campus);
        $this->coordenacao = $this->criarUsuario(['nome' => 'Carla Coordenadora']);
        $this->vincular($this->coordenacao['id_usuario'], 'Coordenação', 'campus', $this->campus);
    }

    public function testVisitanteEhLevadoDireitoAConsultaPublica(): void
    {
        $inicio = $this->como(null)->get('/');

        $inicio->assertRedirectTo(site_url('consulta'));
    }

    public function testDocenteVeAAulaDeHojeEADisciplinaSemRequisitos(): void
    {
        $semestre   = $this->semestreEmAndamento();
        $disciplina = $this->criarDisciplina('ALG', 'Algoritmos');
        $this->vincularProfessor($semestre, $disciplina, $this->docente['id_usuario']);
        $this->db->table('alocacoes_semestrais')->insert([
            'semestre_id'   => $semestre,
            'disciplina_id' => $disciplina,
            'professor_id'  => $this->docente['id_usuario'],
            'ambiente_id'   => $this->sala,
            'dia_semana'    => Alocacoes::diaDaSemana(new DateTimeImmutable('today')),
            'hora_inicio'   => '19:00:00',
            'hora_fim'      => '20:40:00',
        ]);

        $painel = $this->como($this->docente)->get('/');

        $painel->assertSee('Olá, Dora');
        $painel->assertSee('Semestre 2099/1');
        $painel->assertSee('ALG — Algoritmos');
        $painel->assertSee('19:00 às 20:40');
        $painel->assertSee('101 Laboratório 1');
        $painel->assertSee('Disciplinas suas sem requisitos cadastrados');
        $painel->assertDontSee('Reservas para confirmar');

        // Com os requisitos registrados, a pendência some; a aula de outro professor nunca aparece.
        $this->db->table('requisitos_disciplina')->insert([
            'professor_id' => $this->docente['id_usuario'], 'disciplina_id' => $disciplina,
        ]);

        $this->como($this->docente)->get('/')->assertDontSee('Disciplinas suas sem requisitos cadastrados');
        $this->como($this->coordenacao)->get('/')->assertDontSee('ALG — Algoritmos');
    }

    public function testAulaEmFeriadoViraAvisoDoDia(): void
    {
        $semestre   = $this->semestreEmAndamento();
        $disciplina = $this->criarDisciplina('ALG', 'Algoritmos');
        $this->db->table('alocacoes_semestrais')->insert([
            'semestre_id'   => $semestre,
            'disciplina_id' => $disciplina,
            'professor_id'  => $this->docente['id_usuario'],
            'ambiente_id'   => $this->sala,
            'dia_semana'    => Alocacoes::diaDaSemana(new DateTimeImmutable('today')),
            'hora_inicio'   => '19:00:00',
            'hora_fim'      => '20:40:00',
        ]);
        $this->db->table('feriados')->insert([
            'data_feriado' => date('Y-m-d'), 'descricao' => 'Aniversário da cidade', 'abrangencia' => 'nacional',
        ]);

        $painel = $this->como($this->docente)->get('/');

        $painel->assertSee('Feriado: Aniversário da cidade');
        $painel->assertDontSee('ALG — Algoritmos');
    }

    public function testFilaDeConfirmacaoRespeitaOEscopoDeQuemConfirma(): void
    {
        $this->reservar($this->sala, 'Banca de TCC', (int) $this->docente['id_usuario']);
        $this->reservar($this->salaDeOutroCampus, 'Evento de outro campus', (int) $this->criarUsuario()['id_usuario']);

        $painel = $this->como($this->coordenacao)->get('/');

        $painel->assertSee('Reservas para confirmar');
        $painel->assertSee(site_url('agendamento/confirmacoes'));
        $this->assertSame(1, $this->totalDaPendencia((string) $painel->getBody(), 'Reservas para confirmar'));

        // Quem pediu acompanha a própria reserva, sem ganhar a fila de quem confirma.
        $docente = $this->como($this->docente)->get('/');

        $docente->assertSee('Reservas aguardando decisão');
        $docente->assertSee('Banca de TCC');
        $docente->assertSee('Aguardando confirmação');
        $docente->assertDontSee('Reservas para confirmar');
        $docente->assertDontSee('Evento de outro campus');
    }

    public function testSemNadaPendenteOPainelDizIsso(): void
    {
        $semPerfil = $this->criarUsuario(['nome' => 'Nino Novato']);

        $painel = $this->como($semPerfil)->get('/');

        $painel->assertSee('Nada aguardando a sua ação agora.');
        $painel->assertSee('Nenhuma aula ou reserva hoje.');
        $painel->assertSee('Você não tem solicitações em andamento.');
        $painel->assertDontSee('Nova reserva');
    }

    private function semestreEmAndamento(): int
    {
        $this->db->table('semestres')->insert([
            'nome'        => '2099/1',
            'data_inicio' => date('Y-m-d', strtotime('-30 days')),
            'data_fim'    => date('Y-m-d', strtotime('+30 days')),
            'status'      => 'aberto',
        ]);

        return (int) $this->db->insertID();
    }

    /** Reserva aguardando confirmação, daqui a dois dias. */
    private function reservar(int $ambienteId, string $titulo, int $solicitanteId): void
    {
        $this->db->table('reservas_pontuais')->insert([
            'ambiente_id'      => $ambienteId,
            'solicitante_id'   => $solicitanteId,
            'titulo'           => $titulo,
            'data_hora_inicio' => date('Y-m-d 10:00:00', strtotime('+2 days')),
            'data_hora_fim'    => date('Y-m-d 12:00:00', strtotime('+2 days')),
            'status'           => 'pendente_confirmacao',
        ]);
    }

    /** Número do cartão de uma pendência (o número vem logo antes do rótulo, dentro do mesmo link). */
    private function totalDaPendencia(string $html, string $rotulo): int
    {
        $padrao = '/tabular-nums">(\d+)<\/span>\s*<span class="text-body-strong">' . preg_quote($rotulo, '/') . '</';

        $this->assertSame(1, preg_match($padrao, html_entity_decode($html), $achado), "Cartão não encontrado: {$rotulo}");

        return (int) $achado[1];
    }
}

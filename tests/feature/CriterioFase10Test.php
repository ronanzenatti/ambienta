<?php

use App\Libraries\Agendamento\Alocacoes as AlocacoesServico;
use App\Libraries\Agendamento\Recusa;
use App\Libraries\Chamados\Atendimento;
use Tests\Support\FeatureBancoTestCase;

/**
 * Critério de conclusão da Fase 10: professor vinculado no semestre cadastra requisitos com um software novo; o
 * Auxiliar o aprova; ao agendar, a sala mostra "parcial"; a confirmação abre o chamado sozinha, e o "ignorar" no
 * pontual a evita. Mais: vínculos professor-disciplina (criar, importar, exigidos na alocação) e o software
 * pendente fora do cálculo de atendimento.
 *
 * @internal
 */
final class CriterioFase10Test extends FeatureBancoTestCase
{
    private int $campus;
    private int $laboratorio;
    private int $semestre;
    private int $disciplina;
    private string $segunda;

    /** @var array<string, mixed> */
    private array $docente;

    /** @var array<string, mixed> */
    private array $coordenador;

    /** @var array<string, mixed> */
    private array $auxiliar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus      = $this->criarCampus('Campus A');
        $this->laboratorio = $this->criarAmbiente($this->criarPredio($this->campus, 'Prédio 1'), '101', 'Laboratório 1');
        $this->disciplina  = $this->criarDisciplina('ALG', 'Algoritmos');
        $this->segunda     = (new DateTimeImmutable('monday next week'))->modify('+7 days')->format('Y-m-d');
        $this->semestre    = $this->semestreAberto('Semestre de teste');

        $this->docente = $this->criarUsuario(['nome' => 'Professora Ana']);
        $this->vincular($this->docente['id_usuario'], 'Docentes', 'campus', $this->campus);
        $this->coordenador = $this->criarUsuario(['nome' => 'Coordenadora Dora']);
        $this->vincular($this->coordenador['id_usuario'], 'Coordenação', 'campus', $this->campus);
        $this->auxiliar = $this->criarUsuario(['nome' => 'Auxiliar Caio']);
        $this->vincular($this->auxiliar['id_usuario'], 'Auxiliares', 'campus', $this->campus);
    }

    private function semestreAberto(string $nome): int
    {
        $this->db->table('semestres')->insert([
            'nome'        => $nome,
            'data_inicio' => date('Y-m-d', strtotime('-30 days')),
            'data_fim'    => date('Y-m-d', strtotime('+180 days')),
            'status'      => 'aberto',
        ]);

        return (int) $this->db->insertID();
    }

    private function requisito(int $capacidade = 30): int
    {
        $this->db->table('requisitos_disciplina')->insert([
            'professor_id'      => $this->docente['id_usuario'],
            'disciplina_id'     => $this->disciplina,
            'capacidade_minima' => $capacidade,
        ]);

        return (int) $this->db->insertID();
    }

    private function software(string $nome, string $status = 'aprovado'): int
    {
        $this->db->table('softwares')->insert(['nome' => $nome, 'status' => $status]);

        return (int) $this->db->insertID();
    }

    private function exigir(int $requisito, int $software): void
    {
        $this->db->table('requisitos_disciplina_softwares')->insert([
            'requisito_disciplina_id' => $requisito,
            'software_id'             => $software,
        ]);
    }

    private function instalar(int $ambiente, int $software): void
    {
        $this->db->table('ambientes_softwares')->insert(['ambiente_id' => $ambiente, 'software_id' => $software]);
    }

    private function capacidade(int $ambiente, int $capacidade): void
    {
        $this->db->table('ambientes')->where('id_ambiente', $ambiente)->update(['capacidade' => $capacidade]);
    }

    /** @return list<string> chaves das notificações do usuário */
    private function avisos(array $usuario): array
    {
        $linhas = $this->db->table('notificacoes n')
            ->select('t.chave')
            ->join('tipos_notificacoes t', 't.id_tipo_notificacao = n.tipo_notificacao_id')
            ->where('n.usuario_id', $usuario['id_usuario'])
            ->orderBy('n.id_notificacao')
            ->get()
            ->getResultArray();

        return array_column($linhas, 'chave');
    }

    private function vinculosDo(int $semestre): int
    {
        return $this->db->table('professores_disciplinas')->where('semestre_id', $semestre)->countAllResults();
    }

    private function nivel(int $ambiente): string
    {
        return (new Atendimento($this->db))
            ->avaliar($this->docente['id_usuario'], $this->disciplina, $ambiente)['nivel'];
    }

    public function testVinculosCriamRecusamDuplicadoEImportam(): void
    {
        $this->como($this->docente)->get('atribuicoes/novo')->assertStatus(403);

        $dados = [
            'semestre_id'   => (string) $this->semestre,
            'disciplina_id' => (string) $this->disciplina,
            'professor_id'  => (string) $this->docente['id_usuario'],
        ];
        $this->como($this->coordenador)->post('atribuicoes', $dados)->assertRedirect();
        $this->seeInDatabase('professores_disciplinas', [
            'semestre_id' => $this->semestre, 'disciplina_id' => $this->disciplina,
            'professor_id' => $this->docente['id_usuario'], 'deletado_em' => null,
        ]);

        $repetido = $this->como($this->coordenador)->post('atribuicoes', $dados);
        $repetido->assertStatus(422);
        $repetido->assertSee('já tem esta disciplina atribuída');

        // Importar de outro semestre: copia uma vez e não duplica na segunda.
        $novo = $this->semestreAberto('Próximo semestre');
        $this->como($this->coordenador)->post('atribuicoes/importar', [
            'origem_id' => (string) $this->semestre, 'destino_id' => (string) $novo,
        ])->assertRedirect();
        $this->assertSame(1, $this->vinculosDo($novo));

        $this->como($this->coordenador)->post('atribuicoes/importar', [
            'origem_id' => (string) $this->semestre, 'destino_id' => (string) $novo,
        ])->assertRedirect();
        $this->assertSame(1, $this->vinculosDo($novo), 'a segunda importação não duplica');

        // Origem igual ao destino é recusada.
        $this->como($this->coordenador)->post('atribuicoes/importar', [
            'origem_id' => (string) $this->semestre, 'destino_id' => (string) $this->semestre,
        ])->assertStatus(422);

        // O professor só vê os próprios vínculos na listagem.
        $this->como($this->docente)->get('atribuicoes')->assertSee('Algoritmos');
    }

    public function testAlocacaoExigeOVinculoDoProfessorNoSemestre(): void
    {
        $this->expectException(Recusa::class);
        $this->expectExceptionMessage('Agendamento.teacherNotLinked');

        (new AlocacoesServico($this->db))->criar([
            'semestre_id' => $this->semestre, 'disciplina_id' => $this->disciplina,
            'professor_id' => $this->docente['id_usuario'], 'ambiente_id' => $this->laboratorio,
            'dia_semana' => 'segunda', 'hora_inicio' => '08:00:00', 'hora_fim' => '10:00:00',
        ], $this->coordenador['id_usuario'], false);
    }

    public function testAtendimentoIntegralParcialENaoComSoftwarePendenteForaDaConta(): void
    {
        $requisito = $this->requisito(30);
        $python    = $this->software('Python');
        $this->exigir($requisito, $python);

        $this->capacidade($this->laboratorio, 40);
        $this->instalar($this->laboratorio, $python);
        $this->assertSame(Atendimento::INTEGRAL, $this->nivel($this->laboratorio));

        // Capacidade insuficiente, mas com o software: falta metade.
        $this->capacidade($this->laboratorio, 20);
        $this->assertSame(Atendimento::PARCIAL, $this->nivel($this->laboratorio));

        // Software pendente (e o reprovado) não entram na conta: segue "parcial", não vira "não atende".
        $pendente = $this->software('Blender', 'pendente');
        $this->exigir($requisito, $pendente);
        $this->assertSame(Atendimento::PARCIAL, $this->nivel($this->laboratorio));

        // Sem o software aprovado e sem capacidade: não atende.
        $this->db->table('ambientes_softwares')->where('ambiente_id', $this->laboratorio)->delete();
        $this->assertSame(Atendimento::NAO, $this->nivel($this->laboratorio));

        // Aprovado, o software passa a contar (capacidade e Blender faltam, Python instalado): parcial.
        $this->db->table('softwares')->where('id_software', $pendente)->update(['status' => 'aprovado']);
        $this->instalar($this->laboratorio, $python);
        $this->assertSame(Atendimento::PARCIAL, $this->nivel($this->laboratorio));
    }

    public function testAlocacaoNaoIntegralAbreChamadoSozinhaEFicaPendenteDeAdequacao(): void
    {
        $this->requisito(30);
        $this->capacidade($this->laboratorio, 20);
        $vinculo = $this->vincularProfessor($this->semestre, $this->disciplina, $this->docente['id_usuario']);

        $this->como($this->coordenador)->post('agendamento/alocacoes', [
            'semestre_id' => (string) $this->semestre, 'vinculo_id' => (string) $vinculo,
            'ambiente_id' => (string) $this->laboratorio,
            'dia_semana' => 'segunda', 'hora_inicio' => '08:00', 'hora_fim' => '10:00',
        ])->assertRedirect();

        $alocacao = $this->db->table('alocacoes_semestrais')->get()->getRowArray();
        $this->assertSame('pendente_adequacao', $alocacao['status']);
        $this->seeInDatabase('chamados', [
            'alocacao_semestral_id' => $alocacao['id_alocacao_semestral'], 'status' => 'aberto',
        ]);

        // Atendimento integral: nenhum chamado novo.
        $this->capacidade($this->laboratorio, 40);
        $this->como($this->coordenador)->post('agendamento/alocacoes', [
            'semestre_id' => (string) $this->semestre, 'vinculo_id' => (string) $vinculo,
            'ambiente_id' => (string) $this->laboratorio,
            'dia_semana' => 'terca', 'hora_inicio' => '08:00', 'hora_fim' => '10:00',
        ])->assertRedirect();
        $this->assertSame(1, $this->db->table('chamados')->countAllResults());
    }

    public function testReservaPontualPerguntaAbreChamadoNaConfirmacaoEIgnorarEvita(): void
    {
        $this->vincularProfessor($this->semestre, $this->disciplina, $this->docente['id_usuario']);
        $python = $this->software('Python');
        $this->exigir($this->requisito(30), $python);
        $this->instalar($this->laboratorio, $python);
        $this->capacidade($this->laboratorio, 20);

        $corpo = [
            'data' => $this->segunda, 'inicio' => '14:00', 'fim' => '16:00', 'disciplina' => (string) $this->disciplina,
            'ambiente_id' => (string) $this->laboratorio, 'titulo' => 'Aula de reposição',
        ];

        // A sala atende só em parte: a primeira tentativa pergunta, sem reservar nem abrir chamado.
        $pergunta = $this->como($this->docente)->post('agendamento/reservas', $corpo);
        $pergunta->assertStatus(422);
        $pergunta->assertSee('Atende parcialmente');
        $pergunta->assertSee('Estou ciente');
        $this->assertSame(0, $this->db->table('reservas_pontuais')->countAllResults());

        // Ciente: reserva criada; o campus não exige confirmação, então já fica confirmada e abre o chamado.
        $this->como($this->docente)->post('agendamento/reservas', $corpo + ['ciente' => '1'])->assertRedirect();
        $reserva = $this->db->table('reservas_pontuais')->get()->getRowArray();
        $this->assertSame((int) $this->disciplina, (int) $reserva['disciplina_id']);
        $this->seeInDatabase('chamados', ['reserva_pontual_id' => $reserva['id_reserva_pontual'], 'status' => 'aberto']);

        // "Ignorar os requisitos": sem pergunta e sem chamado.
        $this->como($this->docente)->post('agendamento/reservas', [
            'inicio' => '17:00', 'fim' => '18:00', 'ignorar' => '1', 'titulo' => 'Outra aula',
        ] + $corpo)->assertRedirect();
        $this->assertSame(2, $this->db->table('reservas_pontuais')->countAllResults());
        $this->assertSame(1, $this->db->table('chamados')->countAllResults());
        $this->seeInDatabase('reservas_pontuais', ['titulo' => 'Outra aula', 'ignorar_requisitos' => 1]);
    }

    public function testReservaListaOAtendimentoDeCadaSalaQuandoHaDisciplina(): void
    {
        $this->vincularProfessor($this->semestre, $this->disciplina, $this->docente['id_usuario']);
        $requisito = $this->requisito(30);
        $this->exigir($requisito, $this->software('Python'));
        $this->capacidade($this->laboratorio, 40);

        $pagina = $this->como($this->docente)->get('agendamento/reservas/novo', [
            'data' => $this->segunda, 'inicio' => '14:00', 'fim' => '16:00', 'disciplina' => (string) $this->disciplina,
        ]);

        $pagina->assertOK();
        $pagina->assertSee('Atendimento aos requisitos da disciplina');
        $pagina->assertSee('Atende parcialmente');
    }

    public function testAtendimentoUsaOProfessorAtribuidoENaoQuemEstaAgendando(): void
    {
        $this->vincularProfessor($this->semestre, $this->disciplina, $this->docente['id_usuario']);
        $requisito = $this->requisito(30);
        $this->exigir($requisito, $this->software('Python'));
        $this->capacidade($this->laboratorio, 40);

        // Quem agenda não tem requisito nessa disciplina; vale o do professor atribuído no semestre da data.
        $pagina = $this->como($this->coordenador)->get('agendamento/reservas/novo', [
            'data' => $this->segunda, 'inicio' => '14:00', 'fim' => '16:00', 'disciplina' => (string) $this->disciplina,
        ]);

        $pagina->assertOK();
        $pagina->assertSee('Atende parcialmente');

        $atendimento = new Atendimento($this->db);
        $this->assertSame(
            (int) $this->docente['id_usuario'],
            $atendimento->professorDaDisciplina($this->disciplina, $this->segunda, (int) $this->coordenador['id_usuario'])
        );
        // Sem atribuição, volta a quem pediu.
        $this->assertSame(
            (int) $this->coordenador['id_usuario'],
            $atendimento->professorDaDisciplina($this->disciplina + 999, $this->segunda, (int) $this->coordenador['id_usuario'])
        );
    }

    public function testProfessorPropoeSoftwareAuxiliarAprovaReprovaOuMescla(): void
    {
        $this->vincularProfessor($this->semestre, $this->disciplina, $this->docente['id_usuario']);

        // Atribuições de aulas: lista a atribuição e abre (criando) o requisito permanente.
        $this->como($this->docente)->get('atribuicoes')->assertSee('Algoritmos');
        $this->como($this->docente)->get("atribuicoes/{$this->disciplina}/requisitos")->assertRedirect();
        $requisito = (int) $this->db->table('requisitos_disciplina')
            ->where('professor_id', $this->docente['id_usuario'])->get()->getRow()->id_requisito_disciplina;

        // Quem não está vinculado não abre requisito pela tela de atribuições.
        $outra = $this->criarDisciplina('BD', 'Banco de Dados');
        $this->como($this->docente)->get("atribuicoes/{$outra}/requisitos")->assertRedirectTo(site_url('atribuicoes'));

        // Propor um software novo: nasce pendente, entra no requisito e avisa quem aprova.
        $this->como($this->docente)->post("requisitos/{$requisito}/softwares/propor", [
            'nome' => 'Blender', 'versao' => '4.2', 'requisitos_tecnicos' => 'GPU dedicada',
        ])->assertRedirect();
        $blender = $this->db->table('softwares')->where('nome', 'Blender')->get()->getRowArray();
        $this->assertSame('pendente', $blender['status']);
        $this->assertSame((int) $this->docente['id_usuario'], (int) $blender['solicitado_por_id']);
        $this->seeInDatabase('requisitos_disciplina_softwares', [
            'requisito_disciplina_id' => $requisito, 'software_id' => $blender['id_software'], 'deletado_em' => null,
        ]);
        $this->assertSame(['software_pendente'], $this->avisos($this->auxiliar));

        // O professor não vê a fila nem decide.
        $this->como($this->docente)->get('patrimonio/softwares/pendentes')->assertStatus(403);
        $this->como($this->docente)->post("patrimonio/softwares/{$blender['id_software']}/aprovar")->assertStatus(403);

        // Reprovar exige o motivo; com ele, o professor é avisado.
        $this->como($this->auxiliar)->get('patrimonio/softwares/pendentes')->assertSee('Blender');
        $this->como($this->auxiliar)->post("patrimonio/softwares/{$blender['id_software']}/reprovar", ['motivo' => ''])
            ->assertStatus(422);
        $this->como($this->auxiliar)->post("patrimonio/softwares/{$blender['id_software']}/reprovar", ['motivo' => 'Sem licença'])
            ->assertRedirect();
        $this->seeInDatabase('softwares', ['id_software' => $blender['id_software'], 'status' => 'reprovado', 'motivo_reprovacao' => 'Sem licença']);
        $this->assertSame(['software_reprovado'], $this->avisos($this->docente));

        // Aprovar.
        $this->como($this->docente)->post("requisitos/{$requisito}/softwares/propor", ['nome' => 'GIMP'])->assertRedirect();
        $gimp = (int) $this->db->table('softwares')->where('nome', 'GIMP')->get()->getRow()->id_software;
        $this->como($this->auxiliar)->post("patrimonio/softwares/{$gimp}/aprovar")->assertRedirect();
        $this->seeInDatabase('softwares', ['id_software' => $gimp, 'status' => 'aprovado']);

        // Mesclar num software já aprovado: os requisitos passam a exigi-lo e o proposto sai.
        $this->como($this->docente)->post("requisitos/{$requisito}/softwares/propor", ['nome' => 'Python 3'])->assertRedirect();
        $proposto = (int) $this->db->table('softwares')->where('nome', 'Python 3')->get()->getRow()->id_software;
        $python   = $this->software('Python');

        $this->como($this->auxiliar)->post("patrimonio/softwares/{$proposto}/mesclar", ['destino_id' => (string) $proposto])
            ->assertStatus(422);
        $this->como($this->auxiliar)->post("patrimonio/softwares/{$proposto}/mesclar", ['destino_id' => (string) $python])
            ->assertRedirect();
        $this->seeInDatabase('requisitos_disciplina_softwares', [
            'requisito_disciplina_id' => $requisito, 'software_id' => $python, 'deletado_em' => null,
        ]);
        $this->assertNotNull($this->db->table('softwares')->where('id_software', $proposto)->get()->getRow()->deletado_em);
        $this->assertSame(['software_reprovado', 'software_mesclado'], $this->avisos($this->docente));

        // Já decidido: não se decide de novo.
        $this->como($this->auxiliar)->post("patrimonio/softwares/{$gimp}/aprovar")->assertRedirect();
        $this->assertSame(0, $this->db->table('softwares')->where('status', 'pendente')->where('deletado_em', null)->countAllResults());
    }
}

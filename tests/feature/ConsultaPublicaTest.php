<?php

use Tests\Support\FeatureBancoTestCase;

/**
 * Consulta pública, sem login: a semana (domingo a sábado) de um campus, com as aulas do semestre e as reservas
 * pontuais confirmadas, só com o primeiro nome do professor. As alocações e reservas são inseridas direto no
 * banco (as telas de alocação e reserva são da Fase 6).
 *
 * Semana de teste: quarta 2026-10-21 (domingo 18/10 a sábado 24/10), dentro do semestre 2026/2.
 *
 * @internal
 */
final class ConsultaPublicaTest extends FeatureBancoTestCase
{
    private const SEMANA = '?data=2026-10-21';

    private int $semestre;
    private int $curso;
    private int $disciplina;
    private int $ambiente;
    private int $campus;

    /** @var array<string, mixed> */
    private array $professor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus     = $this->criarCampus('Fatec Jahu');
        $predio           = $this->criarPredio($this->campus, 'Prédio Central');
        $this->ambiente   = $this->criarAmbiente($predio, 'LAB3', 'Laboratório 3');
        $this->semestre   = $this->criarSemestre('2026/2');
        $this->curso      = $this->criarCurso('Análise e Desenvolvimento de Sistemas');
        $this->disciplina = $this->criarDisciplina('IAL002', 'Algoritmos e Lógica de Programação', $this->curso);
        $this->professor  = $this->criarUsuario(['nome' => 'Marta Helena Ribeiro']);

        $this->db->table('cursos')->where('id_curso', $this->curso)->update(['abreviacao' => 'ADS']);
    }

    /** @param array<string, mixed> $extra */
    private function alocar(array $extra = []): int
    {
        $this->db->table('alocacoes_semestrais')->insert($extra + [
            'semestre_id'   => $this->semestre,
            'disciplina_id' => $this->disciplina,
            'professor_id'  => $this->professor['id_usuario'],
            'ambiente_id'   => $this->ambiente,
            'dia_semana'    => 'quarta',
            'hora_inicio'   => '07:40:00',
            'hora_fim'      => '09:20:00',
        ]);

        return (int) $this->db->insertID();
    }

    /** @param array<string, mixed> $extra */
    private function reservar(array $extra = []): int
    {
        $this->db->table('reservas_pontuais')->insert($extra + [
            'ambiente_id'     => $this->ambiente,
            'solicitante_id'  => $this->professor['id_usuario'],
            'titulo'          => 'Prova de recuperação',
            'data_hora_inicio' => '2026-10-22 19:00:00',
            'data_hora_fim'   => '2026-10-22 21:00:00',
            'status'          => 'confirmada',
        ]);

        return (int) $this->db->insertID();
    }

    /** Corpo da resposta com as entidades HTML decodificadas (o corpo do teste vem com acentos como entidade). */
    private function html(string $url): string
    {
        return html_entity_decode((string) $this->como(null)->get($url)->getBody(), ENT_QUOTES | ENT_HTML5);
    }

    public function testAbreSemLoginJaPreenchidoComOPrimeiroCampusPorNome(): void
    {
        $this->db->table('campus')->where('id_campus', $this->campus)->update(['cor_primaria' => '#7A1F2B', 'cor_texto' => 'claro']);
        $outro = $this->criarCampus('Fatec Bauru');
        $this->criarAmbiente($this->criarPredio($outro, 'Bloco B'), 'B101', 'Sala Bauru');

        $pagina = $this->como(null)->get('consulta' . self::SEMANA);

        $pagina->assertStatus(200);
        $pagina->assertSee('Consulta pública');
        $pagina->assertSee('Sala Bauru'); // Bauru vem antes de Jahu na ordem do select
        $pagina->assertDontSee('Laboratório 3');
        $this->assertStringContainsString('<option value="' . $outro . '" selected', (string) $pagina->getBody());
        $this->assertStringNotContainsString('--color-brand:', (string) $pagina->getBody(), 'visão acima de campus usa a identidade padrão');

        $this->como(null)->get('consulta?campus=' . $this->campus . '&' . ltrim(self::SEMANA, '?'))->assertSee('Laboratório 3');
        // Campus inexistente ou inativo cai no primeiro ativo.
        $this->como(null)->get('consulta?campus=999999&' . ltrim(self::SEMANA, '?'))->assertSee('Sala Bauru');
        $this->db->table('campus')->where('id_campus', $outro)->update(['ativo' => 0]);
        $this->como(null)->get('consulta?campus=' . $outro . '&' . ltrim(self::SEMANA, '?'))->assertSee('Laboratório 3');
    }

    public function testFiltrosGeraisFicamNumAcordeaoEmbaixoDoSeletorDeSemana(): void
    {
        $html = $this->html('consulta' . self::SEMANA);

        $this->assertStringContainsString('<details', $html);
        $semana = strpos($html, 'name="data"');
        $filtros = strpos($html, '<details');
        $this->assertNotFalse($semana);
        $this->assertGreaterThan($semana, $filtros, 'o seletor de semana fica fora (acima) do acordeão de filtros');
        $this->assertStringContainsString('18/10/2026 – 24/10/2026', $html);
    }

    public function testBlocoTemPeriodoCursoDisciplinaESoOPrimeiroNomeDoProfessor(): void
    {
        $this->alocar();

        $pagina = $this->como(null)->get('consulta' . self::SEMANA);

        $pagina->assertStatus(200);
        $pagina->assertSee('07:40 – 09:20');
        $pagina->assertSee('ADS - Algoritmos e Lógica de Programação');
        $pagina->assertSee('Marta');
        $pagina->assertSee('Recorrente · aula do semestre');
        $pagina->assertDontSee('Helena');
        $pagina->assertDontSee('Ribeiro');
    }

    public function testAsTresVisoesTemRotaPropriaEMostramOMesmoAgendamento(): void
    {
        $this->alocar();

        foreach (['consulta', 'consulta/turnos', 'consulta/resumo'] as $rota) {
            $pagina = $this->como(null)->get($rota . self::SEMANA);

            $pagina->assertStatus(200);
            $pagina->assertSee('Laboratório 3');
            // Um do menu do site e outro da visão atual.
            $this->assertSame(2, substr_count((string) $pagina->getBody(), 'aria-current="page"'), $rota . ': a visão atual vem marcada');
        }

        $this->como(null)->get('consulta/turnos' . self::SEMANA)->assertSee('ADS - Algoritmos e Lógica de Programação');
        $this->como(null)->get('consulta/resumo' . self::SEMANA)->assertSee('Ver semana completa');
    }

    public function testSegundaASextaSempreSabadoEDomingoSoComHorario(): void
    {
        $this->alocar();
        $html = $this->html('consulta' . self::SEMANA);

        // Uma aba por dia, com o total do dia no nome acessível.
        foreach (['Segunda-feira 19/10,', 'Terça-feira 20/10,', 'Quarta-feira 21/10,', 'Quinta-feira 22/10,', 'Sexta-feira 23/10,'] as $aba) {
            $this->assertStringContainsString($aba, $html);
        }
        $this->assertStringNotContainsString('Sábado 24/10,', $html);
        $this->assertStringNotContainsString('Domingo 18/10,', $html);

        $this->alocar(['dia_semana' => 'sabado']);
        $this->alocar(['dia_semana' => 'domingo', 'hora_inicio' => '08:00:00', 'hora_fim' => '10:00:00']);

        $html = $this->html('consulta/turnos' . self::SEMANA);
        $this->assertStringContainsString('Sábado', $html);
        $this->assertStringContainsString('Domingo', $html);

        $html = $this->html('consulta' . self::SEMANA);
        $this->assertStringContainsString('Sábado 24/10,', $html);
        $this->assertStringContainsString('Domingo 18/10,', $html);
    }

    public function testSemanaVemDoDiaEscolhidoDeDomingoASabadoEPadraoEHoje(): void
    {
        $this->alocar();
        $this->db->table('semestres')->where('id_semestre', $this->semestre)->update(['data_inicio' => '2026-10-22']);

        // A quarta 21/10 é antes do início do semestre; a quarta 28/10, na semana seguinte, já tem a aula.
        $this->como(null)->get('consulta?data=2026-10-18')->assertDontSee('07:40 – 09:20');
        $this->como(null)->get('consulta?data=2026-10-24')->assertDontSee('07:40 – 09:20');
        $this->como(null)->get('consulta?data=2026-10-25')->assertSee('07:40 – 09:20');
        $this->como(null)->get('consulta?data=2026-10-31')->assertSee('07:40 – 09:20');
        // Data inválida cai em hoje (a página abre normalmente).
        $this->como(null)->get('consulta?data=31/02/2026')->assertStatus(200);
        $hoje = (new DateTimeImmutable('today'))->format('Y-m-d');
        $this->como(null)->get('consulta')->assertSee($hoje);
    }

    public function testExcecaoDaAlocacaoTiraAAulaDaqueleDia(): void
    {
        $id = $this->alocar();
        $this->db->table('alocacoes_semestrais_excecoes')->insert([
            'alocacao_semestral_id' => $id,
            'data_excecao'          => '2026-10-21',
            'motivo'                => 'bloqueio_ambiente',
        ]);

        $this->como(null)->get('consulta' . self::SEMANA)->assertDontSee('07:40 – 09:20');
    }

    public function testReservaPontualConfirmadaApareceComDisciplinaEBeneficiarioSoPeloPrimeiroNome(): void
    {
        $beneficiario = $this->criarUsuario(['nome' => 'Carlos Alberto Quintanilha']);
        $this->reservar(['disciplina_id' => $this->disciplina, 'beneficiario_id' => $beneficiario['id_usuario']]);

        $pagina = $this->como(null)->get('consulta' . self::SEMANA);

        $pagina->assertSee('19:00 – 21:00');
        $pagina->assertSee('ADS - Algoritmos e Lógica de Programação');
        $pagina->assertSee('Carlos');
        $pagina->assertSee('Pontual · reserva única');
        $pagina->assertDontSee('Alberto');
        $pagina->assertDontSee('Quintanilha');
    }

    public function testReservaSemDisciplinaMostraOTituloEReservaRepetidaVemComoRecorrente(): void
    {
        $this->db->table('recorrencias')->insert(['tipo' => 'semanal', 'numero_ocorrencias' => 4]);
        $this->reservar(['recorrencia_id' => $this->db->insertID()]);

        $this->como(null)->get('consulta' . self::SEMANA)->assertSee('Prova de recuperação');
        // O tipo vai no modal de detalhes, montado no navegador a partir do JSON da página.
        $this->assertStringContainsString('Recorrente · reserva repetida (semanal)', $this->html('consulta' . self::SEMANA));
    }

    public function testReservaNaoConfirmadaOuCanceladaFicaDeFora(): void
    {
        $this->reservar(['status' => 'pendente_confirmacao']);
        $this->reservar([
            'status'                     => 'cancelada',
            'justificativa_cancelamento' => 'Teste',
            'cancelado_por_id'           => $this->professor['id_usuario'],
        ]);
        $this->reservar(['deletado_em' => date('Y-m-d H:i:s')]);

        $this->como(null)->get('consulta' . self::SEMANA)->assertDontSee('Prova de recuperação');
    }

    public function testReservaQuePassaDaMeiaNoiteViraUmBlocoPorDia(): void
    {
        $this->reservar(['data_hora_inicio' => '2026-10-22 22:00:00', 'data_hora_fim' => '2026-10-23 02:00:00']);

        $pagina = $this->como(null)->get('consulta' . self::SEMANA);

        $pagina->assertSee('22:00 – 24:00');
        $pagina->assertSee('00:00 – 02:00');
    }

    public function testFiltrosDeProfessorCursoDisciplinaETextoComCaracteresCuringaLiterais(): void
    {
        $this->alocar();
        $url = static fn (string $filtro): string => 'consulta' . self::SEMANA . '&' . $filtro;

        $this->como(null)->get($url('professor=mart'))->assertSee('Laboratório 3');
        $this->como(null)->get($url('professor=Ribeiro'))->assertDontSee('07:40 – 09:20');
        $this->como(null)->get($url('texto=lab3'))->assertSee('07:40 – 09:20');
        $this->como(null)->get($url('texto=Central'))->assertSee('07:40 – 09:20');
        $this->como(null)->get($url('curso=' . $this->curso . '&disciplina=' . $this->disciplina))->assertSee('07:40 – 09:20');
        $this->como(null)->get($url('curso=999999'))->assertDontSee('07:40 – 09:20');
        // % e _ não viram curinga: buscar "%" não traz tudo.
        $this->como(null)->get($url('texto=%25'))->assertDontSee('07:40 – 09:20');
        $this->como(null)->get($url('texto=_'))->assertDontSee('07:40 – 09:20');
    }

    public function testAmbientesFicamEmAcordeoesPorPredioTodosAbertos(): void
    {
        $this->criarAmbiente($this->criarPredio($this->campus, 'Bloco B'), 'B1', 'Sala do Bloco B');

        foreach (['consulta', 'consulta/turnos', 'consulta/resumo'] as $rota) {
            $html = $this->html($rota . self::SEMANA);

            $this->assertStringContainsString('Bloco B · 1 ambiente', $html, $rota);
            $this->assertStringContainsString('Prédio Central · 1 ambiente', $html, $rota);
            // Os dois blocos começam abertos; os filtros ficam num painel à parte (botão "Filtros"), não num acordeão.
            $this->assertSame(2, preg_match_all('/<details[^>]*\sopen/', $html), $rota);
            $this->assertSame(2, substr_count($html, '<details'), $rota);
            $this->assertStringContainsString('aria-controls="consulta-filtros"', $html, $rota);
            $this->assertLessThan(strpos($html, 'Sala do Bloco B'), strpos($html, 'Bloco B · 1 ambiente'), $rota);
        }
    }

    public function testComFiltroSoAparecemAmbientesComAgendamento(): void
    {
        $this->alocar();
        $this->criarAmbiente($this->db->table('predios')->get()->getRow()->id_predio, 'LAB9', 'Laboratório 9');

        $this->como(null)->get('consulta' . self::SEMANA)->assertSee('Laboratório 9');
        $this->como(null)->get('consulta' . self::SEMANA . '&texto=Algoritmos')->assertDontSee('Laboratório 9');
    }

    public function testAlocacaoCanceladaSemestrePlanejadoECampusInativoFicamDeFora(): void
    {
        $this->alocar(['status' => 'cancelada']);
        $this->alocar(['semestre_id' => $this->criarSemestre('2027/1', 'planejado'), 'dia_semana' => 'sexta']);

        $this->como(null)->get('consulta' . self::SEMANA)->assertDontSee('07:40 – 09:20');

        $this->alocar(['dia_semana' => 'segunda']);
        $this->como(null)->get('consulta' . self::SEMANA)->assertSee('07:40 – 09:20');

        $this->db->table('campus')->where('id_campus', $this->campus)->update(['ativo' => 0]);
        $this->como(null)->get('consulta' . self::SEMANA)->assertSee('Nenhum campus ativo');
    }

    public function testProfessorAnonimizadoNaoTemNomeExibidoNemEhEncontrado(): void
    {
        $this->db->table('usuarios')->where('id_usuario', $this->professor['id_usuario'])
            ->update(['nome' => 'Usuário removido (LGPD)', 'anonimizado_em' => date('Y-m-d H:i:s')]);
        $this->alocar();

        $pagina = $this->como(null)->get('consulta' . self::SEMANA);
        $pagina->assertSee('não informado');
        $pagina->assertDontSee('Usuário');

        $this->como(null)->get('consulta' . self::SEMANA . '&professor=Usu')->assertDontSee('07:40 – 09:20');
    }

    public function testTurnosUsamAConfiguracaoDoCampusOuOPadrao(): void
    {
        $this->alocar(); // manhã, 07:40
        $this->alocar(['hora_inicio' => '19:00:00', 'hora_fim' => '21:00:00']); // noite

        $manha = $this->como(null)->get('consulta/turnos' . self::SEMANA . '&turno=manha');
        $manha->assertSee('07:40 – 09:20');
        $manha->assertDontSee('19:00 – 21:00');
        $manha->assertSee('Manhã (7h–13h)'); // padrão: manhã até 13h, tarde até 18h, noite até 23h

        $noite = $this->como(null)->get('consulta/turnos' . self::SEMANA . '&turno=noite');
        $noite->assertSee('19:00 – 21:00');
        $noite->assertDontSee('07:40 – 09:20');

        $this->db->table('configuracoes_campus')->insert([
            'campus_id' => $this->campus, 'horario_fim_manha' => '12:00:00', 'horario_fim_tarde' => '17:30:00', 'horario_fim_noite' => '22:30:00',
        ]);
        $this->como(null)->get('consulta/turnos' . self::SEMANA . '&turno=tarde')->assertSee('Tarde (12h–17h30)');

        // Configuração fora de ordem volta ao padrão.
        $this->db->table('configuracoes_campus')->where('campus_id', $this->campus)->update(['horario_fim_manha' => '20:00:00']);
        $this->como(null)->get('consulta/turnos' . self::SEMANA . '&turno=tarde')->assertSee('Tarde (13h–18h)');
    }

    public function testResumoMarcaOcupacaoPorTurno(): void
    {
        $this->alocar();

        $pagina = $this->como(null)->get('consulta/resumo' . self::SEMANA);

        $pagina->assertSee('Manhã: Ocupado');
        $pagina->assertSee('Tarde: Livre');
        $pagina->assertSee('Noite: Livre');
    }

    public function testConteudoDigitadoEhEscapadoETraducaoSoNaInterface(): void
    {
        $this->db->table('disciplinas')->where('id_disciplina', $this->disciplina)->update(['nome' => '<script>alert(1)</script>']);
        $this->alocar();

        $html = (string) $this->como(null)->get('consulta' . self::SEMANA)->getBody();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);

        $emIngles = $this->como(null, ['idioma' => 'en'])->get('consulta' . self::SEMANA);
        $emIngles->assertSee('Recurring · semester class');
        $emIngles->assertSee('Laboratório 3'); // nome do ambiente nunca é traduzido
    }
}

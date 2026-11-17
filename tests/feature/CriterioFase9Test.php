<?php

use CodeIgniter\Exceptions\PageNotFoundException;
use Tests\Support\FeatureBancoTestCase;

/**
 * Fase 9: agenda pública do ambiente (destino do QR Code), QR Code e feeds iCal (do ambiente, público, e o
 * pessoal, protegido pelo segredo usuarios.token_ical). O público nunca vê mais que a consulta pública:
 * primeiro nome do professor, reserva confirmada como "Reservado", bloqueio sem motivo.
 *
 * @internal
 */
final class CriterioFase9Test extends FeatureBancoTestCase
{
    private int $campus;
    private int $ambiente;
    private string $segunda;

    /** @var array<string, mixed> */
    private array $professor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus   = $this->criarCampus('Campus A');
        $predio         = $this->criarPredio($this->campus, 'Prédio 1');
        $this->ambiente = $this->criarAmbiente($predio, '101', 'Laboratório 1');
        $this->segunda  = (new DateTimeImmutable('monday next week'))->format('Y-m-d');

        $this->professor = $this->criarUsuario(['nome' => 'Ana Beatriz Souza']);
        $this->vincular($this->professor['id_usuario'], 'Docentes', 'campus', $this->campus);

        $this->db->table('semestres')->insert([
            'nome'        => 'Semestre de teste',
            'data_inicio' => date('Y-m-d', strtotime('-30 days')),
            'data_fim'    => date('Y-m-d', strtotime('+120 days')),
            'status'      => 'aberto',
        ]);
        $semestre = (int) $this->db->insertID();

        $this->db->table('alocacoes_semestrais')->insert([
            'semestre_id'   => $semestre,
            'disciplina_id' => $this->criarDisciplina('ALG', 'Algoritmos'),
            'professor_id'  => $this->professor['id_usuario'],
            'ambiente_id'   => $this->ambiente,
            'dia_semana'    => 'segunda',
            'hora_inicio'   => '19:00:00',
            'hora_fim'      => '22:30:00',
        ]);

        $this->reserva('Banca de TCC confidencial', 'confirmada', '+1 day');
        $this->reserva('Reunião pendente', 'pendente_confirmacao', '+2 days');

        $this->db->table('bloqueios_ambientes')->insert([
            'ambiente_id'      => $this->ambiente,
            'tipo'             => 'manutencao',
            'motivo'           => 'Troca do ar-condicionado',
            'data_hora_inicio' => $this->dia('+3 days') . ' 08:00:00',
            'data_hora_fim'    => $this->dia('+3 days') . ' 12:00:00',
            'criado_por_id'    => $this->professor['id_usuario'],
        ]);
    }

    private function dia(string $passo): string
    {
        return (new DateTimeImmutable($this->segunda))->modify($passo)->format('Y-m-d');
    }

    private function reserva(string $titulo, string $status, string $passo): void
    {
        $this->db->table('reservas_pontuais')->insert([
            'ambiente_id'      => $this->ambiente,
            'solicitante_id'   => $this->professor['id_usuario'],
            'titulo'           => $titulo,
            'data_hora_inicio' => $this->dia($passo) . ' 14:00:00',
            'data_hora_fim'    => $this->dia($passo) . ' 16:00:00',
            'status'           => $status,
        ]);
    }

    public function testAgendaPublicaSemLoginMostraSoORecortePublico(): void
    {
        $resposta = $this->como(null)->get('agenda/ambiente/' . $this->ambiente . '?semana=' . $this->segunda);

        $resposta->assertOK();
        $resposta->assertSee('Algoritmos');
        $resposta->assertSee('Ana');
        $resposta->assertDontSee('Beatriz');
        $resposta->assertSee(lang('Agendamento.publicReserved'));
        $resposta->assertDontSee('Banca de TCC');
        $resposta->assertDontSee('Reunião pendente');
        $resposta->assertSee(lang('Agendamento.blockType_manutencao'));
        $resposta->assertDontSee('ar-condicionado');
    }

    public function testAmbienteExcluidoOuCampusInativoNaoApareceEmPublico(): void
    {
        $this->db->table('campus')->update(['ativo' => 0], ['id_campus' => $this->campus]);

        foreach (['', '/agenda.ics', '/qrcode.svg'] as $sufixo) {
            $this->assertTrue($this->naoEncontrado('agenda/ambiente/' . $this->ambiente . $sufixo), $sufixo);
        }
    }

    public function testQrCodeApontaParaAAgendaPublica(): void
    {
        $resposta = $this->como(null)->get('agenda/ambiente/' . $this->ambiente . '/qrcode.svg');

        $resposta->assertOK();
        $this->assertStringStartsWith('image/svg+xml', $resposta->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('<svg', $resposta->response()->getBody());

        $baixar = $this->como(null)->get('agenda/ambiente/' . $this->ambiente . '/qrcode.svg?baixar=1');
        $this->assertStringContainsString('attachment; filename="qrcode-ambiente-101.svg"', $baixar->response()->getHeaderLine('Content-Disposition'));
    }

    public function testPaginaDoAmbienteOfereceQrCode(): void
    {
        $diretor = $this->criarUsuario();
        $this->vincular($diretor['id_usuario'], 'Direção', 'global');

        $resposta = $this->como($diretor)->get('ambientes/' . $this->ambiente);

        $resposta->assertOK();
        $resposta->assertSee('agenda/ambiente/' . $this->ambiente . '/qrcode.svg?baixar=1');
    }

    public function testFeedDoAmbienteEPublicoESemDadosPessoais(): void
    {
        $resposta = $this->como(null)->get('agenda/ambiente/' . $this->ambiente . '/agenda.ics');
        $ics      = $this->desdobrar($resposta->response()->getBody());

        $resposta->assertOK();
        $this->assertStringStartsWith('text/calendar', $resposta->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('SUMMARY:ALG — Algoritmos', $ics);
        // 19:00 em São Paulo (UTC-3) = 22:00 UTC.
        $this->assertStringContainsString('DTSTART:' . str_replace('-', '', $this->segunda) . 'T220000Z', $ics);
        $this->assertStringContainsString('SUMMARY:' . lang('Agendamento.publicReserved'), $ics);
        $this->assertStringNotContainsString('Beatriz', $ics);
        $this->assertStringNotContainsString('Banca de TCC', $ics);
        $this->assertStringNotContainsString('Reunião pendente', $ics);
        $this->assertStringNotContainsString('ar-condicionado', $ics);
    }

    public function testFeriadoTiraAAulaDoFeed(): void
    {
        $this->db->table('feriados')->insert([
            'data_feriado' => $this->segunda,
            'descricao'    => 'Feriado de teste',
            'abrangencia'  => 'nacional',
            'origem'       => 'manual',
        ]);

        $ics = $this->desdobrar($this->como(null)->get('agenda/ambiente/' . $this->ambiente . '/agenda.ics')->response()->getBody());

        $this->assertStringNotContainsString('alocacao-' . $this->alocacaoId() . '-' . str_replace('-', '', $this->segunda), $ics);
        $this->assertStringContainsString('alocacao-' . $this->alocacaoId() . '-' . str_replace('-', '', $this->dia('+7 days')), $ics);
        $this->assertStringContainsString('Feriado de teste', $ics);
    }

    public function testFeedPessoalPeloLinkSecretoEGerarOutroRevogaOAnterior(): void
    {
        $this->como(null)->get('minha-agenda')->assertRedirect();

        $this->como($this->professor)->get('minha-agenda')->assertSee(lang('Agendamento.icalCreateLink'));
        $this->como($this->professor)->post('minha-agenda/link')->assertRedirectTo(site_url('minha-agenda'));
        $token = $this->token();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->como($this->professor)->get('minha-agenda')->assertSee('agenda/feed/' . $token . '/agenda.ics');

        $ics = $this->desdobrar($this->como(null)->get('agenda/feed/' . $token . '/agenda.ics')->response()->getBody());
        $this->assertStringContainsString('SUMMARY:ALG — Algoritmos', $ics);
        $this->assertStringContainsString('SUMMARY:Banca de TCC confidencial', $ics);
        $this->assertStringContainsString('SUMMARY:Reunião pendente', $ics);
        $this->assertStringContainsString('STATUS:TENTATIVE', $ics);

        $this->como($this->professor)->post('minha-agenda/link');
        $this->assertNotSame($token, $this->token());
        $this->assertTrue($this->naoEncontrado('agenda/feed/' . $token . '/agenda.ics'));
        $this->assertTrue($this->naoEncontrado('agenda/feed/' . str_repeat('0', 64) . '/agenda.ics'));

        $log = (string) $this->db->table('logs_auditoria')->select('GROUP_CONCAT(COALESCE(dados_novos, \'\')) AS t')->get()->getRow()->t;
        $this->assertStringNotContainsString($this->token(), $log);
    }

    /** O teste de requisição deixa a PageNotFoundException subir (em produção ela vira a página 404). */
    private function naoEncontrado(string $caminho): bool
    {
        try {
            $this->como(null)->get($caminho);
        } catch (PageNotFoundException) {
            return true;
        }

        return false;
    }

    private function token(): string
    {
        return (string) $this->db->table('usuarios')->where('id_usuario', $this->professor['id_usuario'])->get()->getRow()->token_ical;
    }

    private function alocacaoId(): int
    {
        return (int) $this->db->table('alocacoes_semestrais')->get()->getRow()->id_alocacao_semestral;
    }

    /** Desfaz o "folding" de linhas longas do iCal (RFC 5545 §3.1) para as asserções. */
    private function desdobrar(string $ics): string
    {
        return str_replace(["\r\n ", "\r\n\t"], '', $ics);
    }
}

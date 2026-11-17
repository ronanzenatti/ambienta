<?php

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\DownloadResponse;
use Tests\Support\FeatureBancoTestCase;

/**
 * Critério de conclusão da Fase 8: cada relatório abre e exporta (Excel e PDF) corretamente para um usuário de
 * escopo limitado (só o próprio campus) e para o superperfil (todos os campi).
 *
 * Cada campus ganha o mesmo conjunto de dados, marcado com o nome do campus ("Alfa", "Beta"), na semana de
 * 07/09/2026 (segunda, feriado nacional) a 13/09/2026.
 *
 * @internal
 */
final class CriterioFase8Test extends FeatureBancoTestCase
{
    private const PERIODO = ['de' => '2026-09-07', 'ate' => '2026-09-13'];

    private const RELATORIOS = [
        'inventario', 'itens-por-status', 'movimentacoes', 'descartes', 'ocupacao', 'chamados', 'cancelamentos',
    ];

    /** @var array<string, string> relatório => texto que só aparece nos dados do campus */
    private const MARCAS = [
        'inventario'       => 'Monitor {c}',
        'itens-por-status' => 'Campus {c}',
        'movimentacoes'    => 'Monitor {c}',
        'descartes'        => 'Tela quebrada {c}',
        'ocupacao'         => 'Laboratório {c}',
        'chamados'         => 'Chamado {c}',
        'cancelamentos'    => 'Reserva {c}',
    ];

    /** @var array<string, mixed> Coordenação no Campus Alfa */
    private array $coordenacao;

    /** @var array<string, mixed> superperfil */
    private array $direcao;

    /** @var array<string, mixed> sem relatorios.acesso */
    private array $docente;

    private int $campusBeta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->direcao = $this->criarUsuario(['nome' => 'Diretor Eli']);
        $this->vincular($this->direcao['id_usuario'], 'Direção');

        $alfa             = $this->montarCampus('Alfa');
        $this->campusBeta = $this->montarCampus('Beta');

        $this->coordenacao = $this->criarUsuario(['nome' => 'Coordenadora Dora']);
        $this->vincular($this->coordenacao['id_usuario'], 'Coordenação', 'campus', $alfa);
        $this->docente = $this->criarUsuario(['nome' => 'Professor Ivo']);
        $this->vincular($this->docente['id_usuario'], 'Docentes', 'campus', $alfa);

        // Feriado nacional na segunda-feira: sem aula nem horas disponíveis nesse dia.
        $this->db->table('feriados')->insert([
            'data_feriado' => '2026-09-07', 'descricao' => 'Independência', 'abrangencia' => 'nacional',
        ]);
    }

    /** Campus com prédio, laboratório, almoxarifado e um registro de cada relatório. */
    private function montarCampus(string $nome): int
    {
        $campus = $this->criarCampus('Campus ' . $nome);
        $predio = $this->criarPredio($campus, 'Prédio ' . $nome);
        $lab    = $this->criarAmbiente($predio, substr($nome, 0, 1) . '10', 'Laboratório ' . $nome);
        $almox  = $this->criarAlmoxarifado($campus);
        $modelo = $this->criarModelo('Monitor ' . $nome);
        $usuario = (int) $this->direcao['id_usuario'];

        $noLab    = $this->criarItem($modelo, $lab, null, 'FJ-' . $nome . '-1');
        $noAlmox  = $this->criarItem($modelo, null, $almox, 'FJ-' . $nome . '-2');
        $this->criarItem($this->criarModelo('Cabo ' . $nome, false), null, $almox, null, 30);

        $this->db->table('movimentacoes_itens')->insert([
            'item_id' => $noAlmox, 'origem_tipo' => 'ambiente', 'origem_ambiente_id' => $lab,
            'destino_tipo' => 'almoxarifado', 'destino_almoxarifado_id' => $almox,
            'remetente_id' => $usuario, 'destinatario_id' => $usuario, 'status' => 'confirmada',
            'data_envio' => '2026-09-08 09:00:00', 'data_confirmacao' => '2026-09-08 11:00:00',
        ]);
        $this->db->table('descartes')->insert([
            'item_id' => $noLab, 'solicitante_id' => $usuario, 'justificativa' => 'Tela quebrada ' . $nome,
            'motivo_descarte_id' => (int) $this->db->table('motivos_descarte')->get()->getRow()->id_motivo_descarte,
            'data_solicitacao' => '2026-09-09 10:00:00',
        ]);

        // Chamado concluído em 2 dias e 2 horas.
        $this->db->table('chamados')->insert([
            'ambiente_id' => $lab, 'aberto_por_id' => $usuario, 'status' => 'concluido',
            'descricao' => 'Chamado ' . $nome, 'criado_em' => '2026-09-08 10:00:00',
        ]);
        $chamado = (int) $this->db->insertID();
        $this->db->table('chamados_historico')->insertBatch([
            ['chamado_id' => $chamado, 'usuario_id' => $usuario, 'status_anterior' => null, 'status_novo' => 'aberto', 'observacao' => 'Abertura', 'criado_em' => '2026-09-08 10:00:00'],
            ['chamado_id' => $chamado, 'usuario_id' => $usuario, 'status_anterior' => 'aberto', 'status_novo' => 'concluido', 'observacao' => 'Pronto', 'criado_em' => '2026-09-10 12:00:00'],
        ]);

        // Ocupação: aula na segunda (feriado: não conta) e na quarta (2 h), reserva de 3 h, bloqueio de 2 h.
        $semestre = $this->db->table('semestres')->where('nome', '2026-2')->get()->getRow()->id_semestre
            ?? $this->criarSemestre('2026-2');
        $alocacao = [
            'semestre_id' => $semestre,
            'disciplina_id' => $this->criarDisciplina('D' . $nome, 'Disciplina ' . $nome),
            'professor_id' => $usuario, 'ambiente_id' => $lab,
        ];
        $this->db->table('alocacoes_semestrais')->insertBatch([
            $alocacao + ['dia_semana' => 'segunda', 'hora_inicio' => '19:00:00', 'hora_fim' => '21:00:00'],
            $alocacao + ['dia_semana' => 'quarta', 'hora_inicio' => '08:00:00', 'hora_fim' => '10:00:00'],
        ]);
        $this->db->table('reservas_pontuais')->insert([
            'ambiente_id' => $lab, 'solicitante_id' => $usuario, 'titulo' => 'Palestra ' . $nome, 'status' => 'confirmada',
            'data_hora_inicio' => '2026-09-10 10:00:00', 'data_hora_fim' => '2026-09-10 13:00:00',
        ]);
        $this->db->table('reservas_pontuais')->insertBatch([
            ['ambiente_id' => $lab, 'solicitante_id' => $usuario, 'titulo' => 'Reserva ' . $nome, 'status' => 'cancelada',
                'data_hora_inicio' => '2026-09-11 14:00:00', 'data_hora_fim' => '2026-09-11 16:00:00',
                'justificativa_cancelamento' => 'Evento adiado', 'cancelado_por_id' => $usuario,
                'data_cancelamento' => '2026-09-09 08:00:00'],
        ]);
        $this->db->table('bloqueios_ambientes')->insert([
            'ambiente_id' => $lab, 'tipo' => 'manutencao', 'motivo' => 'Pintura', 'criado_por_id' => $usuario,
            'data_hora_inicio' => '2026-09-11 05:00:00', 'data_hora_fim' => '2026-09-11 09:00:00',
        ]);

        return $campus;
    }

    private function marca(string $relatorio, string $campus): string
    {
        return str_replace('{c}', $campus, self::MARCAS[$relatorio]);
    }

    public function testEscopoLimitadoVeSoOProprioCampusEmTodosOsRelatorios(): void
    {
        $this->como($this->coordenacao)->get('relatorios')->assertOK();

        foreach (self::RELATORIOS as $relatorio) {
            $pagina = $this->como($this->coordenacao)->get("relatorios/{$relatorio}", self::PERIODO);
            $pagina->assertOK();
            $pagina->assertSee($this->marca($relatorio, 'Alfa'));
            $pagina->assertDontSee($this->marca($relatorio, 'Beta'));

            // Campus fora do escopo na URL: ignorado, nunca obedecido.
            $forcado = $this->como($this->coordenacao)->get(
                "relatorios/{$relatorio}",
                self::PERIODO + ['campus' => (string) $this->campusBeta]
            );
            $forcado->assertOK();
            $forcado->assertDontSee($this->marca($relatorio, 'Beta'));

            $planilha = $this->planilha($this->como($this->coordenacao), $relatorio);
            $this->assertStringContainsString($this->marca($relatorio, 'Alfa'), $planilha, $relatorio);
            $this->assertStringNotContainsString($this->marca($relatorio, 'Beta'), $planilha, $relatorio);

            $this->assertPdf($this->como($this->coordenacao), $relatorio);
        }
    }

    public function testSuperperfilVeTodosOsCampi(): void
    {
        foreach (self::RELATORIOS as $relatorio) {
            $pagina = $this->como($this->direcao)->get("relatorios/{$relatorio}", self::PERIODO);
            $pagina->assertOK();
            $pagina->assertSee($this->marca($relatorio, 'Alfa'));
            $pagina->assertSee($this->marca($relatorio, 'Beta'));

            $planilha = $this->planilha($this->como($this->direcao), $relatorio);
            $this->assertStringContainsString($this->marca($relatorio, 'Alfa'), $planilha, $relatorio);
            $this->assertStringContainsString($this->marca($relatorio, 'Beta'), $planilha, $relatorio);

            $this->assertPdf($this->como($this->direcao), $relatorio);
        }

        // O filtro de campus estreita o que o superperfil vê.
        $filtrado = $this->como($this->direcao)->get('relatorios/chamados', self::PERIODO + ['campus' => (string) $this->campusBeta]);
        $filtrado->assertSee('Chamado Beta');
        $filtrado->assertDontSee('Chamado Alfa');
    }

    public function testSemPermissaoNaoAcessa(): void
    {
        $this->como($this->docente)->get('relatorios')->assertStatus(403);
        $this->como($this->docente)->get('relatorios/inventario')->assertStatus(403);
        $this->como($this->docente)->get('relatorios/inventario/exportar', ['formato' => 'xlsx'])->assertStatus(403);

        $this->expectException(PageNotFoundException::class);
        $this->como($this->coordenacao)->get('relatorios/inexistente');
    }

    public function testValoresDaOcupacaoEDosChamados(): void
    {
        // Disponível: 4 dias × 16 h + sábado 6 h (segunda é feriado) = 70 h, menos 2 h de bloqueio na janela = 68 h.
        // Ocupado: aula de quarta (2 h) + reserva confirmada (3 h) = 5 h → 7,4 %.
        $ocupacao = $this->como($this->coordenacao)->get('relatorios/ocupacao', self::PERIODO);
        $ocupacao->assertSee('68,0');
        $ocupacao->assertSee('7,4 %');

        $chamados = $this->como($this->coordenacao)->get('relatorios/chamados', self::PERIODO);
        $chamados->assertSee('2 d 2 h');

        // Fora do período, a movimentação não aparece.
        $this->como($this->coordenacao)->get('relatorios/movimentacoes', ['de' => '2026-10-01', 'ate' => '2026-10-31'])
            ->assertDontSee('Monitor Alfa');

        // Itens por status agrupados por ambiente: o almoxarifado é um local à parte.
        $porAmbiente = $this->como($this->coordenacao)->get('relatorios/itens-por-status', ['agrupar' => 'ambiente']);
        $porAmbiente->assertSee('Laboratório Alfa');
        $porAmbiente->assertSee('Almoxarifado Central');

        // Busca e ordenação por coluna calculada (agregada) no recarregamento parcial.
        $parcial = $this->como($this->direcao)->get('relatorios/itens-por-status', [
            'q' => 'beta', 'ordem' => 'total', 'dir' => 'desc', 'parcial' => '1',
        ]);
        $parcial->assertOK();
        $parcial->assertSee('Campus Beta');
        $parcial->assertDontSee('Campus Alfa');
        $this->como($this->direcao)->get('relatorios/ocupacao', self::PERIODO + ['q' => 'alfa', 'ordem' => 'seg_disponiveis', 'parcial' => '1'])
            ->assertSee('Laboratório Alfa');
    }

    public function testFiltrosSeguemNaPaginacaoENaExportacao(): void
    {
        $html = (string) $this->como($this->direcao)
            ->get('relatorios/chamados', self::PERIODO + ['campus' => (string) $this->campusBeta])
            ->response()->getBody();

        $this->assertStringContainsString('name="campus" value="' . $this->campusBeta . '" data-filtro', $html);
        $this->assertStringContainsString('de=2026-09-07', html_entity_decode($html));
    }

    /** Conteúdo (XML) da planilha exportada, com todos os registros. */
    private function planilha(self $teste, string $relatorio): string
    {
        $resposta = $teste->get("relatorios/{$relatorio}/exportar", self::PERIODO + ['formato' => 'xlsx', 'abrangencia' => 'tudo'])->response();
        $this->assertInstanceOf(DownloadResponse::class, $resposta, $relatorio);

        $arquivo = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($arquivo, $this->conteudo($resposta));
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($arquivo));
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml') . $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();
        unlink($arquivo);

        return html_entity_decode($xml, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function assertPdf(self $teste, string $relatorio): void
    {
        $resposta = $teste->get("relatorios/{$relatorio}/exportar", self::PERIODO + ['formato' => 'pdf', 'abrangencia' => 'tudo'])->response();
        $this->assertInstanceOf(DownloadResponse::class, $resposta, $relatorio);
        $this->assertStringStartsWith('%PDF', $this->conteudo($resposta), $relatorio);
    }

    private function conteudo(DownloadResponse $resposta): string
    {
        ob_start();
        $resposta->sendBody();

        return (string) ob_get_clean();
    }
}

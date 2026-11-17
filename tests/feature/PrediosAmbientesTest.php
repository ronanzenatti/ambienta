<?php

use CodeIgniter\Config\Services;
use Tests\Support\FeatureBancoTestCase;
use Tests\Support\Libraries\ArquivosDeTeste;

/**
 * Prédios e ambientes: escopo (prédio sob o campus; ambiente sob o prédio), confirmação de reserva em
 * três estados, foto, identidade do campus nas páginas e exclusão com CONFIRMO bloqueada por dependentes.
 *
 * @internal
 */
final class PrediosAmbientesTest extends FeatureBancoTestCase
{
    /** @var array<string, mixed> */
    private array $direcao;

    private int $campus;
    private ArquivosDeTeste $arquivos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->direcao = $this->criarUsuario(['nome' => 'Diretora']);
        $this->vincular($this->direcao['id_usuario'], 'Direção');
        $this->campus = $this->criarCampus('Campus A');

        $this->arquivos = new ArquivosDeTeste();
        Services::injectMock('arquivos', $this->arquivos);
    }

    protected function tearDown(): void
    {
        ArquivosDeTeste::simularEnvio();
        $this->arquivos->limpar();
        Services::resetSingle('arquivos');

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function predio(array $extra = []): array
    {
        return $extra + ['nome' => 'Prédio Central', 'abreviacao' => 'CENTRAL', 'numero' => '1'];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function ambiente(int $categoria, array $extra = []): array
    {
        return $extra + [
            'categoria_ambiente_id'     => (string) $categoria,
            'numero'                    => '101',
            'abreviacao'                => 'LAB1',
            'nome'                      => 'Laboratório de Informática 1',
            'descricao'                 => '',
            'capacidade'                => '40',
            'tamanho_m2'                => '62.5',
            'exige_confirmacao_reserva' => '',
            'ativo'                     => '1',
        ];
    }

    public function testCriaPredioNoCampusComFotoEMostraNaHierarquia(): void
    {
        ArquivosDeTeste::simularEnvio(['foto' => 'png']);
        $this->como($this->direcao)->post("campus/{$this->campus}/predios", $this->predio());
        ArquivosDeTeste::simularEnvio();

        $predio = $this->db->table('predios')->where('nome', 'Prédio Central')->get()->getRowArray();
        $this->assertSame($this->campus, (int) $predio['campus_id']);
        $this->assertMatchesRegularExpression('#^predios/[0-9a-f]{32}\.png$#', (string) $predio['foto']);

        $this->como($this->direcao)->get("campus/{$this->campus}")->assertSee('Prédio Central');

        $pagina = $this->como($this->direcao)->get('predios/' . $predio['id_predio']);
        $pagina->assertStatus(200);
        $pagina->assertSee('Campus A');
        $pagina->assertSee('Nenhum ambiente cadastrado neste prédio.');
    }

    public function testAmbienteGravaCamposEConfirmacaoEmTresEstados(): void
    {
        $predio    = $this->criarPredio($this->campus, 'Prédio 1');
        $categoria = $this->criarCategoria('Laboratório');

        foreach (['' => null, '1' => '1', '0' => '0'] as $postado => $gravado) {
            $numero = 'S' . ($postado === '' ? 'x' : $postado);
            $this->como($this->direcao)->post("predios/{$predio}/ambientes", $this->ambiente($categoria, [
                'numero' => $numero, 'exige_confirmacao_reserva' => $postado,
            ]));

            $linha = $this->db->table('ambientes')->where('numero', $numero)->get()->getRowArray();
            $this->assertNotNull($linha, "ambiente {$numero} gravado");
            $this->assertSame($gravado, $linha['exige_confirmacao_reserva'], "confirmação '{$postado}'");
        }

        $ambiente = $this->db->table('ambientes')->where('numero', 'Sx')->get()->getRowArray();
        $this->assertSame('62.50', $ambiente['tamanho_m2']);
        $this->assertSame('40', (string) $ambiente['capacidade']);

        $pagina = $this->como($this->direcao)->get('ambientes/' . $ambiente['id_ambiente']);
        $pagina->assertStatus(200);
        $pagina->assertSee('Segue a configuração do campus');
        $pagina->assertSee('Laboratório');
        $this->assertStringContainsString('border-border-strong', (string) $pagina->getBody(), 'placa de ambiente');
    }

    public function testPredioAceitaMapaEmPdf(): void
    {
        ArquivosDeTeste::simularEnvio(['mapa' => 'pdf']);
        $this->como($this->direcao)->post("campus/{$this->campus}/predios", $this->predio());
        ArquivosDeTeste::simularEnvio();

        $predio = $this->db->table('predios')->where('nome', 'Prédio Central')->get()->getRowArray();
        $this->assertMatchesRegularExpression('#^predios/[0-9a-f]{32}\.pdf$#', (string) $predio['mapa']);
        $this->como($this->direcao)->get('predios/' . $predio['id_predio'])->assertSee('Abrir o mapa (PDF)');
    }

    public function testAmbienteValidaCategoriaCapacidadeETamanho(): void
    {
        $predio = $this->criarPredio($this->campus, 'Prédio 1');
        $categoria = $this->criarCategoria();

        $resposta = $this->como($this->direcao)->post("predios/{$predio}/ambientes", $this->ambiente($categoria, [
            'categoria_ambiente_id' => '999999', 'capacidade' => '-1', 'tamanho_m2' => 'grande', 'nome' => '',
        ]));

        $resposta->assertStatus(422);
        $resposta->assertSee('Escolha uma categoria existente.');
        $resposta->assertSee('Capacidade (pessoas) deve ser um número inteiro');
        $resposta->assertSee('Tamanho (m²) deve ser um número');
        $resposta->assertSee('Nome é obrigatório');
        $this->assertSame(0, $this->db->table('ambientes')->countAllResults());
    }

    public function testSemCategoriaOFormularioPedeParaCadastrarUmaAntes(): void
    {
        $predio = $this->criarPredio($this->campus, 'Prédio 1');

        $this->como($this->direcao)->get("predios/{$predio}/ambientes/novo")->assertSee('Cadastre ao menos uma categoria');
    }

    public function testEditorDePredioGereSoOsAmbientesDoSeuPredioENaoCriaNemExcluiPredio(): void
    {
        $meu     = $this->criarPredio($this->campus, 'Prédio Meu');
        $vizinho = $this->criarPredio($this->campus, 'Prédio Vizinho');
        $categoria = $this->criarCategoria();
        $noVizinho = $this->criarAmbiente($vizinho, '201', 'Sala 201', $categoria);

        $perfil = $this->criarPerfil('Gestor de prédio', ['institucional.acesso' => 'editor']);
        $gestor = $this->criarUsuario();
        $this->db->table('usuarios_perfis')->insert([
            'usuario_id' => $gestor['id_usuario'], 'perfil_id' => $perfil, 'escopo_tipo' => 'predio', 'predio_id' => $meu,
        ]);

        $this->como($gestor)->post("predios/{$meu}/ambientes", $this->ambiente($categoria))
            ->assertRedirect();
        $this->seeInDatabase('ambientes', ['predio_id' => $meu, 'numero' => '101']);

        $this->como($gestor)->post("predios/{$meu}", $this->predio(['nome' => 'Prédio Meu Renomeado']))->assertRedirect();
        $this->seeInDatabase('predios', ['id_predio' => $meu, 'nome' => 'Prédio Meu Renomeado']);

        $this->como($gestor)->get("predios/{$vizinho}")->assertStatus(403);
        $this->como($gestor)->post("predios/{$vizinho}/ambientes", $this->ambiente($categoria))->assertStatus(403);
        $this->como($gestor)->post("ambientes/{$noVizinho}", $this->ambiente($categoria, ['nome' => 'X']))->assertStatus(403);
        $this->como($gestor)->excluir("ambientes/{$noVizinho}")->assertStatus(403);

        // Prédio: criar e excluir exigem o campus inteiro.
        $this->como($gestor)->post("campus/{$this->campus}/predios", $this->predio(['nome' => 'Novo']))->assertStatus(403);
        $this->como($gestor)->excluir("predios/{$meu}")->assertStatus(403);
    }

    public function testExcluirPredioComAmbientesEhBloqueadoEAmbienteSemDependentesSai(): void
    {
        $predio   = $this->criarPredio($this->campus, 'Prédio 1');
        $ambiente = $this->criarAmbiente($predio, '101', 'Sala 101');

        $this->como($this->direcao)->excluir("predios/{$predio}")->assertRedirectTo(site_url("predios/{$predio}"));
        $this->assertStringContainsString('1 ambiente(s)', (string) $_SESSION['erro']);

        $this->como($this->direcao)->excluir("ambientes/{$ambiente}", 'sim');
        $this->seeInDatabase('ambientes', ['id_ambiente' => $ambiente, 'deletado_em' => null]);

        $this->como($this->direcao)->excluir("ambientes/{$ambiente}")->assertRedirectTo(site_url("predios/{$predio}"));
        $this->assertNotNull($this->db->table('ambientes')->where('id_ambiente', $ambiente)->get()->getRow()->deletado_em);

        $this->como($this->direcao)->excluir("predios/{$predio}")->assertRedirectTo(site_url("campus/{$this->campus}"));
        $this->assertNotNull($this->db->table('predios')->where('id_predio', $predio)->get()->getRow()->deletado_em);
    }

    public function testAmbienteComAlocacaoNaoPodeSerExcluido(): void
    {
        $predio   = $this->criarPredio($this->campus, 'Prédio 1');
        $ambiente = $this->criarAmbiente($predio, '101', 'Sala 101');
        $this->db->table('alocacoes_semestrais')->insert([
            'semestre_id'   => $this->criarSemestre('2026/2'),
            'disciplina_id' => $this->criarDisciplina('ILP', 'Lógica'),
            'professor_id'  => $this->criarUsuario()['id_usuario'],
            'ambiente_id'   => $ambiente,
            'dia_semana'    => 'segunda',
            'hora_inicio'   => '19:00:00',
            'hora_fim'      => '22:30:00',
        ]);

        $this->como($this->direcao)->excluir("ambientes/{$ambiente}");
        $this->assertStringContainsString('1 alocação(ões) semestral(is)', (string) $_SESSION['erro']);
        $this->seeInDatabase('ambientes', ['id_ambiente' => $ambiente, 'deletado_em' => null]);
    }

    public function testListasGeraisDePrediosEAmbientesFiltramPorCampusEPredioELevamAPaginaDoRegistro(): void
    {
        $campusB = $this->criarCampus('Campus B');
        $alfa    = $this->criarPredio($this->campus, 'Prédio Alfa');
        $beta    = $this->criarPredio($campusB, 'Prédio Beta');
        $salaA   = $this->criarAmbiente($alfa, '101', 'Sala Alfa');
        $this->criarAmbiente($beta, '201', 'Sala Beta');
        $corpo   = fn (string $rota, array $filtros = []): string => html_entity_decode(
            (string) $this->como($this->direcao)->get($rota, $filtros)->response()->getBody(),
            ENT_QUOTES | ENT_HTML5
        );

        // Prédios: todos, com o campus na linha e o nome levando à página do prédio; filtro por campus.
        $lista = $corpo('predios');
        $this->assertStringContainsString('Prédio Alfa', $lista);
        $this->assertStringContainsString('Prédio Beta', $lista);
        $this->assertStringContainsString('Campus B', $lista);
        $this->assertStringContainsString('href="' . site_url("predios/{$alfa}") . '"', $lista);
        $this->assertStringContainsString('href="' . site_url('ambientes') . '"', $lista, 'item no menu');

        $lista = $corpo('predios', ['campus' => (string) $campusB]);
        $this->assertStringContainsString('Prédio Beta', $lista);
        $this->assertStringNotContainsString('Prédio Alfa', $lista);

        // Ambientes: campus na 1ª linha e prédio na 2ª; filtros de campus e prédio.
        $lista = $corpo('ambientes');
        $this->assertStringContainsString('Sala Alfa', $lista);
        $this->assertStringContainsString('Sala Beta', $lista);
        $this->assertStringContainsString('href="' . site_url("ambientes/{$salaA}") . '"', $lista);
        $this->assertMatchesRegularExpression('#Campus B<br><span[^>]*>Prédio Beta</span>#', $lista);

        $lista = $corpo('ambientes', ['campus' => (string) $this->campus]);
        $this->assertStringContainsString('Sala Alfa', $lista);
        $this->assertStringNotContainsString('Sala Beta', $lista);

        $lista = $corpo('ambientes', ['campus' => (string) $campusB, 'predio' => (string) $beta]);
        $this->assertStringContainsString('Sala Beta', $lista);
        $this->assertStringNotContainsString('Sala Alfa', $lista);

        // Prédio de outro campus que o filtrado é ignorado (vale só o campus).
        $lista = $corpo('ambientes', ['campus' => (string) $this->campus, 'predio' => (string) $beta]);
        $this->assertStringContainsString('Sala Alfa', $lista);
        $this->assertStringNotContainsString('Sala Beta', $lista);

        // A exportação respeita o mesmo filtro.
        $this->como($this->direcao)->get('ambientes/exportar', ['campus' => (string) $campusB])->assertStatus(200);
    }

    public function testBotaoNovoSempreApareceEUsaModalDeEscolhaQuandoNaoHaFiltro(): void
    {
        $predio = $this->criarPredio($this->campus, 'Prédio Alfa');
        $corpo  = fn (array $usuario, string $rota, array $filtros = []): string => html_entity_decode(
            (string) $this->como($usuario)->get($rota, $filtros)->response()->getBody(),
            ENT_QUOTES | ENT_HTML5
        );

        // Sem filtro: o botão abre o modal que pergunta o campus (prédios) ou campus e prédio (ambientes).
        $lista = $corpo($this->direcao, 'predios');
        $this->assertStringContainsString('Novo prédio', $lista);
        $this->assertStringContainsString("open-modal', 'novo-predio'", $lista);
        $this->assertStringContainsString('name="escolha_campus"', $lista);
        $this->assertStringNotContainsString("campus/{$this->campus}/predios/novo", $lista);

        $lista = $corpo($this->direcao, 'ambientes');
        $this->assertStringContainsString('Novo ambiente', $lista);
        $this->assertStringContainsString("open-modal', 'novo-ambiente'", $lista);
        $this->assertStringContainsString('name="escolha_campus"', $lista);
        $this->assertStringContainsString('name="escolha_predio"', $lista);

        // Com o filtro escolhido: link direto, sem modal.
        $lista = $corpo($this->direcao, 'predios', ['campus' => (string) $this->campus]);
        $this->assertStringContainsString('href="' . site_url("campus/{$this->campus}/predios/novo") . '"', $lista);
        $this->assertStringNotContainsString('novo-predio-form', $lista);

        $lista = $corpo($this->direcao, 'ambientes', ['predio' => (string) $predio]);
        $this->assertStringContainsString('href="' . site_url("predios/{$predio}/ambientes/novo") . '"', $lista);
        $this->assertStringNotContainsString('novo-ambiente-form', $lista);

        // O modal segue para o formulário do registro escolhido; sem escolha, volta à lista com o aviso.
        $this->como($this->direcao)->get('predios/novo', ['escolha_campus' => (string) $this->campus])
            ->assertRedirectTo(site_url("campus/{$this->campus}/predios/novo"));
        $this->como($this->direcao)->get('ambientes/novo', ['escolha_predio' => (string) $predio])
            ->assertRedirectTo(site_url("predios/{$predio}/ambientes/novo"));
        $this->como($this->direcao)->get('predios/novo', ['escolha_campus' => ''])->assertRedirectTo(site_url('predios'));
        $this->como($this->direcao)->get('ambientes/novo')->assertRedirectTo(site_url('ambientes'));

        // Quem só consulta não vê o botão nem a rota de escolha.
        $perfil = $this->criarPerfil('Leitor global', ['institucional.acesso' => 'leitor']);
        $leitor = $this->criarUsuario();
        $this->db->table('usuarios_perfis')->insert([
            'usuario_id' => $leitor['id_usuario'], 'perfil_id' => $perfil, 'escopo_tipo' => 'global',
        ]);
        $this->assertStringNotContainsString('Novo prédio', $corpo($leitor, 'predios'));
        $this->assertStringNotContainsString('Novo ambiente', $corpo($leitor, 'ambientes'));
        $this->como($leitor)->get('predios/novo', ['escolha_campus' => (string) $this->campus])->assertStatus(403);
    }

    public function testListasGeraisSoMostramOQueOEscopoDoUsuarioAlcanca(): void
    {
        $campusB = $this->criarCampus('Campus B');
        $alfa    = $this->criarPredio($this->campus, 'Prédio Alfa');
        $vizinho = $this->criarPredio($this->campus, 'Prédio Vizinho');
        $beta    = $this->criarPredio($campusB, 'Prédio Beta');
        $this->criarAmbiente($alfa, '101', 'Sala Alfa');
        $this->criarAmbiente($vizinho, '102', 'Sala Vizinha');
        $this->criarAmbiente($beta, '201', 'Sala Beta');

        $perfil = $this->criarPerfil('Leitor de prédio', ['institucional.acesso' => 'leitor']);
        $leitor = $this->criarUsuario();
        $this->db->table('usuarios_perfis')->insert([
            'usuario_id' => $leitor['id_usuario'], 'perfil_id' => $perfil, 'escopo_tipo' => 'predio', 'predio_id' => $alfa,
        ]);

        $corpo = fn (string $rota, array $filtros = []): string => html_entity_decode(
            (string) $this->como($leitor)->get($rota, $filtros)->response()->getBody(),
            ENT_QUOTES | ENT_HTML5
        );

        $predios = $corpo('predios');
        $this->assertStringContainsString('Prédio Alfa', $predios);
        $this->assertStringNotContainsString('Prédio Vizinho', $predios);
        $this->assertStringNotContainsString('Prédio Beta', $predios);

        $ambientes = $corpo('ambientes');
        $this->assertStringContainsString('Sala Alfa', $ambientes);
        $this->assertStringNotContainsString('Sala Vizinha', $ambientes);
        $this->assertStringNotContainsString('Sala Beta', $ambientes);

        // Filtrar por um campus que o escopo não alcança não abre nada.
        $ambientes = $corpo('ambientes', ['campus' => (string) $campusB]);
        $this->assertStringNotContainsString('Sala Beta', $ambientes);
        $this->assertStringContainsString('Sala Alfa', $ambientes);
    }

    public function testPaginasDoPredioEDoAmbienteUsamAIdentidadeDoCampus(): void
    {
        $this->db->table('campus')->where('id_campus', $this->campus)->update(['cor_primaria' => '#F2C94C', 'cor_texto' => 'escuro']);
        $predio   = $this->criarPredio($this->campus, 'Prédio 1');
        $ambiente = $this->criarAmbiente($predio, '101', 'Sala 101');
        $estilo   = '--color-brand: #F2C94C; --color-brand-ink: #1B2430;';

        foreach (["predios/{$predio}", "ambientes/{$ambiente}"] as $rota) {
            $this->assertStringContainsString($estilo, (string) $this->como($this->direcao)->get($rota)->getBody(), $rota);
        }
    }
}

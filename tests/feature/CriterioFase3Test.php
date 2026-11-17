<?php

use CodeIgniter\Config\Services;
use Tests\Support\FeatureBancoTestCase;
use Tests\Support\Libraries\ArquivosDeTeste;

/**
 * Critério de conclusão da Fase 3: dá para cadastrar um campus com sua identidade visual, navegar pela
 * hierarquia campus → prédio → ambiente, e abrir a consulta pública sem estar logado. Tudo pelas telas,
 * com login de verdade pelo formulário.
 *
 * @internal
 */
final class CriterioFase3Test extends FeatureBancoTestCase
{
    private ArquivosDeTeste $arquivos;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function entrarComoDirecao(): void
    {
        $usuario = $this->criarUsuario(['nome' => 'Diretora Geral']);
        $this->vincular($usuario['id_usuario'], 'Direção');

        $this->como(null)->post('entrar', ['identificador' => $usuario['email'], 'senha' => self::SENHA])->assertRedirect();
        $sessao = $_SESSION;
        $this->reiniciarSessaoDoUsuario();
        $this->withSession($sessao);
    }

    /** Id do registro para onde a última gravação redirecionou (ex.: .../campus/7 → 7). */
    private function idDoRedirecionamento(\CodeIgniter\Test\TestResponse $resposta): int
    {
        $resposta->assertRedirect();
        preg_match('#/(\d+)$#', $resposta->getRedirectUrl() ?? '', $achado);

        return (int) ($achado[1] ?? 0);
    }

    public function testCampusComIdentidadeHierarquiaEConsultaPublica(): void
    {
        $this->entrarComoDirecao();

        // 1. Campus com identidade visual (cor, texto e logo).
        $this->get('campus/novo')->assertStatus(200);
        ArquivosDeTeste::simularEnvio(['logo' => 'png']);
        $campus = $this->idDoRedirecionamento($this->post('campus', [
            'nome' => 'Fatec Jahu', 'abreviacao' => 'FATEC-JAHU', 'uf' => 'SP', 'cidade' => 'Jaú',
            'cor_primaria' => '#8B1E3F', 'cor_texto' => 'claro', 'ativo' => '1',
        ]));
        ArquivosDeTeste::simularEnvio();
        $this->assertGreaterThan(0, $campus);

        // 2. Prédio no campus, categoria e ambiente no prédio.
        $predio = $this->idDoRedirecionamento($this->post("campus/{$campus}/predios", ['nome' => 'Prédio Central', 'abreviacao' => 'CENTRAL']));
        $this->post('categorias-ambiente', ['nome' => 'Laboratório'])->assertRedirect();
        $categoria = (int) $this->db->table('categorias_ambientes')->where('nome', 'Laboratório')->get()->getRow()->id_categoria_ambiente;
        $ambiente  = $this->idDoRedirecionamento($this->post("predios/{$predio}/ambientes", [
            'categoria_ambiente_id' => (string) $categoria, 'numero' => 'L03', 'abreviacao' => 'LAB3',
            'nome' => 'Laboratório 3', 'capacidade' => '40', 'exige_confirmacao_reserva' => '', 'ativo' => '1',
        ]));

        // 3. Navegação campus → prédio → ambiente, cada página ligando a próxima e com a identidade do campus.
        $identidade = 'style="--color-brand: #8B1E3F; --color-brand-ink: #FFFFFF;"';

        $lista = (string) $this->get('campus')->getBody();
        $this->assertStringContainsString('href="' . site_url("campus/{$campus}") . '"', $lista);

        $paginaCampus = (string) $this->get("campus/{$campus}")->getBody();
        $this->assertStringContainsString($identidade, $paginaCampus);
        $this->assertStringContainsString('uploads/campus/', $paginaCampus, 'o logo aparece na faixa do campus');
        $this->assertStringContainsString('href="' . site_url("predios/{$predio}") . '"', $paginaCampus);

        $paginaPredio = (string) $this->get("predios/{$predio}")->getBody();
        $this->assertStringContainsString($identidade, $paginaPredio);
        $this->assertStringContainsString('href="' . site_url("ambientes/{$ambiente}") . '"', $paginaPredio);
        $this->assertStringContainsString('href="' . site_url("campus/{$campus}") . '"', $paginaPredio, 'trilha volta ao campus');

        $paginaAmbiente = $this->get("ambientes/{$ambiente}");
        $paginaAmbiente->assertStatus(200);
        $paginaAmbiente->assertSee('Laboratório 3');
        $paginaAmbiente->assertSee('L03');
        $this->assertStringContainsString($identidade, (string) $paginaAmbiente->getBody());

        // 4. Consulta pública sem login, com link na página inicial.
        $this->post('sair')->assertRedirect();
        $this->reiniciarSessaoDoUsuario();

        $inicio = (string) $this->como(null)->get('/')->getBody();
        $this->assertStringContainsString('href="' . site_url('consulta') . '"', $inicio);

        $consulta = $this->como(null)->get('consulta');
        $consulta->assertStatus(200);
        $consulta->assertSee('Consulta pública');
        $this->assertStringNotContainsString('href="' . site_url('campus') . '"', (string) $consulta->getBody(), 'visitante não vê o menu institucional');
    }

    public function testMenuInstitucionalAparecePorPermissao(): void
    {
        $docente = $this->criarUsuario();
        $this->vincular($docente['id_usuario'], 'Docentes');
        $semPerfil = $this->criarUsuario();

        $link = 'href="' . site_url('campus') . '"';

        $this->assertStringContainsString($link, (string) $this->como($docente)->get('/')->getBody());
        $this->assertStringNotContainsString($link, (string) $this->como($semPerfil)->get('/')->getBody());
        $this->como($semPerfil)->get('campus')->assertStatus(403);
        $this->como(null)->get('campus')->assertRedirectTo(site_url('entrar'));
    }
}

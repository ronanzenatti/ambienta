<?php

use Tests\Support\FeatureBancoTestCase;

/**
 * Critério de conclusão da Fase 2: um usuário de cada perfil consegue logar e só vê o que a sua
 * permissão/escopo permite. Aqui o login é feito de verdade, pelo formulário.
 *
 * @internal
 */
final class CriterioFase2Test extends FeatureBancoTestCase
{
    /**
     * Cria o usuário, entra pelo formulário e deixa a sessão pronta para as próximas requisições.
     *
     * @return array<string, mixed>
     */
    private function usuarioLogadoComo(string $perfil, string $escopo = 'global', ?int $campusId = null, ?int $predioId = null): array
    {
        $usuario = $this->criarUsuario(['nome' => "Pessoa {$perfil}"]);
        $this->vincular($usuario['id_usuario'], $perfil, $escopo, $campusId, $predioId);

        $this->como(null)->post('entrar', ['identificador' => $usuario['email'], 'senha' => self::SENHA])->assertRedirect();
        $this->assertSame($usuario['id_usuario'], (int) $_SESSION['id_usuario'], "{$perfil} deveria ter logado");

        $sessao = $_SESSION;
        $this->reiniciarSessaoDoUsuario();
        $this->withSession($sessao);

        return $usuario;
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: list<string>}> perfil => [rotas 200, rotas 403]
     */
    public static function perfis(): array
    {
        return [
            'Direção'     => ['Direção', ['usuarios', 'usuarios/novo', 'perfis', 'perfis/novo', 'dominios'], []],
            'Coordenação' => ['Coordenação', ['usuarios'], ['usuarios/novo', 'perfis', 'perfis/novo', 'dominios']],
            'Auxiliares'  => ['Auxiliares', [], ['usuarios', 'usuarios/novo', 'perfis', 'dominios']],
            'Docentes'    => ['Docentes', [], ['usuarios', 'usuarios/novo', 'perfis', 'dominios']],
        ];
    }

    /**
     * @dataProvider perfis
     *
     * @param list<string> $permitidas
     * @param list<string> $negadas
     */
    public function testCadaPerfilAcessaSoOQueAsuaPermissaoPermite(string $perfil, array $permitidas, array $negadas): void
    {
        $this->usuarioLogadoComo($perfil);

        foreach ($permitidas as $rota) {
            $this->assertSame(200, $this->get($rota)->response()->getStatusCode(), "{$perfil} deveria abrir /{$rota}");
        }

        foreach ($negadas as $rota) {
            $this->assertSame(403, $this->get($rota)->response()->getStatusCode(), "{$perfil} não deveria abrir /{$rota}");
        }
    }

    /**
     * @dataProvider perfis
     *
     * @param list<string> $permitidas
     */
    public function testOMenuMostraSoOQueOPerfilPodeUsar(string $perfil, array $permitidas): void
    {
        $this->usuarioLogadoComo($perfil);

        $html = (string) $this->get('/')->response()->getBody();

        $menu = [
            '/usuarios' => in_array('usuarios', $permitidas, true),
            '/perfis'   => in_array('perfis', $permitidas, true),
            '/dominios' => in_array('dominios', $permitidas, true),
        ];

        foreach ($menu as $caminho => $deveAparecer) {
            $achou = str_contains($html, 'href="' . site_url(ltrim($caminho, '/')) . '"');
            $this->assertSame($deveAparecer, $achou, "{$perfil}: link {$caminho}");
        }

        $this->assertStringContainsString('Pessoa ' . $perfil, $html, 'o cabeçalho mostra quem está logado');
        $this->assertStringContainsString('action="' . site_url('sair') . '"', $html);
    }

    public function testCoordenacaoDeCampusVeSoOsUsuariosDoSeuCampus(): void
    {
        $campusA = $this->criarCampus('Campus A');
        $campusB = $this->criarCampus('Campus B');
        $doA     = $this->criarUsuario(['nome' => 'Docente do A']);
        $this->vincular($doA['id_usuario'], 'Docentes', 'campus', $campusA);
        $doB = $this->criarUsuario(['nome' => 'Docente do B']);
        $this->vincular($doB['id_usuario'], 'Docentes', 'campus', $campusB);

        $this->usuarioLogadoComo('Coordenação', 'campus', $campusA);

        $lista = $this->get('usuarios');
        $lista->assertStatus(200);
        $lista->assertSee('Docente do A');
        $lista->assertDontSee('Docente do B');
    }

    public function testDocenteComEscopoDePredioNaoGanhaAcessoAdministrativo(): void
    {
        $campus = $this->criarCampus('Campus A');
        $predio = $this->criarPredio($campus, 'Prédio A1');

        $this->usuarioLogadoComo('Docentes', 'predio', null, $predio);

        $this->get('usuarios')->assertStatus(403);
        $this->get('perfis')->assertStatus(403);
        // A página pública inicial continua aberta.
        $this->get('/')->assertStatus(200);
    }

    public function testDepoisDeSairAsAreasRestritasVoltamAExigirLogin(): void
    {
        $this->usuarioLogadoComo('Direção');
        $this->get('usuarios')->assertStatus(200);

        $this->post('sair')->assertRedirectTo(site_url('entrar'));

        $this->reiniciarSessaoDoUsuario();
        $this->withSession($_SESSION);
        $this->get('usuarios')->assertRedirectTo(site_url('entrar'));
    }

    public function testPaginaInicialEhPublicaEMostraEntrarParaVisitante(): void
    {
        $html = html_entity_decode((string) $this->como(null)->get('/')->response()->getBody(), ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('href="' . site_url('entrar') . '"', $html);
        $this->assertStringNotContainsString('href="' . site_url('usuarios') . '"', $html);
    }

    public function testSeletorDeIdiomaEstaNoCabecalhoComAcessibilidade(): void
    {
        $html = html_entity_decode((string) $this->como(null)->get('/')->response()->getBody(), ENT_QUOTES | ENT_HTML5);

        foreach (['pt-BR', 'en', 'es'] as $idioma) {
            $this->assertStringContainsString('name="idioma" value="' . $idioma . '"', $html);
        }
        $this->assertStringContainsString('aria-current="true"', $html, 'o idioma atual é marcado');
        $this->assertStringContainsString('aria-label="Idioma: Português"', $html, 'o botão anuncia o idioma atual');
    }
}

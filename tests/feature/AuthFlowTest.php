<?php

use CodeIgniter\Email\Email;
use Config\Google;
use Config\Services;
use League\OAuth2\Client\Provider\Google as GoogleProvider;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\Support\FeatureBancoTestCase;

/**
 * Login, logout, idioma, Google Workspace e recuperação de senha, pelas rotas reais.
 *
 * @internal
 */
final class AuthFlowTest extends FeatureBancoTestCase
{
    private function habilitarGoogle(): void
    {
        $config               = config(Google::class);
        $config->clientId     = 'id-de-teste';
        $config->clientSecret = 'segredo-de-teste';
    }

    /** @return array<string, mixed> sessão vigente depois da última requisição */
    private function sessao(): array
    {
        return $_SESSION ?? [];
    }

    public function testTelaDeLoginAbreParaVisitante(): void
    {
        $result = $this->como(null)->get('entrar');

        $result->assertStatus(200);
        $result->assertSee('Entrar no Ambienta');
        $result->assertSee('E-mail ou CPF');
        $result->assertDontSee('Entrar com Google Workspace');
    }

    public function testBotaoDoGoogleSoApareceComCredenciaisConfiguradas(): void
    {
        $this->habilitarGoogle();

        $this->como(null)->get('entrar')->assertSee('Entrar com Google Workspace');
    }

    public function testLoginPorEmailAbreASessaoERedirecionaParaAInicial(): void
    {
        $usuario = $this->criarUsuario(['email' => 'ana@fatec.sp.gov.br', 'idioma_preferido' => 'es']);

        $result = $this->como(null)->post('entrar', ['identificador' => 'ana@fatec.sp.gov.br', 'senha' => self::SENHA]);

        $result->assertRedirect();
        $this->assertSame($usuario['id_usuario'], (int) $this->sessao()['id_usuario']);
        $this->assertSame('es', $this->sessao()['idioma'], 'o idioma preferido vai para a sessão');
        $this->assertNotNull($this->db->table('usuarios')->where('id_usuario', $usuario['id_usuario'])->get()->getRow()->ultimo_acesso_em);
    }

    public function testLoginPorCpf(): void
    {
        $usuario = $this->criarUsuario(['cpf' => '52998224725']);

        $this->como(null)->post('entrar', ['identificador' => '529.982.247-25', 'senha' => self::SENHA])->assertRedirect();

        $this->assertSame($usuario['id_usuario'], (int) $this->sessao()['id_usuario']);
    }

    public function testFalhaNaoRevelaSeOCadastroExiste(): void
    {
        $this->criarUsuario(['email' => 'ana@fatec.sp.gov.br']);

        $senhaErrada = $this->como(null)->post('entrar', ['identificador' => 'ana@fatec.sp.gov.br', 'senha' => 'errada']);
        $semCadastro = $this->como(null)->post('entrar', ['identificador' => 'ninguem@fatec.sp.gov.br', 'senha' => 'errada']);

        foreach ([$senhaErrada, $semCadastro] as $result) {
            $result->assertStatus(401);
            $result->assertSee('E-mail/CPF ou senha incorretos.');
        }
        $this->assertArrayNotHasKey('id_usuario', $this->sessao());
    }

    public function testCamposVaziosNaoAutenticam(): void
    {
        $this->como(null)->post('entrar', ['identificador' => '', 'senha' => ''])->assertStatus(422);
    }

    public function testUsuarioInativoNaoEntra(): void
    {
        $this->criarUsuario(['email' => 'off@fatec.sp.gov.br', 'ativo' => 0]);

        $this->como(null)->post('entrar', ['identificador' => 'off@fatec.sp.gov.br', 'senha' => self::SENHA])->assertStatus(401);
    }

    public function testMuitasTentativasSeguidasSaoBloqueadas(): void
    {
        $this->criarUsuario(['email' => 'ana@fatec.sp.gov.br']);
        $status = [];

        for ($i = 0; $i < 7; $i++) {
            $status[] = $this->como(null)
                ->post('entrar', ['identificador' => 'ana@fatec.sp.gov.br', 'senha' => 'errada'])
                ->response()->getStatusCode();
        }

        $this->assertSame([401, 401, 401, 401, 401, 429, 429], $status);
    }

    public function testVoltaParaAPaginaPedidaDepoisDoLogin(): void
    {
        $usuario = $this->criarUsuario();
        $this->vincular($usuario['id_usuario'], 'Direção');

        $anonimo = $this->como(null)->get('usuarios');
        $anonimo->assertRedirectTo(site_url('entrar'));
        $this->assertSame('/usuarios', $this->sessao()['url_destino']);

        $login = $this->como(null, ['url_destino' => '/usuarios'])
            ->post('entrar', ['identificador' => $usuario['email'], 'senha' => self::SENHA]);

        $login->assertRedirectTo(site_url('usuarios'));
    }

    public function testDestinoDeOutroSiteEhIgnoradoNoLogin(): void
    {
        $usuario = $this->criarUsuario();

        foreach (['//evil.example/x', 'https://evil.example', '/\\evil.example'] as $destino) {
            $result = $this->como(null, ['url_destino' => $destino])
                ->post('entrar', ['identificador' => $usuario['email'], 'senha' => self::SENHA]);

            $result->assertRedirectTo(site_url('/'));
        }
    }

    public function testLogoutEncerraASessao(): void
    {
        $usuario = $this->criarUsuario();

        $this->como($usuario)->post('sair')->assertRedirectTo(site_url('entrar'));

        $this->assertArrayNotHasKey('id_usuario', $this->sessao());
    }

    public function testTrocaDeIdiomaValeNaSessaoEViraPreferenciaDoUsuarioLogado(): void
    {
        $usuario = $this->criarUsuario();

        $this->como($usuario)->post('idioma', ['idioma' => 'en'])->assertRedirect();

        $this->assertSame('en', $this->sessao()['idioma']);
        $this->seeInDatabase('usuarios', ['id_usuario' => $usuario['id_usuario'], 'idioma_preferido' => 'en']);
    }

    public function testIdiomaInvalidoEhIgnorado(): void
    {
        $usuario = $this->criarUsuario();

        $this->como($usuario)->post('idioma', ['idioma' => 'klingon'])->assertRedirect();

        $this->seeInDatabase('usuarios', ['id_usuario' => $usuario['id_usuario'], 'idioma_preferido' => 'pt-BR']);
    }

    public function testInterfaceSegueOIdiomaDaSessao(): void
    {
        $this->como(null, ['idioma' => 'en'])->get('entrar')->assertSee('Sign in to Ambienta');
        $this->como(null, ['idioma' => 'es'])->get('entrar')->assertSee('Ingresar a Ambienta');
        $this->como(null, ['idioma' => 'pt-BR'])->get('entrar')->assertSee('Entrar no Ambienta');
        // Sem sessão, o padrão (pt-BR).
        $this->como(null)->get('entrar')->assertSee('Entrar no Ambienta');
    }

    public function testConteudoCadastradoNuncaEhTraduzido(): void
    {
        $usuario = $this->criarUsuario(['nome' => 'Professora Ana']);
        $this->vincular($usuario['id_usuario'], 'Direção');

        $html = (string) $this->como($usuario, ['idioma' => 'en'])->get('usuarios')->response()->getBody();

        $this->assertStringContainsString('Users', $html);
        $this->assertStringContainsString('Professora Ana', $html);
        $this->assertStringContainsString('Direção', $html, 'nome de perfil é conteúdo cadastrado');
    }

    // ---------------------------------------------------------------- Google

    /**
     * @return MockObject&GoogleProvider
     */
    private function provedorGoogle(): MockObject
    {
        $provedor = $this->createMock(GoogleProvider::class);
        Services::injectMock('googleProvider', $provedor);

        return $provedor;
    }

    public function testIniciarGoogleSemCredenciaisVoltaAoLoginComAviso(): void
    {
        $result = $this->como(null)->get('auth/google');

        $result->assertRedirectTo(site_url('entrar'));
    }

    public function testIniciarGoogleGuardaStateEPkceERedirecionaParaOGoogle(): void
    {
        $this->habilitarGoogle();
        $provedor = $this->provedorGoogle();
        $provedor->method('getAuthorizationUrl')->willReturn('https://accounts.google.com/o/oauth2/v2/auth?x=1');
        $provedor->method('getState')->willReturn('estado-123');
        $provedor->method('getPkceCode')->willReturn('pkce-456');

        $result = $this->como(null)->get('auth/google');

        $result->assertRedirectTo('https://accounts.google.com/o/oauth2/v2/auth?x=1');
        $this->assertSame('estado-123', $this->sessao()['oauth2_state']);
        $this->assertSame('pkce-456', $this->sessao()['oauth2_pkce']);
    }

    /**
     * @param array<string, mixed> $contaGoogle
     */
    private function retornoDoGoogle(array $contaGoogle, string $stateRecebido = 'estado-123'): \CodeIgniter\Test\TestResponse
    {
        $this->habilitarGoogle();
        $provedor = $this->provedorGoogle();
        $provedor->expects($this->once())->method('setPkceCode')->with('pkce-456');
        $provedor->method('getAccessToken')->willReturn(new AccessToken(['access_token' => 'tk']));
        $provedor->method('getResourceOwner')->willReturn(new GoogleUser($contaGoogle));

        return $this->como(null, ['oauth2_state' => 'estado-123', 'oauth2_pkce' => 'pkce-456'])
            ->get('auth/google/callback', ['state' => $stateRecebido, 'code' => 'codigo-abc']);
    }

    public function testRetornoDoGoogleAbreASessaoDeUsuarioCadastradoEmDominioPermitido(): void
    {
        $this->db->table('dominios_email_permitidos')->insert(['dominio' => 'fatec.sp.gov.br']);
        $usuario = $this->criarUsuario(['email' => 'ana@fatec.sp.gov.br']);

        $result = $this->retornoDoGoogle([
            'sub' => 'g-789', 'email' => 'ana@fatec.sp.gov.br', 'email_verified' => true, 'name' => 'Ana',
        ]);

        $result->assertRedirectTo(site_url('/'));
        $this->assertSame($usuario['id_usuario'], (int) $this->sessao()['id_usuario']);
        $this->seeInDatabase('usuarios', ['id_usuario' => $usuario['id_usuario'], 'google_id' => 'g-789']);
    }

    public function testRetornoDoGoogleComDominioNaoPermitidoNaoAbreSessao(): void
    {
        $this->db->table('dominios_email_permitidos')->insert(['dominio' => 'fatec.sp.gov.br']);
        $this->criarUsuario(['email' => 'ana@gmail.com']);

        $result = $this->retornoDoGoogle(['sub' => 'g-1', 'email' => 'ana@gmail.com', 'email_verified' => true]);

        $result->assertRedirectTo(site_url('entrar'));
        $this->assertArrayNotHasKey('id_usuario', $this->sessao());
    }

    public function testRetornoDoGoogleComStateDiferenteEhRecusado(): void
    {
        $this->db->table('dominios_email_permitidos')->insert(['dominio' => 'fatec.sp.gov.br']);
        $this->criarUsuario(['email' => 'ana@fatec.sp.gov.br']);

        $this->habilitarGoogle();
        $provedor = $this->provedorGoogle();
        $provedor->expects($this->never())->method('getAccessToken');

        $result = $this->como(null, ['oauth2_state' => 'estado-123'])
            ->get('auth/google/callback', ['state' => 'forjado', 'code' => 'codigo-abc']);

        $result->assertRedirectTo(site_url('entrar'));
        $this->assertArrayNotHasKey('id_usuario', $this->sessao());
    }

    public function testRetornoDoGoogleSemStateNaSessaoEhRecusado(): void
    {
        $this->habilitarGoogle();
        $this->provedorGoogle()->expects($this->never())->method('getAccessToken');

        $result = $this->como(null)->get('auth/google/callback', ['state' => '', 'code' => 'codigo-abc']);

        $result->assertRedirectTo(site_url('entrar'));
    }

    public function testFalhaNaTrocaDoCodigoVoltaAoLoginSemVazarODetalhe(): void
    {
        $this->habilitarGoogle();
        $provedor = $this->provedorGoogle();
        $provedor->method('getAccessToken')->willThrowException(new RuntimeException('detalhe interno do Google'));

        $result = $this->como(null, ['oauth2_state' => 'estado-123', 'oauth2_pkce' => 'p'])
            ->get('auth/google/callback', ['state' => 'estado-123', 'code' => 'codigo-abc']);

        $result->assertRedirectTo(site_url('entrar'));
        $this->assertArrayNotHasKey('id_usuario', $this->sessao());
    }

    // ------------------------------------------------------ recuperação de senha

    protected function setUp(): void
    {
        parent::setUp();

        config(\Config\Encryption::class)->key = 'chave-de-teste-da-aplicacao';
    }

    /** @return string token do link enviado por e-mail */
    private function tokenEnviado(): string
    {
        /** @var Email $email */
        $email = service('email');

        $this->assertSame(1, preg_match('#senha/redefinir/([0-9a-f.]+)#', (string) $email->archive['body'], $partes));

        return $partes[1];
    }

    public function testPedidoDeRecuperacaoEnviaOLinkParaOEmailCadastrado(): void
    {
        $this->criarUsuario(['email' => 'ana@fatec.sp.gov.br', 'cpf' => '52998224725']);

        $result = $this->como(null)->post('senha/esqueci', ['identificador' => '529.982.247-25']);

        $result->assertRedirectTo(site_url('senha/esqueci'));
        $this->assertStringContainsString('ana@fatec.sp.gov.br', json_encode(service('email')->archive['recipients']));
        $this->assertSame('Ambienta: redefinição de senha', service('email')->archive['subject']);
        $this->assertNotSame('', $this->tokenEnviado());
    }

    public function testPedidoParaCadastroInexistenteResponderIgualENaoEnviaNada(): void
    {
        $result = $this->como(null)->post('senha/esqueci', ['identificador' => 'ninguem@fatec.sp.gov.br']);

        $result->assertRedirectTo(site_url('senha/esqueci'));
        $this->assertEmpty(service('email')->archive ?? []);
    }

    public function testLinkDefineNovaSenhaEUmaSoVez(): void
    {
        $usuario = $this->criarUsuario(['email' => 'ana@fatec.sp.gov.br']);
        $this->como(null)->post('senha/esqueci', ['identificador' => 'ana@fatec.sp.gov.br']);
        $token = $this->tokenEnviado();

        $this->como(null)->get("senha/redefinir/{$token}")->assertStatus(200);

        $troca = $this->como(null)->post("senha/redefinir/{$token}", [
            'senha' => 'nova-senha-456', 'senha_confirma' => 'nova-senha-456',
        ]);
        $troca->assertRedirectTo(site_url('entrar'));

        $hash = $this->db->table('usuarios')->where('id_usuario', $usuario['id_usuario'])->get()->getRow()->senha_hash;
        $this->assertTrue(password_verify('nova-senha-456', $hash));

        // O mesmo link não vale de novo.
        $this->como(null)->get("senha/redefinir/{$token}")->assertStatus(410);
        $this->como(null)->post("senha/redefinir/{$token}", [
            'senha' => 'outra-senha-789', 'senha_confirma' => 'outra-senha-789',
        ])->assertStatus(410);
    }

    public function testNovaSenhaPrecisaDeOitoCaracteresEConfirmacaoIgual(): void
    {
        $this->criarUsuario(['email' => 'ana@fatec.sp.gov.br']);
        $this->como(null)->post('senha/esqueci', ['identificador' => 'ana@fatec.sp.gov.br']);
        $token = $this->tokenEnviado();

        $curta = $this->como(null)->post("senha/redefinir/{$token}", ['senha' => '123', 'senha_confirma' => '123']);
        $curta->assertStatus(422);

        $diferente = $this->como(null)->post("senha/redefinir/{$token}", [
            'senha' => 'senha-comprida-1', 'senha_confirma' => 'senha-comprida-2',
        ]);
        $diferente->assertStatus(422);
    }

    public function testLinkAdulteradoOuDeOutroUsuarioNaoFunciona(): void
    {
        $this->criarUsuario(['email' => 'ana@fatec.sp.gov.br']);
        $outro = $this->criarUsuario(['email' => 'bia@fatec.sp.gov.br']);
        $this->como(null)->post('senha/esqueci', ['identificador' => 'ana@fatec.sp.gov.br']);
        $token = $this->tokenEnviado();

        $trocado = preg_replace('/^\d+\./', $outro['id_usuario'] . '.', $token);

        $this->como(null)->get('senha/redefinir/' . $trocado)->assertStatus(410);
        $this->como(null)->get('senha/redefinir/lixo')->assertStatus(410);
    }
}

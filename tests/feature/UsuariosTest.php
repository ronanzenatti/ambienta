<?php

use Tests\Support\FeatureBancoTestCase;

/**
 * Tela de usuários: consulta, cadastro, alteração, perfis com escopo, reativação, exclusão e LGPD.
 *
 * @internal
 */
final class UsuariosTest extends FeatureBancoTestCase
{
    private int $campusA;
    private int $campusB;

    /** @var array<string, mixed> */
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campusA = $this->criarCampus('Campus A');
        $this->campusB = $this->criarCampus('Campus B');
        $this->admin   = $this->criarUsuario(['nome' => 'Diretora Geral', 'email' => 'diretora@fatec.sp.gov.br']);
        $this->vincular($this->admin['id_usuario'], 'Direção');
    }

    /**
     * Gestor de usuários restrito a um campus.
     *
     * @return array<string, mixed>
     */
    private function gestorDoCampus(int $campusId): array
    {
        $perfil   = $this->db->table('perfis')->where('nome', 'Gestor de usuários')->get()->getRow();
        // Tem tudo que o perfil Docentes tem (para poder concedê-lo), mais a gestão de usuários; não tem
        // patrimônio nem as permissões específicas da Coordenação.
        $perfilId = $perfil !== null ? (int) $perfil->id_perfil : $this->criarPerfil('Gestor de usuários', [
            'usuarios.acesso' => 'editor', 'institucional.acesso' => 'editor', 'agendamento.acesso' => 'editor',
            'chamados.acesso' => 'editor', 'notificacoes.acesso' => 'editor', 'academico.acesso' => 'leitor',
            'academico.requisitos' => 'editor', 'patrimonio.confirmar_recebimento' => 'editor',
        ]);

        $gestor = $this->criarUsuario(['nome' => "Gestor {$campusId}"]);
        $this->db->table('usuarios_perfis')->insert([
            'usuario_id' => $gestor['id_usuario'], 'perfil_id' => $perfilId, 'escopo_tipo' => 'campus', 'campus_id' => $campusId,
        ]);

        return $gestor;
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, string>
     */
    private function formulario(array $extra = []): array
    {
        return $extra + [
            'nome' => 'Nova Pessoa', 'email' => 'nova@fatec.sp.gov.br', 'cpf' => '', 'senha' => '',
            'idioma_preferido' => 'pt-BR',
        ];
    }

    private function usuarioPorEmail(string $email): ?object
    {
        return $this->db->table('usuarios')->where('email', $email)->get()->getRow();
    }

    private function perfisAtivosDe(int $usuarioId): int
    {
        return $this->db->table('usuarios_perfis')
            ->where('usuario_id', $usuarioId)
            ->where('deletado_em', null)
            ->countAllResults();
    }

    private function foiExcluido(int $usuarioId): bool
    {
        return $this->db->table('usuarios')->where('id_usuario', $usuarioId)->get()->getRow()->deletado_em !== null;
    }

    // --------------------------------------------------------------- acesso

    public function testVisitanteEhMandadoParaOLogin(): void
    {
        $this->como(null)->get('usuarios')->assertRedirectTo(site_url('entrar'));
        $this->como(null)->get('usuarios/novo')->assertRedirectTo(site_url('entrar'));
        $this->como(null)->post('usuarios', $this->formulario())->assertRedirectTo(site_url('entrar'));
    }

    public function testPerfilSemPermissaoDeUsuariosRecebe403(): void
    {
        $docente = $this->criarUsuario();
        $this->vincular($docente['id_usuario'], 'Docentes');

        $this->como($docente)->get('usuarios')->assertStatus(403);
        $this->como($docente)->get('usuarios/novo')->assertStatus(403);
        $this->como($docente)->post('usuarios', $this->formulario())->assertStatus(403);
        $this->como($docente)->get('perfis')->assertStatus(403);
        $this->como($docente)->get('dominios')->assertStatus(403);
        $this->assertNull($this->usuarioPorEmail('nova@fatec.sp.gov.br'));
    }

    public function testLeitorConsultaMasNaoAltera(): void
    {
        $coordenador = $this->criarUsuario(['nome' => 'Coordenador']);
        $this->vincular($coordenador['id_usuario'], 'Coordenação');
        $alvo = $this->criarUsuario(['nome' => 'Alvo Qualquer']);
        $this->vincular($alvo['id_usuario'], 'Docentes');

        $lista = $this->como($coordenador)->get('usuarios');
        $lista->assertStatus(200);
        $lista->assertSee('Alvo Qualquer');
        $lista->assertDontSee('Novo usuário');

        $ver = $this->como($coordenador)->get('usuarios/' . $alvo['id_usuario']);
        $ver->assertStatus(200);
        $ver->assertSee('Você tem acesso somente para consulta.');
        $ver->assertDontSee('Anonimizar');

        $this->como($coordenador)->get('usuarios/novo')->assertStatus(403);
        $this->como($coordenador)->post('usuarios/' . $alvo['id_usuario'], $this->formulario(['nome' => 'Hackeado']))->assertStatus(403);
        $this->como($coordenador)->excluir('usuarios/' . $alvo['id_usuario'])->assertStatus(403);
        $this->seeInDatabase('usuarios', ['id_usuario' => $alvo['id_usuario'], 'nome' => 'Alvo Qualquer', 'deletado_em' => null]);
    }

    public function testRequisicaoDeEscritaSemTokenCsrfEhBarrada(): void
    {
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);

        $this->como($this->admin)->postSemToken('usuarios', $this->formulario());
    }

    // ----------------------------------------------------------- listagem

    public function testListaBuscaPorNomeEmailECpf(): void
    {
        $this->criarUsuario(['nome' => 'Beatriz Souza', 'email' => 'bia@fatec.sp.gov.br', 'cpf' => '52998224725']);
        $this->criarUsuario(['nome' => 'Carlos Lima', 'email' => 'carlos@fatec.sp.gov.br']);

        $porNome = $this->como($this->admin)->get('usuarios', ['q' => 'Beatriz']);
        $porNome->assertSee('Beatriz Souza');
        $porNome->assertDontSee('Carlos Lima');

        $this->como($this->admin)->get('usuarios', ['q' => 'carlos@'])->assertSee('Carlos Lima');
        $this->como($this->admin)->get('usuarios', ['q' => '529.982.247-25'])->assertSee('Beatriz Souza');
        $this->como($this->admin)->get('usuarios', ['q' => 'zzz'])->assertSee('Nenhum registro encontrado para “zzz”.');
    }

    public function testGestorDeCampusSoVeUsuariosDoSeuEscopo(): void
    {
        $gestor = $this->gestorDoCampus($this->campusA);
        $predioA = $this->criarPredio($this->campusA, 'Prédio A1');
        $predioB = $this->criarPredio($this->campusB, 'Prédio B1');

        $noCampusA = $this->criarUsuario(['nome' => 'Docente do Campus A']);
        $this->vincular($noCampusA['id_usuario'], 'Docentes', 'campus', $this->campusA);
        $noPredioA = $this->criarUsuario(['nome' => 'Docente do Prédio A']);
        $this->vincular($noPredioA['id_usuario'], 'Docentes', 'predio', null, $predioA);
        $noCampusB = $this->criarUsuario(['nome' => 'Docente do Campus B']);
        $this->vincular($noCampusB['id_usuario'], 'Docentes', 'campus', $this->campusB);
        $noPredioB = $this->criarUsuario(['nome' => 'Docente do Prédio B']);
        $this->vincular($noPredioB['id_usuario'], 'Docentes', 'predio', null, $predioB);
        $this->criarUsuario(['nome' => 'Sem Vínculo Nenhum']);

        $lista = $this->como($gestor)->get('usuarios');

        $lista->assertSee('Docente do Campus A');
        $lista->assertSee('Docente do Prédio A');
        $lista->assertDontSee('Docente do Campus B');
        $lista->assertDontSee('Docente do Prédio B');
        $lista->assertDontSee('Sem Vínculo Nenhum');
        $lista->assertDontSee('Diretora Geral');

        // Abrir direto pelo endereço um usuário de fora do escopo também não funciona.
        $this->como($gestor)->get('usuarios/' . $noCampusB['id_usuario'])->assertRedirectTo(site_url('usuarios'));
    }

    // ------------------------------------------------------------- cadastro

    public function testCadastraUsuarioComSenhaCpfEPerfilInicial(): void
    {
        $result = $this->como($this->admin)->post('usuarios', $this->formulario([
            'cpf' => '529.982.247-25', 'senha' => 'senha-forte-123',
            'perfil_id' => $this->perfilId('Docentes'), 'escopo_tipo' => 'global',
        ]));

        $criado = $this->usuarioPorEmail('nova@fatec.sp.gov.br');
        $this->assertNotNull($criado);
        $result->assertRedirectTo(site_url('usuarios/' . $criado->id_usuario));
        $this->assertSame('52998224725', $criado->cpf, 'CPF fica só com dígitos');
        $this->assertTrue(password_verify('senha-forte-123', $criado->senha_hash));
        $this->assertSame(1, (int) $criado->ativo);
        $this->seeInDatabase('usuarios_perfis', [
            'usuario_id' => $criado->id_usuario, 'perfil_id' => $this->perfilId('Docentes'), 'escopo_tipo' => 'global',
        ]);
    }

    public function testEmailEmMaiusculasEhGuardadoEmMinusculas(): void
    {
        $this->como($this->admin)->post('usuarios', $this->formulario(['email' => 'Nova@FATEC.sp.gov.br']));

        $this->assertNotNull($this->usuarioPorEmail('nova@fatec.sp.gov.br'));
    }

    public function testUsuarioSemSenhaSoEntraPeloGoogle(): void
    {
        $this->como($this->admin)->post('usuarios', $this->formulario());

        $this->assertNull($this->usuarioPorEmail('nova@fatec.sp.gov.br')->senha_hash);
    }

    public function testCadastroRecusaDadosInvalidos(): void
    {
        $casos = [
            'nome vazio'      => ['nome' => ''],
            'e-mail inválido' => ['email' => 'isto-nao-e-email'],
            'cpf inválido'    => ['cpf' => '11111111111'],
            'senha curta'     => ['senha' => '1234567'],
            'idioma inválido' => ['idioma_preferido' => 'klingon'],
        ];

        foreach ($casos as $descricao => $campos) {
            $result = $this->como($this->admin)->post('usuarios', $this->formulario($campos));

            $result->assertStatus(422);
            $this->assertNull($this->usuarioPorEmail('nova@fatec.sp.gov.br'), $descricao);
        }
    }

    public function testCadastroRecusaEmailECpfJaUsadosPorUsuarioAtivo(): void
    {
        $this->criarUsuario(['email' => 'existente@fatec.sp.gov.br', 'cpf' => '52998224725']);

        $dados    = $this->formulario(['email' => 'existente@fatec.sp.gov.br']);
        $porEmail = $this->como($this->admin)->post('usuarios', $dados);
        $porEmail->assertStatus(422);
        $porEmail->assertSee('Já existe um usuário ativo com este e-mail.');

        $porCpf = $this->como($this->admin)->post('usuarios', $this->formulario(['cpf' => '529.982.247-25']));
        $porCpf->assertStatus(422);
        $porCpf->assertSee('Já existe um usuário ativo com este CPF.');
    }

    // ------------------------------------------------------------ reativação

    public function testEmailDeCadastroExcluidoOferecReativacaoEmVezDeDuplicar(): void
    {
        $antigo = $this->criarUsuario([
            'nome' => 'Ex-Professor', 'email' => 'volta@fatec.sp.gov.br', 'deletado_em' => '2026-01-10 08:00:00',
        ]);

        $result = $this->como($this->admin)->post('usuarios', $this->formulario(['email' => 'volta@fatec.sp.gov.br']));

        $result->assertStatus(409);
        $result->assertSee('Este cadastro já existiu');
        $result->assertSee('Ex-Professor');
        $result->assertSee('usuarios/reativar/' . $antigo['id_usuario']);
        $total = $this->db->table('usuarios')->where('email', 'volta@fatec.sp.gov.br')->countAllResults();
        $this->assertSame(1, $total, 'nada foi duplicado');
    }

    public function testCpfDeCadastroExcluidoTambemOferecReativacao(): void
    {
        $this->criarUsuario(['cpf' => '52998224725', 'deletado_em' => '2026-01-10 08:00:00']);

        $this->como($this->admin)->post('usuarios', $this->formulario(['cpf' => '52998224725']))
            ->assertStatus(409);
    }

    public function testReativarDevolveOAcessoSemSenhaAntigaENemPerfisAntigos(): void
    {
        $antigo = $this->criarUsuario([
            'email' => 'volta@fatec.sp.gov.br', 'ativo' => 0, 'deletado_em' => '2026-01-10 08:00:00',
        ]);
        $this->db->table('usuarios_perfis')->insert([
            'usuario_id' => $antigo['id_usuario'], 'perfil_id' => $this->perfilId('Docentes'),
            'escopo_tipo' => 'global', 'deletado_em' => '2026-01-10 08:00:00',
        ]);

        $result = $this->como($this->admin)->post('usuarios/reativar/' . $antigo['id_usuario']);

        $result->assertRedirectTo(site_url('usuarios/' . $antigo['id_usuario']));
        $linha = $this->db->table('usuarios')->where('id_usuario', $antigo['id_usuario'])->get()->getRow();
        $this->assertNull($linha->deletado_em);
        $this->assertSame(1, (int) $linha->ativo);
        $this->assertNull($linha->senha_hash, 'a senha anterior é descartada');
        $this->assertSame(0, $this->perfisAtivosDe($antigo['id_usuario']));
    }

    public function testReativarPodeJaAtribuirUmPerfil(): void
    {
        $antigo = $this->criarUsuario(['email' => 'volta@fatec.sp.gov.br', 'deletado_em' => '2026-01-10 08:00:00']);

        $this->como($this->admin)->post('usuarios/reativar/' . $antigo['id_usuario'], [
            'perfil_id' => $this->perfilId('Docentes'), 'escopo_tipo' => 'global',
        ])->assertRedirect();

        $this->seeInDatabase('usuarios_perfis', ['usuario_id' => $antigo['id_usuario'], 'deletado_em' => null]);
    }

    public function testNaoReativaSeOEmailJaFoiTomadoPorOutroUsuarioAtivo(): void
    {
        $antigo = $this->criarUsuario(['email' => 'volta@fatec.sp.gov.br', 'deletado_em' => '2026-01-10 08:00:00']);
        $this->criarUsuario(['email' => 'volta@fatec.sp.gov.br']);

        $this->como($this->admin)->post('usuarios/reativar/' . $antigo['id_usuario'])
            ->assertRedirectTo(site_url('usuarios'));

        $this->assertTrue($this->foiExcluido($antigo['id_usuario']));
    }

    public function testCadastroAnonimizadoNuncaEhOferecidoParaReativacao(): void
    {
        $anonimo = $this->criarUsuario(['email' => 'volta@fatec.sp.gov.br']);
        (new \App\Libraries\AnonimizadorUsuario())->anonimizar($anonimo['id_usuario']);

        // O e-mail original ficou livre: cria um cadastro novo, sem oferta de reativação.
        $dados = $this->formulario(['email' => 'volta@fatec.sp.gov.br']);
        $this->como($this->admin)->post('usuarios', $dados)->assertRedirect();
        $this->como($this->admin)->post('usuarios/reativar/' . $anonimo['id_usuario'])
            ->assertRedirectTo(site_url('usuarios'));

        $this->assertTrue($this->foiExcluido($anonimo['id_usuario']));
    }

    // -------------------------------------------------- escopo de quem administra

    public function testGestorDeCampusExigePerfilNoCadastro(): void
    {
        $gestor = $this->gestorDoCampus($this->campusA);

        $result = $this->como($gestor)->post('usuarios', $this->formulario());

        $result->assertStatus(422);
        $this->assertNull($this->usuarioPorEmail('nova@fatec.sp.gov.br'));
    }

    public function testGestorCadastraDentroDoSeuCampus(): void
    {
        $gestor = $this->gestorDoCampus($this->campusA);

        $this->como($gestor)->post('usuarios', $this->formulario([
            'perfil_id' => $this->perfilId('Docentes'), 'escopo_tipo' => 'campus', 'campus_id' => $this->campusA,
        ]))->assertRedirect();

        $criado = $this->usuarioPorEmail('nova@fatec.sp.gov.br');
        $this->seeInDatabase('usuarios_perfis', [
            'usuario_id' => $criado->id_usuario, 'escopo_tipo' => 'campus', 'campus_id' => $this->campusA,
        ]);
        // E o usuário aparece na lista dele.
        $this->como($gestor)->get('usuarios')->assertSee('Nova Pessoa');
    }

    public function testGestorNaoCadastraForaDoSeuCampusEONadaFicaGravado(): void
    {
        $gestor = $this->gestorDoCampus($this->campusA);

        $result = $this->como($gestor)->post('usuarios', $this->formulario([
            'perfil_id' => $this->perfilId('Docentes'), 'escopo_tipo' => 'campus', 'campus_id' => $this->campusB,
        ]));

        $result->assertStatus(422);
        $this->assertNull(
            $this->usuarioPorEmail('nova@fatec.sp.gov.br'),
            'o cadastro é desfeito junto com o vínculo negado'
        );
    }

    public function testGestorNaoEntregaPerfilComMaisPermissoesDoQueTem(): void
    {
        $gestor = $this->gestorDoCampus($this->campusA);

        // Coordenação inclui patrimônio, que o gestor não tem.
        $this->como($gestor)->post('usuarios', $this->formulario([
            'perfil_id' => $this->perfilId('Coordenação'), 'escopo_tipo' => 'campus', 'campus_id' => $this->campusA,
        ]))->assertStatus(422);

        // Superperfil, só o superperfil concede.
        $this->como($gestor)->post('usuarios', $this->formulario([
            'perfil_id' => $this->perfilId('Direção'), 'escopo_tipo' => 'global',
        ]))->assertStatus(422);

        $this->assertNull($this->usuarioPorEmail('nova@fatec.sp.gov.br'));
    }

    public function testGestorDeCampusNaoAlteraUsuarioDeOutroCampusNemOSuperperfil(): void
    {
        $gestor  = $this->gestorDoCampus($this->campusA);
        $docenteB = $this->criarUsuario(['nome' => 'Docente B']);
        $this->vincular($docenteB['id_usuario'], 'Docentes', 'campus', $this->campusB);

        foreach ([$docenteB, $this->admin] as $alvo) {
            $this->como($gestor)->post('usuarios/' . $alvo['id_usuario'], $this->formulario(['nome' => 'Hackeado']))
                ->assertRedirectTo(site_url('usuarios'));
            $this->como($gestor)->excluir('usuarios/' . $alvo['id_usuario'])->assertRedirectTo(site_url('usuarios'));
        }

        $this->seeInDatabase('usuarios', [
            'id_usuario' => $docenteB['id_usuario'], 'nome' => 'Docente B', 'deletado_em' => null,
        ]);
        $this->seeInDatabase('usuarios', [
            'id_usuario' => $this->admin['id_usuario'], 'nome' => 'Diretora Geral', 'deletado_em' => null,
        ]);
    }

    public function testUsuarioComVinculosEmDoisCamposSoEGeridoPeloEscopoGlobal(): void
    {
        $gestor = $this->gestorDoCampus($this->campusA);
        $duplo  = $this->criarUsuario(['nome' => 'Docente dos Dois']);
        $this->vincular($duplo['id_usuario'], 'Docentes', 'campus', $this->campusA);
        $this->vincular($duplo['id_usuario'], 'Docentes', 'campus', $this->campusB);

        $this->como($gestor)->post('usuarios/' . $duplo['id_usuario'], $this->formulario(['nome' => 'Mudou']))
            ->assertRedirectTo(site_url('usuarios'));
        $dados = $this->formulario(['nome' => 'Mudou', 'ativo' => '1', 'email' => $duplo['email']]);
        $this->como($this->admin)->post('usuarios/' . $duplo['id_usuario'], $dados)->assertRedirect();

        $this->seeInDatabase('usuarios', ['id_usuario' => $duplo['id_usuario'], 'nome' => 'Mudou']);
    }

    // ------------------------------------------------------------ alteração

    public function testAlteraDadosETrocaASenha(): void
    {
        $alvo = $this->criarUsuario(['nome' => 'Antes', 'email' => 'antes@fatec.sp.gov.br']);

        $this->como($this->admin)->post('usuarios/' . $alvo['id_usuario'], $this->formulario([
            'nome' => 'Depois', 'email' => 'depois@fatec.sp.gov.br', 'idioma_preferido' => 'es',
            'senha' => 'nova-senha-456', 'ativo' => '1',
        ]))->assertRedirectTo(site_url('usuarios/' . $alvo['id_usuario']));

        $linha = $this->db->table('usuarios')->where('id_usuario', $alvo['id_usuario'])->get()->getRow();
        $this->assertSame('Depois', $linha->nome);
        $this->assertSame('depois@fatec.sp.gov.br', $linha->email);
        $this->assertSame('es', $linha->idioma_preferido);
        $this->assertTrue(password_verify('nova-senha-456', $linha->senha_hash));
    }

    public function testSenhaEmBrancoNaAlteracaoMantemASenhaAtual(): void
    {
        $alvo = $this->criarUsuario(['email' => 'mantem@fatec.sp.gov.br']);

        $this->como($this->admin)->post('usuarios/' . $alvo['id_usuario'], $this->formulario([
            'email' => 'mantem@fatec.sp.gov.br', 'senha' => '', 'ativo' => '1',
        ]));

        $hash = $this->db->table('usuarios')->where('id_usuario', $alvo['id_usuario'])->get()->getRow()->senha_hash;
        $this->assertTrue(password_verify(self::SENHA, $hash));
    }

    // ------------------------------------------------------------ troca rápida de senha

    /** @return array<string, mixed> quem gere usuários e pode trocar senhas, restrito a um campus */
    private function trocadorDoCampus(int $campusId): array
    {
        $perfilId = $this->criarPerfil("Trocador {$campusId}", [
            'usuarios.acesso' => 'leitor', 'usuarios.trocar_senha' => 'editor',
        ]);
        $usuario = $this->criarUsuario(['nome' => "Trocador {$campusId}"]);
        $this->db->table('usuarios_perfis')->insert([
            'usuario_id' => $usuario['id_usuario'], 'perfil_id' => $perfilId, 'escopo_tipo' => 'campus', 'campus_id' => $campusId,
        ]);

        return $usuario;
    }

    public function testTrocaDeSenhaExigeAPermissaoPropria(): void
    {
        $alvo   = $this->criarUsuario();
        $gestor = $this->gestorDoCampus($this->campusA);
        $this->vincular($alvo['id_usuario'], 'Docentes', 'campus', $this->campusA);

        $this->como($gestor)->get('usuarios/' . $alvo['id_usuario'] . '/senha')->assertStatus(403);
        $this->como($gestor)->post('usuarios/' . $alvo['id_usuario'] . '/senha', ['senha' => 'provisoria-123'])
            ->assertStatus(403);
    }

    public function testTrocaDeSenhaGravaHashMarcaATrocaEDevolveOTextoDeAcesso(): void
    {
        $alvo = $this->criarUsuario(['email' => 'troca@fatec.sp.gov.br']);

        $resposta = $this->como($this->admin)->post('usuarios/' . $alvo['id_usuario'] . '/senha?modal=1', [
            'senha' => 'provisoria-123', 'enviar_email' => '0',
        ]);
        $resposta->assertOK();

        $json = json_decode($resposta->getJSON(), true);
        $this->assertNull($json['resultado']['email']);
        $this->assertStringContainsString(site_url('entrar'), $json['resultado']['texto']);
        $this->assertStringContainsString('troca@fatec.sp.gov.br', $json['resultado']['texto']);
        $this->assertStringContainsString('provisoria-123', $json['resultado']['texto']);

        $linha = $this->db->table('usuarios')->where('id_usuario', $alvo['id_usuario'])->get()->getRow();
        $this->assertTrue(password_verify('provisoria-123', $linha->senha_hash));
        $this->assertSame('1', (string) $linha->trocar_senha);
    }

    public function testTrocaDeSenhaRecusaSenhaCurtaENaoGrava(): void
    {
        $alvo = $this->criarUsuario();

        $this->como($this->admin)->post('usuarios/' . $alvo['id_usuario'] . '/senha?modal=1', ['senha' => 'curta'])
            ->assertStatus(422);

        $linha = $this->db->table('usuarios')->where('id_usuario', $alvo['id_usuario'])->get()->getRow();
        $this->assertTrue(password_verify(self::SENHA, $linha->senha_hash));
        $this->assertSame('0', (string) $linha->trocar_senha);
    }

    public function testTrocaDeSenhaRespeitaOEscopoENaoServeParaASiMesmoNemParaOSuperperfil(): void
    {
        $trocador = $this->trocadorDoCampus($this->campusA);
        $docenteB = $this->criarUsuario();
        $this->vincular($docenteB['id_usuario'], 'Docentes', 'campus', $this->campusB);
        $docenteA = $this->criarUsuario();
        $this->vincular($docenteA['id_usuario'], 'Docentes', 'campus', $this->campusA);

        foreach ([$docenteB, $this->admin, $trocador] as $alvo) {
            $this->como($trocador)->post('usuarios/' . $alvo['id_usuario'] . '/senha', ['senha' => 'provisoria-123'])
                ->assertRedirectTo(site_url('usuarios'));
        }
        $this->como($trocador)->post('usuarios/' . $docenteA['id_usuario'] . '/senha', [
            'senha' => 'provisoria-123', 'enviar_email' => '0',
        ])->assertOK();

        $this->dontSeeInDatabase('usuarios', ['id_usuario' => $docenteB['id_usuario'], 'trocar_senha' => 1]);
        $this->dontSeeInDatabase('usuarios', ['id_usuario' => $this->admin['id_usuario'], 'trocar_senha' => 1]);
        $this->seeInDatabase('usuarios', ['id_usuario' => $docenteA['id_usuario'], 'trocar_senha' => 1]);
    }

    public function testListaOferecEstaAcaoSoParaQuemTemAPermissao(): void
    {
        $alvo     = $this->criarUsuario(['nome' => 'Alvo da Troca']);
        $trocador = $this->trocadorDoCampus($this->campusA);
        $this->vincular($alvo['id_usuario'], 'Docentes', 'campus', $this->campusA);

        $this->como($trocador)->get('usuarios?parcial=1')
            ->assertSee('usuarios/' . $alvo['id_usuario'] . '/senha');
        $this->como($this->gestorDoCampus($this->campusA))->get('usuarios?parcial=1')
            ->assertDontSee('/senha');
    }

    public function testUsuarioComSenhaProvisoriaSoConsegueEscolherASuaSenha(): void
    {
        $alvo = $this->criarUsuario();
        $this->como($this->admin)->post('usuarios/' . $alvo['id_usuario'] . '/senha', [
            'senha' => 'provisoria-123', 'enviar_email' => '0',
        ])->assertOK();

        $this->como($alvo)->get('/')->assertRedirectTo(site_url('senha/trocar'));
        $this->como($alvo)->get('usuarios')->assertRedirectTo(site_url('senha/trocar'));
        $this->como($alvo)->get('senha/trocar')->assertOK();

        // Repetir a provisória ou errar a confirmação não libera.
        $this->como($alvo)->post('senha/trocar', ['senha' => 'provisoria-123', 'senha_confirma' => 'provisoria-123'])
            ->assertStatus(422);
        $this->como($alvo)->post('senha/trocar', ['senha' => 'minha-senha-456', 'senha_confirma' => 'outra-coisa'])
            ->assertStatus(422);
        $this->seeInDatabase('usuarios', ['id_usuario' => $alvo['id_usuario'], 'trocar_senha' => 1]);

        $this->como($alvo)->post('senha/trocar', ['senha' => 'minha-senha-456', 'senha_confirma' => 'minha-senha-456'])
            ->assertRedirectTo(site_url('/'));

        $linha = $this->db->table('usuarios')->where('id_usuario', $alvo['id_usuario'])->get()->getRow();
        $this->assertTrue(password_verify('minha-senha-456', $linha->senha_hash));
        $this->assertSame('0', (string) $linha->trocar_senha);
        $this->como($alvo)->get('senha/trocar')->assertRedirectTo(site_url('/'));
    }

    public function testDesativarImpedeOLogin(): void
    {
        $alvo = $this->criarUsuario(['email' => 'sai@fatec.sp.gov.br']);

        // Sem o campo "ativo" no formulário = desmarcado.
        $dados = $this->formulario(['email' => 'sai@fatec.sp.gov.br']);
        $this->como($this->admin)->post('usuarios/' . $alvo['id_usuario'], $dados);

        $this->seeInDatabase('usuarios', ['id_usuario' => $alvo['id_usuario'], 'ativo' => 0]);
        $login = ['identificador' => 'sai@fatec.sp.gov.br', 'senha' => self::SENHA];
        $this->como(null)->post('entrar', $login)->assertStatus(401);
    }

    public function testDesativarUmUsuarioLogadoDerrubaASessaoDele(): void
    {
        $alvo = $this->criarUsuario(['email' => 'sessao@fatec.sp.gov.br']);
        $this->vincular($alvo['id_usuario'], 'Coordenação');
        $this->como($alvo)->get('usuarios')->assertStatus(200);

        $this->db->table('usuarios')->where('id_usuario', $alvo['id_usuario'])->update(['ativo' => 0]);

        $this->como($alvo)->get('usuarios')->assertRedirectTo(site_url('entrar'));
    }

    public function testNaoDesativaANaoSerAOutraPessoaNemOUltimoAdministrador(): void
    {
        // Ninguém desativa a própria conta.
        $this->como($this->admin)->post('usuarios/' . $this->admin['id_usuario'], $this->formulario([
            'nome' => 'Diretora Geral', 'email' => 'diretora@fatec.sp.gov.br',
        ]))->assertStatus(422);
        $this->seeInDatabase('usuarios', ['id_usuario' => $this->admin['id_usuario'], 'ativo' => 1]);

        // Com um segundo administrador, ele pode desativar o primeiro, mas não o último que sobra.
        $segundo = $this->criarUsuario(['email' => 'segundo@fatec.sp.gov.br']);
        $this->vincular($segundo['id_usuario'], 'Direção');
        $this->como($segundo)->post('usuarios/' . $this->admin['id_usuario'], $this->formulario([
            'nome' => 'Diretora Geral', 'email' => 'diretora@fatec.sp.gov.br',
        ]))->assertRedirect();
        $this->seeInDatabase('usuarios', ['id_usuario' => $this->admin['id_usuario'], 'ativo' => 0]);
    }

    // ------------------------------------------------------ perfis do usuário

    public function testAtribuiEPerfilComEscopoEImpedeDuplicidade(): void
    {
        $alvo = $this->criarUsuario();
        $dados = ['perfil_id' => $this->perfilId('Docentes'), 'escopo_tipo' => 'campus', 'campus_id' => $this->campusA];

        $this->como($this->admin)->post('usuarios/' . $alvo['id_usuario'] . '/perfis', $dados)
            ->assertRedirectTo(site_url('usuarios/' . $alvo['id_usuario']));
        $this->seeInDatabase('usuarios_perfis', [
            'usuario_id' => $alvo['id_usuario'], 'escopo_tipo' => 'campus', 'campus_id' => $this->campusA,
        ]);

        $repetido = $this->como($this->admin)->post('usuarios/' . $alvo['id_usuario'] . '/perfis', $dados);
        $repetido->assertStatus(422);
        $repetido->assertSee('já tem este perfil neste escopo');
        $this->assertSame(1, $this->perfisAtivosDe($alvo['id_usuario']));
    }

    public function testSuperperfilSoEConcedidoEmEscopoGlobal(): void
    {
        $alvo = $this->criarUsuario();

        $this->como($this->admin)->post('usuarios/' . $alvo['id_usuario'] . '/perfis', [
            'perfil_id' => $this->perfilId('Direção'), 'escopo_tipo' => 'campus', 'campus_id' => $this->campusA,
        ])->assertStatus(422);

        $this->assertSame(0, $this->perfisAtivosDe($alvo['id_usuario']));
    }

    public function testEscopoInexistenteEhRecusado(): void
    {
        $alvo = $this->criarUsuario();

        $this->como($this->admin)->post('usuarios/' . $alvo['id_usuario'] . '/perfis', [
            'perfil_id' => $this->perfilId('Docentes'), 'escopo_tipo' => 'campus', 'campus_id' => 999999,
        ])->assertStatus(422);
        $this->como($this->admin)->post('usuarios/' . $alvo['id_usuario'] . '/perfis', [
            'perfil_id' => $this->perfilId('Docentes'), 'escopo_tipo' => 'planeta', 'campus_id' => $this->campusA,
        ])->assertStatus(422);
    }

    public function testRemoverPerfilExigeConfirmo(): void
    {
        $alvo      = $this->criarUsuario();
        $vinculoId = $this->vincular($alvo['id_usuario'], 'Docentes');

        $this->como($this->admin)->excluir('usuarios/' . $alvo['id_usuario'] . '/perfis/' . $vinculoId, 'confirmo')
            ->assertRedirect();
        $this->seeInDatabase('usuarios_perfis', ['id_usuario_perfil' => $vinculoId, 'deletado_em' => null]);

        $this->como($this->admin)->excluir('usuarios/' . $alvo['id_usuario'] . '/perfis/' . $vinculoId)
            ->assertRedirect();
        $this->assertSame(0, $this->perfisAtivosDe($alvo['id_usuario']));
    }

    public function testNaoRemoveOPerfilDoUltimoAdministrador(): void
    {
        $vinculo = $this->db->table('usuarios_perfis')->where('usuario_id', $this->admin['id_usuario'])->get()->getRow();
        $rota    = 'usuarios/' . $this->admin['id_usuario'] . '/perfis/' . $vinculo->id_usuario_perfil;

        $this->como($this->admin)->excluir($rota)->assertRedirect();

        $this->seeInDatabase('usuarios_perfis', [
            'id_usuario_perfil' => $vinculo->id_usuario_perfil, 'deletado_em' => null,
        ]);
    }

    // ------------------------------------------------------ exclusão e LGPD

    public function testExcluirExigeConfirmoEFazSoftDeleteRemovendoOsPerfis(): void
    {
        $alvo = $this->criarUsuario(['nome' => 'Vai Sair']);
        $this->vincular($alvo['id_usuario'], 'Docentes');

        foreach (['', 'confirmo', 'CONFIRMAR', ' '] as $errado) {
            $this->como($this->admin)->excluir('usuarios/' . $alvo['id_usuario'], $errado);
            $this->seeInDatabase('usuarios', ['id_usuario' => $alvo['id_usuario'], 'deletado_em' => null]);
        }

        $this->como($this->admin)->excluir('usuarios/' . $alvo['id_usuario'])->assertRedirectTo(site_url('usuarios'));

        $linha = $this->db->table('usuarios')->where('id_usuario', $alvo['id_usuario'])->get()->getRow();
        $this->assertNotNull($linha, 'soft delete: o registro continua no banco');
        $this->assertNotNull($linha->deletado_em);
        $this->assertSame('Vai Sair', $linha->nome, 'a exclusão simples não anonimiza');
        $this->assertSame(0, $this->perfisAtivosDe($alvo['id_usuario']));
        $this->como($this->admin)->get('usuarios')->assertDontSee('Vai Sair');
    }

    public function testNaoExcluiAPropriaContaNemOUltimoAdministrador(): void
    {
        $this->como($this->admin)->excluir('usuarios/' . $this->admin['id_usuario'])->assertRedirect();
        $this->como($this->admin)->excluir('usuarios/' . $this->admin['id_usuario'] . '/anonimizar')->assertRedirect();

        $this->seeInDatabase('usuarios', [
            'id_usuario' => $this->admin['id_usuario'], 'deletado_em' => null, 'anonimizado_em' => null,
        ]);
    }

    public function testNaoExcluiOUltimoAdministradorMesmoPorOutroSuperperfilEmEscopoNaoGlobal(): void
    {
        // Um segundo "Direção" só de campus não conta como administrador geral de reserva.
        $reserva = $this->criarUsuario(['email' => 'reserva@fatec.sp.gov.br']);
        $this->db->table('usuarios_perfis')->insert([
            'usuario_id' => $reserva['id_usuario'], 'perfil_id' => $this->perfilId('Direção'),
            'escopo_tipo' => 'campus', 'campus_id' => $this->campusA,
        ]);

        $this->como($reserva)->excluir('usuarios/' . $this->admin['id_usuario'])->assertRedirect();

        $this->seeInDatabase('usuarios', ['id_usuario' => $this->admin['id_usuario'], 'deletado_em' => null]);
    }

    public function testAnonimizarExigeConfirmoETrocaOsDadosPessoais(): void
    {
        $alvo = $this->criarUsuario([
            'nome' => 'Maria da Silva', 'email' => 'maria@fatec.sp.gov.br', 'cpf' => '52998224725',
        ]);

        $this->como($this->admin)->excluir('usuarios/' . $alvo['id_usuario'] . '/anonimizar', 'nao');
        $this->seeInDatabase('usuarios', ['id_usuario' => $alvo['id_usuario'], 'nome' => 'Maria da Silva']);

        $this->como($this->admin)->excluir('usuarios/' . $alvo['id_usuario'] . '/anonimizar')
            ->assertRedirectTo(site_url('usuarios'));

        $linha = $this->db->table('usuarios')->where('id_usuario', $alvo['id_usuario'])->get()->getRow();
        $this->assertSame('Usuário removido (LGPD)', $linha->nome);
        $this->assertNull($linha->cpf);
        $this->assertNotNull($linha->anonimizado_em);
    }

    public function testPaginaDoUsuarioTrazOsModaisDeExclusaoComConfirmo(): void
    {
        $alvo = $this->criarUsuario(['nome' => 'Fulano de Tal']);

        $html = (string) $this->como($this->admin)->get('usuarios/' . $alvo['id_usuario'])->response()->getBody();

        $this->assertStringContainsString('excluir-usuario', $html);
        $this->assertStringContainsString('anonimizar-usuario', $html);
        $this->assertStringContainsString('CONFIRMO', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*form="excluir-usuario-form"[^>]*\sdisabled/', $html);
        $this->assertStringNotContainsString('@click.self', $html);
        $this->assertStringNotContainsString('@click.outside', $html);
    }

    public function testBotaoEditarUsaAmareloEExcluirUsaVermelho(): void
    {
        $alvo = $this->criarUsuario();
        $this->criarUsuario(['nome' => 'Outro']);

        $lista  = (string) $this->como($this->admin)->get('usuarios')->response()->getBody();
        $pagina = (string) $this->como($this->admin)->get('usuarios/' . $alvo['id_usuario'])->response()->getBody();

        $this->assertStringContainsString('bg-action-edit text-ink', $lista);
        $this->assertStringContainsString('bg-action-edit text-ink', $pagina);
        $this->assertStringContainsString('bg-signal-critical text-brand-ink', $pagina);
    }
}

<?php

use App\Models\Institucional\DominioEmailPermitidoModel;
use Tests\Support\FeatureBancoTestCase;

/**
 * Telas do superperfil: perfis com matriz de permissões e domínios de e-mail do login Google.
 *
 * @internal
 */
final class PerfisEDominiosTest extends FeatureBancoTestCase
{
    /** @var array<string, mixed> */
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->criarUsuario(['nome' => 'Diretora Geral']);
        $this->vincular($this->admin['id_usuario'], 'Direção');
    }

    /**
     * @return array<string, string>
     */
    private function matrizDoPerfil(string $nome): array
    {
        $linhas = $this->db->query(
            'SELECT pe.chave, pp.tipo_acesso FROM perfis_permissoes pp
             JOIN perfis p ON p.id_perfil = pp.perfil_id
             JOIN permissoes pe ON pe.id_permissao = pp.permissao_id
             WHERE p.nome = ? AND pp.deletado_em IS NULL',
            [$nome]
        )->getResultArray();

        $matriz = array_column($linhas, 'tipo_acesso', 'chave');
        ksort($matriz);

        return $matriz;
    }

    // --------------------------------------------------------------- perfis

    public function testSoOSuperperfilVeEEditaPerfis(): void
    {
        $coordenador = $this->criarUsuario();
        $this->vincular($coordenador['id_usuario'], 'Coordenação');

        $this->como($coordenador)->get('perfis')->assertStatus(403);
        $this->como($coordenador)->get('perfis/novo')->assertStatus(403);
        $this->como($coordenador)->post('perfis', ['nome' => 'Invasor'])->assertStatus(403);
        $this->como(null)->get('perfis')->assertRedirectTo(site_url('entrar'));

        $lista = $this->como($this->admin)->get('perfis');
        $lista->assertStatus(200);
        foreach (['Direção', 'Coordenação', 'Auxiliares', 'Docentes'] as $nome) {
            $lista->assertSee($nome);
        }
    }

    public function testCriaPerfilComMatrizDeModulosEPermissoesEspecificas(): void
    {
        $result = $this->como($this->admin)->post('perfis', [
            'nome'       => 'Secretaria',
            'acesso'     => ['usuarios.acesso' => 'leitor', 'patrimonio.acesso' => 'editor', 'relatorios.acesso' => ''],
            'especifica' => ['patrimonio.confirmar_recebimento'],
        ]);

        $result->assertRedirectTo(site_url('perfis'));
        $this->seeInDatabase('perfis', ['nome' => 'Secretaria', 'super_perfil' => 0]);
        $this->assertSame(
            ['patrimonio.acesso' => 'editor', 'patrimonio.confirmar_recebimento' => 'editor', 'usuarios.acesso' => 'leitor'],
            $this->matrizDoPerfil('Secretaria')
        );
    }

    public function testIgnoraChavesForaDoCatalogoENiveisInvalidos(): void
    {
        $this->como($this->admin)->post('perfis', [
            'nome'       => 'Malandro',
            'acesso'     => ['usuarios.acesso' => 'superadmin', 'inventada.acesso' => 'editor', 'chamados.acesso' => 'leitor'],
            'especifica' => ['auditoria.expurgar', 'coisa.inventada'],
        ]);

        // 'especifica' aceita chave existente do catálogo (a decisão de conceder é do superperfil).
        $this->assertSame(
            ['auditoria.expurgar' => 'editor', 'chamados.acesso' => 'leitor'],
            $this->matrizDoPerfil('Malandro')
        );
    }

    public function testEdicaoAlteraNiveisEDesfazAcessoRemovido(): void
    {
        $id = $this->criarPerfil('Secretaria', ['usuarios.acesso' => 'leitor', 'patrimonio.acesso' => 'editor']);

        $this->como($this->admin)->post('perfis/' . $id, [
            'nome'   => 'Secretaria Geral',
            'acesso' => ['usuarios.acesso' => 'editor', 'patrimonio.acesso' => ''],
        ])->assertRedirectTo(site_url('perfis'));

        $this->assertSame(['usuarios.acesso' => 'editor'], $this->matrizDoPerfil('Secretaria Geral'));

        // Devolver um acesso que já existiu volta a funcionar (a linha antiga ficou como soft delete).
        $this->como($this->admin)->post('perfis/' . $id, [
            'nome'   => 'Secretaria Geral',
            'acesso' => ['usuarios.acesso' => 'editor', 'patrimonio.acesso' => 'leitor'],
        ]);
        $this->assertSame(
            ['patrimonio.acesso' => 'leitor', 'usuarios.acesso' => 'editor'],
            $this->matrizDoPerfil('Secretaria Geral')
        );
    }

    public function testPerfilCriadoNaTelaConcedeAcessoDeVerdade(): void
    {
        $this->como($this->admin)->post('perfis', ['nome' => 'Secretaria', 'acesso' => ['usuarios.acesso' => 'leitor']]);
        $secretaria = $this->criarUsuario();
        $this->vincular($secretaria['id_usuario'], 'Secretaria');

        $this->como($secretaria)->get('usuarios')->assertStatus(200);
        $this->como($secretaria)->get('usuarios/novo')->assertStatus(403);
    }

    public function testNomeVazioLongoOuRepetidoEhRecusado(): void
    {
        $this->como($this->admin)->post('perfis', ['nome' => ''])->assertStatus(422);
        $this->como($this->admin)->post('perfis', ['nome' => str_repeat('x', 101)])->assertStatus(422);

        $repetido = $this->como($this->admin)->post('perfis', ['nome' => 'Docentes']);
        $repetido->assertStatus(422);
        $repetido->assertSee('Já existe um perfil com este nome.');
    }

    public function testSuperperfilNaoPodeSerAlteradoNemExcluido(): void
    {
        $direcao = $this->perfilId('Direção');

        $this->como($this->admin)->post('perfis/' . $direcao, ['nome' => 'Renomeado'])->assertRedirectTo(site_url('perfis'));
        $this->como($this->admin)->excluir('perfis/' . $direcao)->assertRedirectTo(site_url('perfis'));

        $this->seeInDatabase('perfis', ['id_perfil' => $direcao, 'nome' => 'Direção', 'deletado_em' => null]);
        $this->como($this->admin)->get('perfis/' . $direcao)->assertSee('Superperfil: acesso irrestrito');
    }

    public function testExcluirPerfilExigeConfirmoENaoPodeEstarEmUso(): void
    {
        $livre = $this->criarPerfil('Temporário', ['chamados.acesso' => 'leitor']);
        $emUso = $this->criarPerfil('Em uso', ['chamados.acesso' => 'leitor']);
        $usuario = $this->criarUsuario();
        $this->vincular($usuario['id_usuario'], 'Em uso');

        $this->como($this->admin)->excluir('perfis/' . $livre, 'talvez');
        $this->seeInDatabase('perfis', ['id_perfil' => $livre, 'deletado_em' => null]);

        $this->como($this->admin)->excluir('perfis/' . $emUso)->assertRedirectTo(site_url('perfis'));
        $this->seeInDatabase('perfis', ['id_perfil' => $emUso, 'deletado_em' => null]);

        $this->como($this->admin)->excluir('perfis/' . $livre)->assertRedirectTo(site_url('perfis'));
        $this->assertNotNull($this->db->table('perfis')->where('id_perfil', $livre)->get()->getRow()->deletado_em);
        $this->assertSame([], $this->matrizDoPerfil('Temporário'));
    }

    public function testFormularioDePerfilTemOsNiveisPorModuloEAsPermissoesEspecificas(): void
    {
        $html = html_entity_decode(
            (string) $this->como($this->admin)->get('perfis/novo')->response()->getBody(),
            ENT_QUOTES | ENT_HTML5
        );

        $this->assertStringContainsString('name="acesso[usuarios.acesso]"', $html);
        $this->assertStringContainsString('name="acesso[patrimonio.acesso]"', $html);
        $this->assertStringContainsString('value="agendamento.autorizar_conflito"', $html);
        $this->assertStringContainsString('name="especifica[]"', $html);
    }

    // -------------------------------------------------------------- domínios

    public function testSoOSuperperfilAdministraDominios(): void
    {
        $coordenador = $this->criarUsuario();
        $this->vincular($coordenador['id_usuario'], 'Coordenação');

        $this->como($coordenador)->get('dominios')->assertStatus(403);
        $this->como($coordenador)->post('dominios', ['dominio' => 'fatec.sp.gov.br'])->assertStatus(403);
        $this->assertSame(0, $this->db->table('dominios_email_permitidos')->countAllResults());

        $lista = $this->como($this->admin)->get('dominios');
        $lista->assertStatus(200);
        $lista->assertSee('Nenhum domínio cadastrado');
    }

    public function testAdicionaDominioEmMinusculasEEleValeNoLoginGoogle(): void
    {
        $modelo = new DominioEmailPermitidoModel();
        $this->assertFalse($modelo->permite('fatec.sp.gov.br'));

        $this->como($this->admin)->post('dominios', ['dominio' => '  Fatec.SP.gov.br '])->assertRedirectTo(site_url('dominios'));

        $this->seeInDatabase('dominios_email_permitidos', ['dominio' => 'fatec.sp.gov.br', 'ativo' => 1]);
        $this->assertTrue($modelo->permite('fatec.sp.gov.br'));
        $this->assertTrue($modelo->permite('FATEC.sp.gov.br'));
        $this->como($this->admin)->get('dominios')->assertSee('fatec.sp.gov.br');
    }

    public function testRecusaDominioMalFormadoOuRepetido(): void
    {
        foreach (['', 'sem-ponto', '@fatec.sp.gov.br', 'a b.com', 'x@y.com', '-ruim.com', 'https://fatec.sp.gov.br'] as $ruim) {
            $this->como($this->admin)->post('dominios', ['dominio' => $ruim])->assertStatus(422);
        }
        $this->assertSame(0, $this->db->table('dominios_email_permitidos')->countAllResults());

        $this->como($this->admin)->post('dominios', ['dominio' => 'fatec.sp.gov.br']);
        $repetido = $this->como($this->admin)->post('dominios', ['dominio' => 'fatec.sp.gov.br']);
        $repetido->assertStatus(422);
        $repetido->assertSee('Este domínio já está cadastrado.');
    }

    public function testDesativarDominioTiraEleDoLoginGoogle(): void
    {
        $this->db->table('dominios_email_permitidos')->insert(['dominio' => 'fatec.sp.gov.br']);
        $id = (int) $this->db->insertID();

        $this->como($this->admin)->post("dominios/{$id}/alternar")->assertRedirectTo(site_url('dominios'));
        $this->assertFalse((new DominioEmailPermitidoModel())->permite('fatec.sp.gov.br'));

        $this->como($this->admin)->post("dominios/{$id}/alternar");
        $this->assertTrue((new DominioEmailPermitidoModel())->permite('fatec.sp.gov.br'));
    }

    public function testExcluirDominioExigeConfirmoEPermiteCadastrarDeNovo(): void
    {
        $this->db->table('dominios_email_permitidos')->insert(['dominio' => 'fatec.sp.gov.br']);
        $id = (int) $this->db->insertID();

        $this->como($this->admin)->excluir("dominios/{$id}", 'sim');
        $this->seeInDatabase('dominios_email_permitidos', ['id_dominio_email_permitido' => $id, 'deletado_em' => null]);

        $this->como($this->admin)->excluir("dominios/{$id}")->assertRedirectTo(site_url('dominios'));
        $this->assertFalse((new DominioEmailPermitidoModel())->permite('fatec.sp.gov.br'));

        // O domínio excluído libera o valor (soft delete com unicidade só entre ativos).
        $this->como($this->admin)->post('dominios', ['dominio' => 'fatec.sp.gov.br'])->assertRedirectTo(site_url('dominios'));
        $this->assertTrue((new DominioEmailPermitidoModel())->permite('fatec.sp.gov.br'));
    }
}

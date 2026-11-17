<?php

use CodeIgniter\Config\Services;
use Tests\Support\FeatureBancoTestCase;
use Tests\Support\Libraries\ArquivosDeTeste;

/**
 * Cadastro de campus: escopo (criar/excluir só no global, alterar no campus), identidade visual com
 * validação de contraste, arquivos (logo, foto, mapa em imagem ou PDF) e exclusão com CONFIRMO.
 *
 * @internal
 */
final class CampusTest extends FeatureBancoTestCase
{
    /** @var array<string, mixed> */
    private array $direcao;

    private ArquivosDeTeste $arquivos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->direcao = $this->criarUsuario(['nome' => 'Diretora']);
        $this->vincular($this->direcao['id_usuario'], 'Direção');

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
    private function dados(array $extra = []): array
    {
        return $extra + [
            'nome'         => 'Fatec Jahu',
            'abreviacao'   => 'FATEC-JAHU',
            'numero'       => '052',
            'logradouro'   => 'Rua Frei Galvão',
            'cidade'       => 'Jaú',
            'uf'           => 'sp',
            'cep'          => '17212-650',
            'latitude'     => '-22.2963',
            'longitude'    => '-48.5587',
            'cor_primaria' => '',
            'cor_texto'    => '',
            'ativo'        => '1',
        ];
    }

    /** @return array<string, mixed> */
    private function campusPorNome(string $nome): array
    {
        return (array) $this->db->table('campus')->where('nome', $nome)->get()->getRowArray();
    }

    public function testDirecaoCadastraCampusComEnderecoECoordenadas(): void
    {
        $resposta = $this->como($this->direcao)->post('campus', $this->dados());

        $campus = $this->campusPorNome('Fatec Jahu');
        $resposta->assertRedirectTo(site_url('campus/' . $campus['id_campus']));
        $this->assertSame('SP', $campus['uf'], 'UF é gravada em maiúsculas');
        $this->assertSame('-22.2963000', $campus['latitude']);
        $this->assertNull($campus['cor_primaria']);
        $this->assertNull($campus['cor_texto']);

        $pagina = $this->como($this->direcao)->get('campus/' . $campus['id_campus']);
        $pagina->assertStatus(200);
        $pagina->assertSee('Fatec Jahu');
        $pagina->assertSee('Identidade padrão do sistema');
        $this->assertStringContainsString('openstreetmap.org', (string) $pagina->getBody());
    }

    public function testIdentidadeComContrasteSuficienteESalvaEAplicadaSoNoConteinerDoCampus(): void
    {
        $this->como($this->direcao)->post('campus', $this->dados(['cor_primaria' => '#7a1f2b', 'cor_texto' => 'claro']));

        $campus = $this->campusPorNome('Fatec Jahu');
        $this->assertSame('#7A1F2B', $campus['cor_primaria']);
        $this->assertSame('claro', $campus['cor_texto']);

        $html = (string) $this->como($this->direcao)->get('campus/' . $campus['id_campus'])->getBody();
        $this->assertStringContainsString('style="--color-brand: #7A1F2B; --color-brand-ink: #FFFFFF;"', $html);
        $this->assertStringNotContainsString(':root', $html, 'a identidade do campus nunca vai para o :root');
    }

    public function testCombinacaoAbaixoDe45ParaUmENaoEhSalvaESugereAOutraCorDeTexto(): void
    {
        // Amarelo com texto claro: 2,6:1. Com texto escuro passa, então a mensagem sugere o escuro.
        $resposta = $this->como($this->direcao)->post('campus', $this->dados(['cor_primaria' => '#C99A2E', 'cor_texto' => 'claro']));

        $resposta->assertStatus(422);
        $resposta->assertSee('abaixo do mínimo de 4,5:1');
        $resposta->assertSee('Use o texto Escuro');
        $this->assertSame([], $this->campusPorNome('Fatec Jahu'));
    }

    public function testCorQueNenhumTextoAtendeEhRecusada(): void
    {
        $resposta = $this->como($this->direcao)->post('campus', $this->dados(['cor_primaria' => '#777777', 'cor_texto' => 'escuro']));

        $resposta->assertStatus(422);
        $resposta->assertSee('Nenhuma opção de texto atinge 4,5:1');
        $this->assertSame([], $this->campusPorNome('Fatec Jahu'));
    }

    public function testCorETextoAndamJuntosECorPrecisaSerHex(): void
    {
        $casos = [
            [['cor_primaria' => '#1E3A5F'], 'Escolha a cor do texto'],
            [['cor_texto' => 'claro'], '#RRGGBB'],
            [['cor_primaria' => 'red;x:y', 'cor_texto' => 'claro'], '#RRGGBB'],
        ];

        foreach ($casos as [$campos, $mensagem]) {
            $resposta = $this->como($this->direcao)->post('campus', $this->dados($campos));
            $resposta->assertStatus(422);
            $resposta->assertSee($mensagem);
        }

        $this->assertSame([], $this->campusPorNome('Fatec Jahu'));
    }

    public function testCamposObrigatoriosEFormatos(): void
    {
        $resposta = $this->como($this->direcao)->post('campus', $this->dados([
            'nome' => '', 'abreviacao' => '', 'uf' => 'São Paulo', 'cep' => '123', 'latitude' => '95',
        ]));

        $resposta->assertStatus(422);
        foreach (['Nome é obrigatório', 'Abreviação é obrigatório', 'UF não está no formato', 'CEP não está no formato', 'Latitude deve ser menor'] as $erro) {
            $resposta->assertSee($erro);
        }
    }

    public function testLogoFotoEMapaEmPdfSaoGravados(): void
    {
        ArquivosDeTeste::simularEnvio(['logo' => 'png', 'foto' => 'jpg', 'mapa' => 'pdf']);
        $this->como($this->direcao)->post('campus', $this->dados());

        $campus = $this->campusPorNome('Fatec Jahu');
        $this->assertMatchesRegularExpression('#^campus/[0-9a-f]{32}\.png$#', (string) $campus['logo']);
        $this->assertMatchesRegularExpression('#^campus/[0-9a-f]{32}\.jpg$#', (string) $campus['foto']);
        $this->assertMatchesRegularExpression('#^campus/[0-9a-f]{32}\.pdf$#', (string) $campus['mapa']);
        $this->assertFileExists($this->arquivos->raiz() . '/' . $campus['mapa']);

        ArquivosDeTeste::simularEnvio();
        $pagina = $this->como($this->direcao)->get('campus/' . $campus['id_campus']);
        $pagina->assertSee('Abrir o mapa (PDF)');
        $this->assertStringContainsString('uploads/' . $campus['logo'], (string) $pagina->getBody());
    }

    public function testArquivoInvalidoNaoSalvaNadaNemDeixaArquivoOrfao(): void
    {
        ArquivosDeTeste::simularEnvio(['logo' => 'png', 'foto' => 'falso']);
        $resposta = $this->como($this->direcao)->post('campus', $this->dados());

        $resposta->assertStatus(422);
        $resposta->assertSee('Envie uma imagem PNG, JPEG ou WebP.');
        $this->assertSame([], $this->campusPorNome('Fatec Jahu'));
        $this->assertSame([], glob($this->arquivos->raiz() . '/campus/*') ?: [], 'o logo já gravado é descartado');
    }

    public function testTrocarERemoverArquivoApagaOAntigo(): void
    {
        ArquivosDeTeste::simularEnvio(['logo' => 'png']);
        $this->como($this->direcao)->post('campus', $this->dados());
        $campus = $this->campusPorNome('Fatec Jahu');
        $antigo = $this->arquivos->raiz() . '/' . $campus['logo'];

        ArquivosDeTeste::simularEnvio(['logo' => 'png']);
        $this->como($this->direcao)->post('campus/' . $campus['id_campus'], $this->dados());
        $novo = $this->campusPorNome('Fatec Jahu')['logo'];
        $this->assertNotSame($campus['logo'], $novo);
        $this->assertFileDoesNotExist($antigo);

        ArquivosDeTeste::simularEnvio();
        $this->como($this->direcao)->post('campus/' . $campus['id_campus'], $this->dados(['remover_logo' => '1']));
        $this->assertNull($this->campusPorNome('Fatec Jahu')['logo']);
        $this->assertFileDoesNotExist($this->arquivos->raiz() . '/' . $novo);
    }

    public function testCoordenacaoDeCampusSoVeEAlteraOProprioCampusENaoCriaNemExclui(): void
    {
        $meu   = $this->criarCampus('Campus Meu');
        $outro = $this->criarCampus('Campus Outro');
        $coord = $this->criarUsuario();
        $this->vincular($coord['id_usuario'], 'Coordenação', 'campus', $meu);

        $lista = $this->como($coord)->get('campus');
        $lista->assertStatus(200);
        $lista->assertSee('Campus Meu');
        $lista->assertDontSee('Campus Outro');
        $lista->assertDontSee('Novo campus');

        $this->como($coord)->get('campus/novo')->assertStatus(403);
        $this->como($coord)->post('campus', $this->dados())->assertStatus(403);
        $this->como($coord)->get("campus/{$outro}")->assertStatus(403);
        $this->como($coord)->get("campus/{$outro}/editar")->assertStatus(403);
        $this->como($coord)->post("campus/{$outro}", $this->dados(['nome' => 'Invadido']))->assertStatus(403);

        $this->como($coord)->get("campus/{$meu}/editar")->assertStatus(200);
        $this->como($coord)->post("campus/{$meu}", $this->dados(['nome' => 'Campus Renomeado']))
            ->assertRedirectTo(site_url("campus/{$meu}"));
        $this->seeInDatabase('campus', ['id_campus' => $meu, 'nome' => 'Campus Renomeado']);

        $this->como($coord)->excluir("campus/{$meu}")->assertStatus(403);
        $this->seeInDatabase('campus', ['id_campus' => $meu, 'deletado_em' => null]);
    }

    public function testDocenteSoConsultaENuncaVeBotoesDeAlterarOuExcluir(): void
    {
        $campus  = $this->criarCampus('Campus A');
        $docente = $this->criarUsuario();
        $this->vincular($docente['id_usuario'], 'Docentes', 'campus', $campus);

        $pagina = $this->como($docente)->get("campus/{$campus}");
        $pagina->assertStatus(200);
        $html = (string) $pagina->getBody();
        $this->assertStringNotContainsString('bg-action-edit', $html);
        $this->assertDoesNotMatchRegularExpression('/<(a|button)\s[^>]*bg-signal-critical/', $html);

        $this->como($docente)->get("campus/{$campus}/editar")->assertStatus(403);
        $this->como($docente)->post("campus/{$campus}", $this->dados())->assertStatus(403);
    }

    public function testEscopoDePredioVeOCampusMasSoOSeuPredio(): void
    {
        $campus = $this->criarCampus('Campus A');
        $meu    = $this->criarPredio($campus, 'Prédio Meu');
        $this->criarPredio($campus, 'Prédio Vizinho');
        $aux = $this->criarUsuario();
        $this->vincular($aux['id_usuario'], 'Auxiliares', 'predio', null, $meu);

        $this->como($aux)->get('campus')->assertSee('Campus A');

        $pagina = $this->como($aux)->get("campus/{$campus}");
        $pagina->assertStatus(200);
        $pagina->assertSee('Prédio Meu');
        $pagina->assertDontSee('Prédio Vizinho');
    }

    public function testExcluirExigeConfirmoEBloqueiaCampusComPredios(): void
    {
        $vazio   = $this->criarCampus('Campus Vazio');
        $comFilho = $this->criarCampus('Campus Com Prédio');
        $this->criarPredio($comFilho, 'Prédio 1');

        $this->como($this->direcao)->excluir("campus/{$vazio}", 'confirmo');
        $this->seeInDatabase('campus', ['id_campus' => $vazio, 'deletado_em' => null]);

        $bloqueado = $this->como($this->direcao)->excluir("campus/{$comFilho}");
        $bloqueado->assertRedirectTo(site_url("campus/{$comFilho}"));
        $this->assertStringContainsString('1 prédio(s)', (string) $_SESSION['erro']);
        $this->seeInDatabase('campus', ['id_campus' => $comFilho, 'deletado_em' => null]);

        $this->como($this->direcao)->excluir("campus/{$vazio}")->assertRedirectTo(site_url('campus'));
        $this->assertNotNull($this->db->table('campus')->where('id_campus', $vazio)->get()->getRow()->deletado_em);
    }

    public function testFormularioTemPreviaDeContrasteEOModalDeExclusaoComConfirmo(): void
    {
        $campus = $this->criarCampus('Campus A');

        $form = (string) $this->como($this->direcao)->get("campus/{$campus}/editar")->getBody();
        $this->assertMatchesRegularExpression('/x-data=["\x27]identidadeCampus\(/', $form);
        $this->assertStringContainsString('enctype="multipart/form-data"', $form);
        $this->assertStringContainsString('bg-action-edit text-ink', $form, 'salvar uma alteração usa o botão amarelo');

        $pagina = (string) $this->como($this->direcao)->get("campus/{$campus}")->getBody();
        $this->assertMatchesRegularExpression('/<button[^>]*form="excluir-campus-form"[^>]*\sdisabled/', $pagina);
        $this->assertStringContainsString('CONFIRMO', $pagina);
    }
}

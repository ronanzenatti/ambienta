<?php

use Tests\Support\FeatureBancoTestCase;

/**
 * Formulários curtos em modal (?modal=1): o GET devolve só o formulário, erro de validação volta 422 com os
 * erros (e o novo token CSRF no cabeçalho), salvar devolve JSON com a mensagem. Sem ?modal=1, tudo continua
 * como página e redirecionamento.
 *
 * @internal
 */
final class FormularioModalTest extends FeatureBancoTestCase
{
    /** @var array<string, mixed> editor institucional */
    private array $coordenacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coordenacao = $this->criarUsuario(['nome' => 'Coordenadora']);
        $this->vincular($this->coordenacao['id_usuario'], 'Coordenação', 'campus', $this->criarCampus('Campus A'));
    }

    private function corpo(\CodeIgniter\Test\TestResponse $resposta): string
    {
        return html_entity_decode((string) $resposta->response()->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function testGetNoModalDevolveSoOFormulario(): void
    {
        $html = $this->corpo($this->como($this->coordenacao)->get('cursos/novo', ['modal' => '1']));

        $this->assertStringStartsWith('<form', ltrim((string) preg_replace('/<!--.*?-->/s', '', $html)));
        $this->assertStringContainsString('data-titulo="Novo curso"', $html);
        $this->assertStringContainsString('@click="fechar()"', $html, 'Cancelar só fecha o modal');
        $this->assertStringNotContainsString('<html', $html);

        $pagina = $this->corpo($this->como($this->coordenacao)->get('cursos/novo'));
        $this->assertStringContainsString('<html', $pagina, 'sem ?modal=1 continua sendo página');
        $this->assertStringContainsString('href="' . site_url('cursos') . '"', $pagina);
    }

    public function testErroDeValidacaoNoModalVolta422ComOFormularioEONovoToken(): void
    {
        $resposta = $this->como($this->coordenacao)->post('cursos?modal=1', ['nome' => '', 'abreviacao' => '', 'tipo' => '']);

        $resposta->assertStatus(422);
        $this->assertStringContainsString('aria-invalid="true"', $this->corpo($resposta));
        $this->assertStringNotContainsString('<html', $this->corpo($resposta));
        $this->assertNotSame('', $resposta->response()->getHeaderLine('X-Csrf-Hash'));
        $this->dontSeeInDatabase('cursos', ['nome' => '']);
    }

    public function testSalvarNoModalDevolveJsonEGrava(): void
    {
        $resposta = $this->como($this->coordenacao)->post('cursos?modal=1', [
            'nome' => 'Big Data', 'abreviacao' => 'BD', 'tipo' => 'graduacao',
            'responsavel_id' => (string) $this->coordenacao['id_usuario'],
        ]);

        $resposta->assertStatus(200);
        $resposta->assertJSONExact(['mensagem' => lang('Cursos.saved')]);
        $this->seeInDatabase('cursos', ['nome' => 'Big Data', 'deletado_em' => null]);

        $id = (int) $this->db->table('cursos')->where('nome', 'Big Data')->get()->getRow()->id_curso;
        $this->como($this->coordenacao)->post("cursos/{$id}?modal=1", [
            'nome' => 'Big Data 2', 'abreviacao' => 'BD', 'tipo' => 'graduacao',
            'responsavel_id' => (string) $this->coordenacao['id_usuario'],
        ])
            ->assertJSONExact(['mensagem' => lang('Cursos.saved')]);
        $this->seeInDatabase('cursos', ['id_curso' => $id, 'nome' => 'Big Data 2']);
    }

    public function testSemModalSalvarContinuaRedirecionando(): void
    {
        $this->como($this->coordenacao)->post('categorias-ambiente', ['nome' => 'Oficina'])
            ->assertRedirectTo(site_url('categorias-ambiente'));

        $this->como($this->coordenacao)->get('categorias-ambiente/novo')->assertStatus(200);
    }

    public function testListaTemOModalEOsLinksQueOAbrem(): void
    {
        $this->criarCategoria('Sala');

        $html = $this->corpo($this->como($this->coordenacao)->get('categorias-ambiente'));

        $this->assertStringContainsString('x-data="ambientaFormulario()"', $html);
        $this->assertStringContainsString('href="' . site_url('categorias-ambiente/novo') . '"', $html);
        $this->assertMatchesRegularExpression('/<a [^>]*@click="ambientaAbrirFormulario\(\$event\)"[^>]*aria-label="Alterar: Sala"/', $html);
        $this->assertStringNotContainsString('name="nome"', $html, 'o formulário não fica mais solto na página');

        // Campus tem formulário grande (endereço, arquivos): continua em página.
        $campus = $this->corpo($this->como($this->coordenacao)->get('campus'));
        $this->assertStringNotContainsString('ambientaFormulario()', $campus);
    }

    public function testDominioNoModal(): void
    {
        $admin = $this->criarUsuario(['nome' => 'Super']);
        $this->vincular($admin['id_usuario'], 'Direção', 'global');

        $invalido = $this->como($admin)->post('dominios?modal=1', ['dominio' => 'sem ponto']);
        $invalido->assertStatus(422);
        $this->assertStringContainsString(lang('Dominios.invalid'), $this->corpo($invalido));

        $this->como($admin)->post('dominios?modal=1', ['dominio' => 'fatec.sp.gov.br'])
            ->assertJSONExact(['mensagem' => lang('Dominios.added')]);
        $this->seeInDatabase('dominios_email_permitidos', ['dominio' => 'fatec.sp.gov.br']);
    }
}

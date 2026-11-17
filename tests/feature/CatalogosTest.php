<?php

use Tests\Support\FeatureBancoTestCase;

/**
 * Catálogos globais (categorias de ambiente, cursos, disciplinas com vínculo a cursos, semestres):
 * editor da permissão institucional em qualquer escopo edita, leitor só consulta.
 *
 * @internal
 */
final class CatalogosTest extends FeatureBancoTestCase
{
    /** @var array<string, mixed> Coordenação com escopo só de campus: editor institucional */
    private array $coordenacao;

    /** @var array<string, mixed> Docente: leitor institucional */
    private array $docente;

    protected function setUp(): void
    {
        parent::setUp();

        $campus = $this->criarCampus('Campus A');

        $this->coordenacao = $this->criarUsuario(['nome' => 'Coordenadora']);
        $this->vincular($this->coordenacao['id_usuario'], 'Coordenação', 'campus', $campus);

        $this->docente = $this->criarUsuario(['nome' => 'Docente']);
        $this->vincular($this->docente['id_usuario'], 'Docentes', 'campus', $campus);
    }

    public function testEditorComEscopoDeCampusGereCatalogosELeitorSoConsulta(): void
    {
        foreach (['categorias-ambiente', 'cursos', 'disciplinas', 'semestres'] as $rota) {
            $this->como($this->docente)->get($rota)->assertStatus(200);
            $this->como($this->coordenacao)->get($rota)->assertStatus(200);
        }

        foreach (['cursos/novo', 'disciplinas/novo', 'semestres/novo'] as $rota) {
            $this->como($this->docente)->get($rota)->assertStatus(403);
            $this->como($this->coordenacao)->get($rota)->assertStatus(200);
        }

        $this->como($this->docente)->post('categorias-ambiente', ['nome' => 'Invasora'])->assertStatus(403);
        $this->como($this->docente)->post('cursos', ['nome' => 'X', 'abreviacao' => 'X', 'tipo' => 'graduacao'])->assertStatus(403);
        $this->dontSeeInDatabase('categorias_ambientes', ['nome' => 'Invasora']);

        $lista = (string) $this->como($this->docente)->get('categorias-ambiente')->getBody();
        $this->assertStringNotContainsString('name="nome"', $lista, 'leitor não vê o formulário de criação');
    }

    public function testCategoriaTemNomeUnicoEntreAsAtivasENaoSaiEmUso(): void
    {
        $this->como($this->coordenacao)->post('categorias-ambiente', ['nome' => 'Laboratório', 'descricao' => 'Com computadores'])
            ->assertRedirectTo(site_url('categorias-ambiente'));

        $repetida = $this->como($this->coordenacao)->post('categorias-ambiente', ['nome' => 'Laboratório']);
        $repetida->assertStatus(422);
        $repetida->assertSee('Já existe uma categoria com este nome.');

        $id = (int) $this->db->table('categorias_ambientes')->where('nome', 'Laboratório')->get()->getRow()->id_categoria_ambiente;
        $this->criarAmbiente($this->criarPredio($this->criarCampus('Campus B'), 'P1'), '101', 'Sala', $id);

        $this->como($this->coordenacao)->excluir("categorias-ambiente/{$id}");
        $this->assertStringContainsString('1 ambiente(s)', (string) $_SESSION['erro']);
        $this->seeInDatabase('categorias_ambientes', ['id_categoria_ambiente' => $id, 'deletado_em' => null]);

        $livre = $this->criarCategoria('Auditório');
        $this->como($this->coordenacao)->excluir("categorias-ambiente/{$livre}")->assertRedirectTo(site_url('categorias-ambiente'));

        // Soft delete libera o nome (coluna nome_unico do schema).
        $this->como($this->coordenacao)->post('categorias-ambiente', ['nome' => 'Auditório'])->assertRedirect();
        $this->assertSame(2, $this->db->table('categorias_ambientes')->where('nome', 'Auditório')->countAllResults());
    }

    public function testCategoriaPodeSerRenomeada(): void
    {
        $id = $this->criarCategoria('Sala');

        $this->como($this->coordenacao)->get("categorias-ambiente/{$id}/editar")->assertStatus(200);
        $this->como($this->coordenacao)->post("categorias-ambiente/{$id}", ['nome' => 'Sala de aula', 'descricao' => ''])->assertRedirect();
        $this->seeInDatabase('categorias_ambientes', ['id_categoria_ambiente' => $id, 'nome' => 'Sala de aula', 'descricao' => null]);
    }

    public function testCursoValidaTipoEMostraRotuloTraduzido(): void
    {
        $invalido = $this->como($this->coordenacao)->post('cursos', ['nome' => 'ADS', 'abreviacao' => 'ADS', 'tipo' => 'mestrado']);
        $invalido->assertStatus(422);
        $invalido->assertSee('Tipo deve ser um destes valores');

        $this->como($this->coordenacao)->post('cursos', [
            'nome' => 'Análise e Desenvolvimento de Sistemas', 'abreviacao' => 'ADS', 'descricao' => 'Tecnólogo', 'tipo' => 'graduacao',
            'responsavel_id' => (string) $this->coordenacao['id_usuario'],
        ])->assertRedirectTo(site_url('cursos'));

        $lista = $this->como($this->coordenacao)->get('cursos');
        $lista->assertSee('Análise e Desenvolvimento de Sistemas');
        $lista->assertSee('Graduação');

        $this->como($this->coordenacao, ['idioma' => 'en'])->get('cursos')->assertSee('Undergraduate');
    }

    public function testDisciplinaPertenceAUmCursoComSiglaUnicaNele(): void
    {
        $ads = $this->criarCurso('ADS');
        $gti = $this->criarCurso('GTI');

        $this->como($this->coordenacao)->post('disciplinas', ['curso_id' => (string) $ads, 'sigla' => 'IAL', 'nome' => 'Algoritmos'])
            ->assertRedirect();
        $id = (int) $this->db->table('disciplinas')->where('sigla', 'IAL')->get()->getRow()->id_disciplina;
        $this->seeInDatabase('disciplinas', ['id_disciplina' => $id, 'curso_id' => $ads]);

        // Mesma sigla no mesmo curso: recusada; em outro curso: aceita.
        $repetida = $this->como($this->coordenacao)->post('disciplinas', ['curso_id' => (string) $ads, 'sigla' => 'IAL', 'nome' => 'Outra']);
        $repetida->assertStatus(422);
        $repetida->assertSee('Já existe uma disciplina com esta sigla neste curso.');
        $this->como($this->coordenacao)->post('disciplinas', ['curso_id' => (string) $gti, 'sigla' => 'IAL', 'nome' => 'Algoritmos GTI'])
            ->assertRedirect();

        // Curso obrigatório e existente.
        $this->como($this->coordenacao)->post('disciplinas', ['curso_id' => '999999', 'sigla' => 'X', 'nome' => 'X'])->assertStatus(422);
        $this->como($this->coordenacao)->post('disciplinas', ['sigla' => 'Y', 'nome' => 'Y'])->assertStatus(422);

        $this->como($this->coordenacao)->get("disciplinas/{$id}")->assertSee('ADS');

        // Com disciplina cadastrada, o curso não sai.
        $this->como($this->coordenacao)->excluir("cursos/{$ads}");
        $this->seeInDatabase('cursos', ['id_curso' => $ads, 'deletado_em' => null]);
        $this->assertStringContainsString('disciplina(s)', (string) $_SESSION['erro']);
    }

    public function testSemestreValidaDatasNomeUnicoEStatus(): void
    {
        $dados = ['nome' => '2026/2', 'data_inicio' => '2026-08-01', 'data_fim' => '2026-12-15', 'status' => 'aberto'];

        $invertido = $this->como($this->coordenacao)->post('semestres', ['data_fim' => '2026-07-01'] + $dados);
        $invertido->assertStatus(422);
        $invertido->assertSee('O fim precisa ser depois do início.');

        $this->como($this->coordenacao)->post('semestres', ['status' => 'fechado'] + $dados)->assertStatus(422);
        $this->como($this->coordenacao)->post('semestres', ['data_inicio' => '01/08/2026'] + $dados)->assertStatus(422);

        $this->como($this->coordenacao)->post('semestres', $dados)->assertRedirectTo(site_url('semestres'));
        $this->como($this->coordenacao)->post('semestres', $dados)->assertSee('Já existe um semestre com este nome.');

        $lista = $this->como($this->coordenacao)->get('semestres');
        $lista->assertSee('01/08/2026');
        $lista->assertSee('Aberto');
        $this->assertStringContainsString('bg-signal-confirmed', (string) $lista->getBody(), 'status como ponto + rótulo');

        $id = (int) $this->db->table('semestres')->where('nome', '2026/2')->get()->getRow()->id_semestre;
        $this->como($this->coordenacao)->post("semestres/{$id}", ['status' => 'encerrado'] + $dados)->assertRedirect();
        $this->seeInDatabase('semestres', ['id_semestre' => $id, 'status' => 'encerrado']);
    }

    public function testSemestreComAlocacaoNaoSai(): void
    {
        $semestre = $this->criarSemestre('2026/2');
        $this->db->table('alocacoes_semestrais')->insert([
            'semestre_id'   => $semestre,
            'disciplina_id' => $this->criarDisciplina('ILP', 'Lógica'),
            'professor_id'  => $this->criarUsuario()['id_usuario'],
            'ambiente_id'   => $this->criarAmbiente($this->criarPredio($this->criarCampus('C'), 'P'), '1', 'Sala'),
            'dia_semana'    => 'terca',
            'hora_inicio'   => '19:00:00',
            'hora_fim'      => '20:40:00',
        ]);

        $this->como($this->coordenacao)->excluir("semestres/{$semestre}");
        $this->seeInDatabase('semestres', ['id_semestre' => $semestre, 'deletado_em' => null]);

        $livre = $this->criarSemestre('2027/1', 'planejado');
        $this->como($this->coordenacao)->excluir("semestres/{$livre}")->assertRedirectTo(site_url('semestres'));
        $this->assertNotNull($this->db->table('semestres')->where('id_semestre', $livre)->get()->getRow()->deletado_em);
    }
}

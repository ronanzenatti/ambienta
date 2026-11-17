<?php

use Tests\Support\FeatureBancoTestCase;

/**
 * Grupo Acadêmico: responsável do curso (select de pessoas com busca por nome, e-mail ou CPF), Atribuições de
 * aulas (quem vê e quem gere), permissões academico.* e o menu lateral.
 *
 * @internal
 */
final class AcademicoTest extends FeatureBancoTestCase
{
    private int $campus;
    private int $semestre;

    /** @var array<string, mixed> */
    private array $coordenador;

    /** @var array<string, mixed> */
    private array $responsavel;

    /** @var array<string, mixed> */
    private array $professor;

    /** @var array<string, mixed> */
    private array $outroProfessor;

    private int $curso;
    private int $disciplina;
    private int $disciplinaDeOutroCurso;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus   = $this->criarCampus('Campus A');
        $this->semestre = $this->semestreAberto();

        $this->coordenador = $this->criarUsuario(['nome' => 'Coordenadora Dora', 'cpf' => '52998224725']);
        $this->vincular($this->coordenador['id_usuario'], 'Coordenação', 'campus', $this->campus);

        $this->responsavel = $this->criarUsuario(['nome' => 'Responsável Rui', 'email' => 'rui@fatec.sp.gov.br']);
        $this->vincular($this->responsavel['id_usuario'], 'Docentes', 'campus', $this->campus);

        $this->professor = $this->criarUsuario(['nome' => 'Professora Ana', 'cpf' => '11144477735']);
        $this->vincular($this->professor['id_usuario'], 'Docentes', 'campus', $this->campus);

        $this->outroProfessor = $this->criarUsuario(['nome' => 'Professor Beto']);
        $this->vincular($this->outroProfessor['id_usuario'], 'Docentes', 'campus', $this->campus);

        $this->curso                  = $this->criarCurso('Curso do Rui', 'graduacao', $this->responsavel['id_usuario']);
        $this->disciplina             = $this->criarDisciplina('ALG', 'Algoritmos', $this->curso);
        $this->disciplinaDeOutroCurso = $this->criarDisciplina('BD', 'Banco de Dados');
    }

    private function semestreAberto(): int
    {
        $this->db->table('semestres')->insert([
            'nome'        => 'Semestre de teste',
            'data_inicio' => date('Y-m-d', strtotime('-30 days')),
            'data_fim'    => date('Y-m-d', strtotime('+180 days')),
            'status'      => 'aberto',
        ]);

        return (int) $this->db->insertID();
    }

    /** @return array<string, string> */
    private function dados(int $disciplina, array $professor): array
    {
        return [
            'semestre_id'   => (string) $this->semestre,
            'disciplina_id' => (string) $disciplina,
            'professor_id'  => (string) $professor['id_usuario'],
        ];
    }

    private function atribuir(int $disciplina, array $professor): void
    {
        $this->db->table('professores_disciplinas')->insert([
            'semestre_id'   => $this->semestre,
            'disciplina_id' => $disciplina,
            'professor_id'  => $professor['id_usuario'],
        ]);
    }

    public function testCursoExigeResponsavelValidoEMostraNaListagem(): void
    {
        $dados = ['nome' => 'ADS', 'abreviacao' => 'ADS', 'tipo' => 'graduacao'];

        $this->como($this->coordenador)->post('cursos', $dados)->assertStatus(422);
        $this->como($this->coordenador)->post('cursos', $dados + ['responsavel_id' => '999999'])->assertStatus(422);

        $this->como($this->coordenador)->post('cursos', $dados + ['responsavel_id' => (string) $this->responsavel['id_usuario']])
            ->assertRedirectTo(site_url('cursos'));
        $this->seeInDatabase('cursos', ['nome' => 'ADS', 'responsavel_id' => $this->responsavel['id_usuario']]);

        $this->como($this->coordenador)->get('cursos')->assertSee('Responsável Rui');
    }

    public function testSelectDePessoasPesquisaPorNomeEmailECpfEMostraOsTres(): void
    {
        $busca = function (string $q): array {
            $resposta = $this->como($this->coordenador)->get('opcoes/pessoas', ['q' => $q]);
            $resposta->assertStatus(200);

            return array_column(json_decode((string) $resposta->response()->getBody(), true)['itens'], 'rotulo');
        };

        $porNome = $busca('Professora Ana');
        $this->assertCount(1, $porNome);
        $this->assertStringContainsString($this->professor['email'], $porNome[0]);
        $this->assertStringContainsString('111.444.777-35', $porNome[0]);

        $this->assertCount(1, $busca($this->professor['email']));
        $this->assertCount(1, $busca('11144477735'), 'CPF sem pontuação');
        $this->assertCount(1, $busca('111.444.777-35'), 'CPF com pontuação');
    }

    public function testSelectDePessoasExigePermissaoOuResponsabilidade(): void
    {
        $semDireito = $this->criarUsuario(['nome' => 'Sem direito']);
        $this->como($semDireito)->get('opcoes/pessoas')->assertStatus(403);
        $this->como($this->professor)->get('opcoes/pessoas')->assertStatus(403);

        // O responsável de um curso escolhe pessoas para as atribuições dele.
        $this->como($this->responsavel)->get('opcoes/pessoas')->assertStatus(200);
    }

    public function testResponsavelDoCursoGereAtribuicoesSoDoProprioCurso(): void
    {
        $this->como($this->professor)->get('atribuicoes/novo')->assertStatus(403);
        $this->como($this->professor)->post('atribuicoes', $this->dados($this->disciplina, $this->professor))->assertStatus(403);

        $this->como($this->responsavel)->get('atribuicoes/novo')->assertStatus(200);
        $this->como($this->responsavel)->post('atribuicoes', $this->dados($this->disciplina, $this->professor))
            ->assertRedirectTo(site_url('atribuicoes'));
        $this->seeInDatabase('professores_disciplinas', [
            'disciplina_id' => $this->disciplina, 'professor_id' => $this->professor['id_usuario'], 'deletado_em' => null,
        ]);

        // Disciplina de outro curso: fora do que ele gere.
        $fora = $this->como($this->responsavel)->post('atribuicoes', $this->dados($this->disciplinaDeOutroCurso, $this->professor));
        $fora->assertStatus(422);
        $this->dontSeeInDatabase('professores_disciplinas', ['disciplina_id' => $this->disciplinaDeOutroCurso]);

        // Quem tem academico.atribuicoes (editor) gere qualquer curso.
        $this->como($this->coordenador)->post('atribuicoes', $this->dados($this->disciplinaDeOutroCurso, $this->professor))
            ->assertRedirectTo(site_url('atribuicoes'));
    }

    public function testExcluirAtribuicaoExigeGerirOCursoDaDisciplina(): void
    {
        $this->atribuir($this->disciplina, $this->professor);
        $id = (int) $this->db->table('professores_disciplinas')->get()->getRow()->id_professor_disciplina;

        $this->como($this->professor)->excluir("atribuicoes/{$id}")->assertStatus(403);
        $this->seeInDatabase('professores_disciplinas', ['id_professor_disciplina' => $id, 'deletado_em' => null]);

        $this->como($this->responsavel)->excluir("atribuicoes/{$id}")
            ->assertRedirectTo(site_url('atribuicoes'));
        $this->dontSeeInDatabase('professores_disciplinas', ['id_professor_disciplina' => $id, 'deletado_em' => null]);
    }

    public function testListagemMostraSoAsAtribuicoesRelacionadasAoUsuario(): void
    {
        $this->atribuir($this->disciplina, $this->professor);
        $this->atribuir($this->disciplinaDeOutroCurso, $this->outroProfessor);

        // O professor vê só a própria; o responsável do curso vê as do curso dele; a coordenação vê todas.
        $propria = (string) $this->como($this->professor)->get('atribuicoes')->getBody();
        $this->assertStringContainsString('Algoritmos', $propria);
        $this->assertStringNotContainsString('Banco de Dados', $propria);

        $doCurso = (string) $this->como($this->responsavel)->get('atribuicoes')->getBody();
        $this->assertStringContainsString('Algoritmos', $doCurso);
        $this->assertStringNotContainsString('Banco de Dados', $doCurso);

        $todas = (string) $this->como($this->coordenador)->get('atribuicoes')->getBody();
        $this->assertStringContainsString('Algoritmos', $todas);
        $this->assertStringContainsString('Banco de Dados', $todas);
    }

    public function testImportacaoDoResponsavelCopiaSoOsCursosDele(): void
    {
        $this->atribuir($this->disciplina, $this->professor);
        $this->atribuir($this->disciplinaDeOutroCurso, $this->outroProfessor);

        $this->db->table('semestres')->insert([
            'nome'        => 'Próximo',
            'data_inicio' => date('Y-m-d', strtotime('+181 days')),
            'data_fim'    => date('Y-m-d', strtotime('+360 days')),
            'status'      => 'planejado',
        ]);
        $novo = (int) $this->db->insertID();

        $this->como($this->responsavel)->post('atribuicoes/importar', [
            'origem_id' => (string) $this->semestre, 'destino_id' => (string) $novo,
        ])->assertRedirectTo(site_url('atribuicoes'));

        $this->seeInDatabase('professores_disciplinas', ['semestre_id' => $novo, 'disciplina_id' => $this->disciplina]);
        $this->dontSeeInDatabase('professores_disciplinas', ['semestre_id' => $novo, 'disciplina_id' => $this->disciplinaDeOutroCurso]);
    }

    public function testProfessorAbreRequisitosPelaAtribuicaoSoDasDisciplinasDele(): void
    {
        $this->atribuir($this->disciplina, $this->professor);

        $this->como($this->professor)->get('atribuicoes')->assertSee('Algoritmos');
        $this->como($this->professor)->get("atribuicoes/{$this->disciplina}/requisitos")->assertRedirect();
        $this->seeInDatabase('requisitos_disciplina', [
            'professor_id' => $this->professor['id_usuario'], 'disciplina_id' => $this->disciplina,
        ]);

        $this->como($this->professor)->get("atribuicoes/{$this->disciplinaDeOutroCurso}/requisitos")
            ->assertRedirectTo(site_url('atribuicoes'));
    }

    public function testTelasAntigasDeVinculosNaoExistemMais(): void
    {
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->como($this->professor)->get('minhas-disciplinas');
    }

    public function testMenuTemGrupoAcademicoComAsTelasMovidas(): void
    {
        $menu = html_entity_decode((string) $this->como($this->coordenador)->get('/')->getBody());

        $this->assertStringContainsString('submenu-curso', $menu);
        foreach (['Cursos', 'Disciplinas', 'Semestres', 'Atribuições de aulas', 'Requisitos de disciplina'] as $item) {
            $this->assertStringContainsString($item, $menu);
        }
        $this->assertStringNotContainsString('Minhas disciplinas', $menu);
        $this->assertStringNotContainsString('Vínculos com professores', $menu);
    }

    public function testRequisitosPassamAExigirAcademicoRequisitos(): void
    {
        $this->como($this->professor)->get('requisitos')->assertStatus(200);

        $semPermissao = $this->criarPerfil('Só chamados', ['chamados.acesso' => 'editor']);
        $usuario      = $this->criarUsuario(['nome' => 'Só chamados']);
        $this->db->table('usuarios_perfis')->insert([
            'usuario_id' => $usuario['id_usuario'], 'perfil_id' => $semPermissao, 'escopo_tipo' => 'global',
        ]);

        $this->como($usuario)->get('requisitos')->assertStatus(403);
        $this->como($usuario)->get('chamados')->assertStatus(200);
    }
}

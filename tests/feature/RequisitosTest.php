<?php

use Tests\Support\FeatureBancoTestCase;

/**
 * Requisitos de disciplina pela interface: docente cuida só dos próprios, quem tem
 * chamados.resolver_cancelar cuida dos de todos; itens e softwares na página do requisito (remoção com
 * CONFIRMO) e a verificação de um ambiente, que só sinaliza.
 *
 * @internal
 */
final class RequisitosTest extends FeatureBancoTestCase
{
    private int $campus;
    private int $ambiente;
    private int $disciplina;

    /** @var array<string, mixed> */
    private array $docente;

    /** @var array<string, mixed> */
    private array $outroDocente;

    /** @var array<string, mixed> gere requisitos de todos */
    private array $coordenacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus     = $this->criarCampus('Campus A');
        $this->ambiente   = $this->criarAmbiente($this->criarPredio($this->campus, 'Prédio 1'), '101', 'Laboratório 1');
        $this->disciplina = $this->criarDisciplina('IAL', 'Algoritmos');

        $this->docente = $this->criarUsuario(['nome' => 'Professora Ana']);
        $this->vincular($this->docente['id_usuario'], 'Docentes', 'campus', $this->campus);

        $this->outroDocente = $this->criarUsuario(['nome' => 'Professor Beto']);
        $this->vincular($this->outroDocente['id_usuario'], 'Docentes', 'campus', $this->campus);

        $this->coordenacao = $this->criarUsuario(['nome' => 'Coordenadora']);
        $this->vincular($this->coordenacao['id_usuario'], 'Coordenação', 'campus', $this->campus);

        $semestre = $this->criarSemestre('2026/2');

        foreach ([$this->docente, $this->outroDocente] as $professor) {
            $this->db->table('professores_disciplinas')->insert([
                'semestre_id'   => $semestre,
                'disciplina_id' => $this->disciplina,
                'professor_id'  => $professor['id_usuario'],
            ]);
        }
    }

    public function testDisciplinaSoDeQuemLecionaAMateria(): void
    {
        $outra = $this->criarDisciplina('BDD', 'Banco de Dados');

        // Professor sem atribuição para a disciplina é recusado.
        $r = $this->como($this->coordenacao)->post('requisitos?modal=1', [
            'professor_id'      => (string) $this->docente['id_usuario'],
            'disciplina_id'     => (string) $outra,
            'capacidade_minima' => '10',
        ]);
        $r->assertStatus(422);
        $this->dontSeeInDatabase('requisitos_disciplina', ['disciplina_id' => $outra]);

        $fonte = $this->como($this->coordenacao)
            ->get('opcoes/disciplinas-do-professor?chave=chamados.resolver_cancelar&nivel=editor&professor=' . $this->docente['id_usuario']);
        $fonte->assertSee('Algoritmos');
        $fonte->assertDontSee('Banco de Dados');
    }

    /** @param array<string, mixed> $usuario */
    private function requisitoDe(array $usuario, int $capacidade = 30): int
    {
        $this->db->table('requisitos_disciplina')->insert([
            'professor_id'      => $usuario['id_usuario'],
            'disciplina_id'     => $this->disciplina,
            'capacidade_minima' => $capacidade,
        ]);

        return (int) $this->db->insertID();
    }

    public function testDocenteCriaSoParaSiMesmo(): void
    {
        // O professor postado é ignorado: sem permissão para gerir os de outros, o requisito é do próprio.
        $this->como($this->docente)->post('requisitos', [
            'professor_id'      => (string) $this->outroDocente['id_usuario'],
            'disciplina_id'     => (string) $this->disciplina,
            'capacidade_minima' => '35',
        ])->assertRedirect();

        $this->seeInDatabase('requisitos_disciplina', [
            'professor_id' => $this->docente['id_usuario'], 'disciplina_id' => $this->disciplina, 'capacidade_minima' => 35,
        ]);

        // Duplicado (mesmo professor e disciplina) é recusado com mensagem, e o modal continua aberto.
        $r = $this->como($this->docente)->post('requisitos?modal=1', [
            'disciplina_id' => (string) $this->disciplina, 'capacidade_minima' => '10',
        ]);
        $r->assertStatus(422);
        $r->assertSee('já tem um requisito');
    }

    public function testCoordenacaoEscolheOProfessor(): void
    {
        // O professor vem de uma lista sob demanda (opcoes/usuarios): a tela só traz o select.
        $tela = $this->como($this->coordenacao)->get('requisitos/novo');
        $tela->assertSee('Professor');
        $this->assertStringContainsString('opcoes/usuarios', (string) $tela->getBody());

        $r = $this->como($this->coordenacao)->post('requisitos?modal=1', [
            'professor_id'      => (string) $this->outroDocente['id_usuario'],
            'disciplina_id'     => (string) $this->disciplina,
            'capacidade_minima' => '',
        ]);
        $r->assertOK();
        $r->assertJSONFragment(['mensagem' => 'Requisito salvo.']);
        // Recém-criado: o modal leva à página do requisito, para informar itens e softwares.
        $this->assertStringContainsString('/requisitos/', (string) json_decode($r->getJSON(), true)['redirecionar']);

        $this->seeInDatabase('requisitos_disciplina', ['professor_id' => $this->outroDocente['id_usuario'], 'capacidade_minima' => 0]);
    }

    public function testDocenteNaoAlteraRequisitoDeOutro(): void
    {
        $id = $this->requisitoDe($this->outroDocente);

        $lista = $this->como($this->docente)->get('requisitos');
        $lista->assertSee('Algoritmos');
        $this->assertStringNotContainsString("requisitos/{$id}/editar", (string) $lista->getBody());

        $r = $this->como($this->docente)->get("requisitos/{$id}");
        $r->assertOK();
        $r->assertDontSee('Adicionar item');

        $this->como($this->docente)->get("requisitos/{$id}/editar")->assertStatus(403);
        $this->como($this->docente)->post("requisitos/{$id}", ['disciplina_id' => (string) $this->disciplina, 'capacidade_minima' => '1'])->assertStatus(403);
        $this->como($this->docente)->excluir("requisitos/{$id}")->assertStatus(403);

        $this->como($this->coordenacao)->post("requisitos/{$id}", [
            'professor_id'      => (string) $this->outroDocente['id_usuario'],
            'disciplina_id'     => (string) $this->disciplina,
            'capacidade_minima' => '50',
        ])->assertRedirect();
        $this->seeInDatabase('requisitos_disciplina', ['id_requisito_disciplina' => $id, 'capacidade_minima' => 50]);
    }

    public function testItensESoftwaresComRemocaoPorConfirmo(): void
    {
        $id        = $this->requisitoDe($this->docente);
        $categoria = $this->criarCategoriaItem('Computadores');
        $this->db->table('softwares')->insert(['nome' => 'VS Code', 'versao' => '1.90']);
        $software = (int) $this->db->insertID();

        $this->como($this->docente)->post("requisitos/{$id}/itens", ['categoria_item_id' => (string) $categoria, 'quantidade' => '0'])->assertStatus(422);
        $this->como($this->docente)->post("requisitos/{$id}/itens", ['categoria_item_id' => (string) $categoria, 'quantidade' => '20'])->assertRedirect();
        // Mesma categoria de novo: atualiza a quantidade, não duplica.
        $this->como($this->docente)->post("requisitos/{$id}/itens", ['categoria_item_id' => (string) $categoria, 'quantidade' => '25'])->assertRedirect();
        $this->assertSame(1, $this->db->table('requisitos_disciplina_itens')->where('deletado_em', null)->countAllResults());
        $this->seeInDatabase('requisitos_disciplina_itens', ['requisito_disciplina_id' => $id, 'quantidade' => 25]);

        $this->como($this->docente)->post("requisitos/{$id}/softwares", ['software_id' => (string) $software])->assertRedirect();
        $this->como($this->docente)->post("requisitos/{$id}/softwares", ['software_id' => (string) $software])->assertStatus(422);

        $r = $this->como($this->docente)->get("requisitos/{$id}");
        $r->assertSee('Computadores');
        $r->assertSee('Quantidade');
        $r->assertSee('VS Code 1.90');

        $item = (int) $this->db->table('requisitos_disciplina_itens')->get()->getRow()->id_requisito_disciplina_item;
        $this->como($this->docente)->excluir("requisitos/{$id}/itens/{$item}", 'confirmo');
        $this->seeInDatabase('requisitos_disciplina_itens', ['id_requisito_disciplina_item' => $item, 'deletado_em' => null]);
        $this->como($this->docente)->excluir("requisitos/{$id}/itens/{$item}")->assertRedirect();
        $this->dontSeeInDatabase('requisitos_disciplina_itens', ['id_requisito_disciplina_item' => $item, 'deletado_em' => null]);

        // Excluir o requisito leva junto os detalhes (soft delete).
        $this->como($this->docente)->excluir("requisitos/{$id}")->assertRedirect();
        $this->dontSeeInDatabase('requisitos_disciplina', ['id_requisito_disciplina' => $id, 'deletado_em' => null]);
        $this->dontSeeInDatabase('requisitos_disciplina_softwares', ['requisito_disciplina_id' => $id, 'deletado_em' => null]);
    }

    public function testVerificarAmbienteSoSinaliza(): void
    {
        $id = $this->requisitoDe($this->docente, 45);

        $r = $this->como($this->docente)->get("requisitos/{$id}/verificar?ambiente_id={$this->ambiente}");
        $r->assertSee('não atende a todos os requisitos');
        $r->assertSee('Capacidade: exige 45, o ambiente comporta 40.');

        $this->db->table('ambientes')->where('id_ambiente', $this->ambiente)->update(['capacidade' => 45]);

        $this->como($this->docente)->get("requisitos/{$id}/verificar?ambiente_id={$this->ambiente}")
            ->assertSee('atende a todos os requisitos');
    }

    public function testAdicionarEmModalRecarregaSoATabelaDoCartao(): void
    {
        $id        = $this->requisitoDe($this->docente);
        $categoria = $this->criarCategoriaItem('Computadores');

        // Formulário do modal: aponta a tabela do cartão a recarregar.
        $this->como($this->docente)->get("requisitos/{$id}/itens/novo?modal=1")->assertSee('data-tabela="requisito-itens"');
        $this->como($this->docente)->get("requisitos/{$id}/softwares/propor?modal=1")->assertSee('data-tabela="requisito-softwares"');

        // Erro de validação mantém o modal aberto (422); salvo devolve JSON.
        $this->como($this->docente)->post("requisitos/{$id}/itens?modal=1", ['categoria_item_id' => (string) $categoria, 'quantidade' => '0'])->assertStatus(422);
        $r = $this->como($this->docente)->post("requisitos/{$id}/itens?modal=1", ['categoria_item_id' => (string) $categoria, 'quantidade' => '12']);
        $r->assertOK();
        $r->assertJSONFragment(['mensagem' => 'Item adicionado ao requisito.']);

        $fragmento = $this->como($this->docente)->get("requisitos/{$id}/itens?parcial=1");
        $fragmento->assertSee('Computadores');
        $fragmento->assertSee('12');

        $this->como($this->docente)->post("requisitos/{$id}/softwares/propor?modal=1", ['nome' => 'Blender', 'versao' => '4.2'])->assertOK();
        $this->como($this->docente)->get("requisitos/{$id}/softwares?parcial=1")->assertSee('Blender 4.2');

        // Quem só consulta não abre os formulários.
        $this->como($this->outroDocente)->get("requisitos/{$id}/itens/novo?modal=1")->assertStatus(403);
    }

    public function testQuemNaoTemAcessoAoModuloNaoEntra(): void
    {
        $this->criarPerfil('Só patrimônio', ['patrimonio.acesso' => 'editor']);
        $semAcesso = $this->criarUsuario();
        $this->vincular($semAcesso['id_usuario'], 'Só patrimônio', 'campus', $this->campus);

        $this->como($semAcesso)->get('requisitos')->assertStatus(403);
        $this->como($semAcesso)->get('chamados')->assertStatus(403);
    }
}

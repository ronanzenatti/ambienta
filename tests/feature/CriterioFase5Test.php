<?php

use Tests\Support\FeatureBancoTestCase;

/**
 * Critério de conclusão da Fase 5: abrir um chamado, mudar seu status e ver o histórico completo
 * registrado. Mais as regras de quem pode o quê e o escopo do ambiente.
 *
 * @internal
 */
final class CriterioFase5Test extends FeatureBancoTestCase
{
    private int $campus;
    private int $laboratorio;
    private int $salaOutroCampus;

    /** @var array<string, mixed> abre chamados; só cancela o próprio */
    private array $docente;

    /** @var array<string, mixed> resolve e cancela chamados no campus */
    private array $auxiliar;

    /** @var array<string, mixed> auxiliar de outro campus */
    private array $auxiliarB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus          = $this->criarCampus('Campus A');
        $this->laboratorio     = $this->criarAmbiente($this->criarPredio($this->campus, 'Prédio 1'), '101', 'Laboratório 1');
        $campusB               = $this->criarCampus('Campus B');
        $this->salaOutroCampus = $this->criarAmbiente($this->criarPredio($campusB, 'Prédio B'), '201', 'Sala B');

        $this->docente = $this->criarUsuario(['nome' => 'Professora Ana']);
        $this->vincular($this->docente['id_usuario'], 'Docentes', 'campus', $this->campus);

        $this->auxiliar = $this->criarUsuario(['nome' => 'Auxiliar Caio']);
        $this->vincular($this->auxiliar['id_usuario'], 'Auxiliares', 'campus', $this->campus);

        $this->auxiliarB = $this->criarUsuario(['nome' => 'Auxiliar Dora']);
        $this->vincular($this->auxiliarB['id_usuario'], 'Auxiliares', 'campus', $campusB);
    }

    private function abrir(): int
    {
        $r = $this->como($this->docente)->post('chamados?modal=1', [
            'ambiente_id' => (string) $this->laboratorio,
            'descricao'   => 'Faltam 5 computadores para a aula de Algoritmos',
        ]);
        $r->assertOK();
        $r->assertJSONFragment(['mensagem' => 'Chamado #1 aberto.']);

        return (int) $this->db->table('chamados')->get()->getRow()->id_chamado;
    }

    public function testAbrirMudarStatusEVerOHistoricoCompleto(): void
    {
        $id = $this->abrir();

        // Observação é obrigatória.
        $this->como($this->auxiliar)->post("chamados/{$id}/situacao?modal=1", ['status' => 'em_andamento', 'observacao' => ''])
            ->assertStatus(422);
        $this->seeInDatabase('chamados', ['id_chamado' => $id, 'status' => 'aberto']);

        $r = $this->como($this->auxiliar)->post("chamados/{$id}/situacao?modal=1", [
            'status' => 'em_andamento', 'observacao' => 'Computadores separados no almoxarifado',
        ]);
        $r->assertOK();
        $r->assertJSONFragment(['mensagem' => 'Situação alterada para Em andamento.']);

        $this->como($this->auxiliar)->post("chamados/{$id}/situacao", [
            'status' => 'concluido', 'observacao' => 'Computadores instalados',
        ])->assertRedirectTo(site_url("chamados/{$id}"));

        $this->seeInDatabase('chamados', ['id_chamado' => $id, 'status' => 'concluido']);
        $this->assertSame(3, $this->db->table('chamados_historico')->where('chamado_id', $id)->countAllResults());

        $pagina = $this->como($this->docente)->get("chamados/{$id}");
        $pagina->assertOK();

        foreach (
            [
            'Chamado #' . $id, 'Abertura', 'Faltam 5 computadores para a aula de Algoritmos',
            'Computadores separados no almoxarifado', 'Computadores instalados', 'Auxiliar Caio', 'Concluído',
            ] as $texto
        ) {
            $pagina->assertSee($texto);
        }

        // Concluído é final: ninguém muda mais.
        $pagina->assertDontSee('Alterar situação');

        $this->como($this->auxiliar)->post("chamados/{$id}/situacao", ['status' => 'cancelado', 'observacao' => 'x'])
            ->assertStatus(403);
    }

    public function testQuemAbriuSoCancelaOProprioEnquantoAberto(): void
    {
        $id = $this->abrir();

        $r = $this->como($this->docente)->get("chamados/{$id}/situacao");
        $r->assertOK();
        $r->assertSee('Cancelado');
        $r->assertDontSee('Em andamento');
        $this->como($this->docente)->post("chamados/{$id}/situacao", ['status' => 'em_andamento', 'observacao' => 'x'])
            ->assertStatus(422);

        $this->como($this->docente)->post("chamados/{$id}/situacao", ['status' => 'cancelado', 'observacao' => 'Resolvido na outra sala'])
            ->assertRedirect();
        $this->seeInDatabase('chamados', ['id_chamado' => $id, 'status' => 'cancelado']);
        $this->seeInDatabase('chamados_historico', [
            'chamado_id' => $id, 'status_anterior' => 'aberto', 'status_novo' => 'cancelado', 'usuario_id' => $this->docente['id_usuario'],
        ]);
    }

    public function testDocenteNaoCancelaDepoisDeEmAndamento(): void
    {
        $id = $this->abrir();
        $this->como($this->auxiliar)->post("chamados/{$id}/situacao", ['status' => 'em_andamento', 'observacao' => 'Começou']);

        $this->como($this->docente)->get("chamados/{$id}")->assertDontSee('Alterar situação');
        $this->como($this->docente)->post("chamados/{$id}/situacao", ['status' => 'cancelado', 'observacao' => 'x'])->assertStatus(403);
    }

    public function testEscopoDoAmbiente(): void
    {
        $id = $this->abrir();

        // Auxiliar de outro campus não vê nem mexe.
        $this->como($this->auxiliarB)->get('chamados')->assertDontSee('Laboratório 1');
        $this->como($this->auxiliarB)->get("chamados/{$id}")->assertStatus(403);
        $this->como($this->auxiliarB)->post("chamados/{$id}/situacao", ['status' => 'concluido', 'observacao' => 'x'])->assertStatus(403);

        // Ninguém abre chamado em ambiente fora do seu escopo.
        $r = $this->como($this->docente)->post('chamados?modal=1', [
            'ambiente_id' => (string) $this->salaOutroCampus, 'descricao' => 'Teste',
        ]);
        $r->assertStatus(422);
        $r->assertSee('Escolha um ambiente da lista.');

        // Na lista do campus, o chamado aparece com a situação em ponto + rótulo.
        $r = $this->como($this->auxiliar)->get('chamados');
        $r->assertSee('Laboratório 1');
        $r->assertSee('Aberto');
    }

    public function testQuemAbriuVeOProprioChamadoMesmoForaDoEscopo(): void
    {
        $id = $this->abrir();

        // A docente perdeu o vínculo com o campus, mas continua com o módulo por outro campus.
        $this->db->table('usuarios_perfis')->where('usuario_id', $this->docente['id_usuario'])->delete();
        $this->vincular($this->docente['id_usuario'], 'Docentes', 'campus', $this->criarCampus('Campus C'));

        $this->como($this->docente)->get("chamados/{$id}")->assertOK();
        $this->como($this->docente)->get('chamados')->assertSee('Laboratório 1');
    }
}

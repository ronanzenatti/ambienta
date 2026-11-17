<?php

use Tests\Support\FeatureBancoTestCase;

/**
 * Descarte: pedido com motivo da lista fixa, aprovação só por quem tem patrimonio.aprovar_descarte (com
 * CONFIRMO), baixa por soft delete e número de patrimônio reservado para sempre.
 *
 * @internal
 */
final class DescartesTest extends FeatureBancoTestCase
{
    private int $campus;
    private int $laboratorio;
    private int $item;
    private int $motivo;

    /** @var array<string, mixed> pede descarte, mas não aprova */
    private array $auxiliar;

    /** @var array<string, mixed> aprova descarte no campus */
    private array $coordenacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus      = $this->criarCampus('Campus A');
        $this->laboratorio = $this->criarAmbiente($this->criarPredio($this->campus, 'Prédio 1'), '101', 'Laboratório 1');
        $this->item        = $this->criarItem($this->criarModelo('Monitor LG'), $this->laboratorio, null, 'FJ-0500');
        $this->motivo      = (int) $this->db->table('motivos_descarte')->get()->getRow()->id_motivo_descarte;

        $this->auxiliar = $this->criarUsuario(['nome' => 'Auxiliar']);
        $this->vincular($this->auxiliar['id_usuario'], 'Auxiliares', 'campus', $this->campus);

        $this->coordenacao = $this->criarUsuario(['nome' => 'Coordenadora']);
        $this->vincular($this->coordenacao['id_usuario'], 'Coordenação', 'campus', $this->campus);
    }

    private function pedir(): int
    {
        $this->como($this->auxiliar)->post("patrimonio/itens/{$this->item}/descartar", [
            'motivo_descarte_id' => (string) $this->motivo, 'justificativa' => 'Tela quebrada',
        ])->assertRedirect();

        return (int) $this->db->table('descartes')->get()->getRow()->id_descarte;
    }

    public function testPedidoValidaMotivoENaoDuplica(): void
    {
        $this->como($this->auxiliar)->post("patrimonio/itens/{$this->item}/descartar", ['motivo_descarte_id' => '99999'])->assertStatus(422);

        $id = $this->pedir();
        $this->seeInDatabase('descartes', ['id_descarte' => $id, 'status' => 'pendente', 'solicitante_id' => $this->auxiliar['id_usuario']]);

        $this->como($this->auxiliar)->post("patrimonio/itens/{$this->item}/descartar", ['motivo_descarte_id' => (string) $this->motivo]);
        $this->assertSame(1, $this->db->table('descartes')->countAllResults());

        // Com descarte pendente, o item não é movimentado.
        $this->como($this->auxiliar)->post("patrimonio/itens/{$this->item}/movimentar", [
            'destino' => "ambiente:{$this->laboratorio}", 'destinatario_id' => (string) $this->auxiliar['id_usuario'],
        ])->assertStatus(422);
    }

    public function testItemEmTransitoNaoPodeSerDescartado(): void
    {
        $this->db->table('itens')->where('id_item', $this->item)->update(['status' => 'em_transito']);

        $this->como($this->auxiliar)->post("patrimonio/itens/{$this->item}/descartar", ['motivo_descarte_id' => (string) $this->motivo])->assertRedirect();
        $this->assertStringContainsString('em trânsito', (string) $_SESSION['erro']);
        $this->assertSame(0, $this->db->table('descartes')->countAllResults());
    }

    public function testSoQuemTemPermissaoAprovaEComConfirmo(): void
    {
        $id = $this->pedir();

        $this->como($this->auxiliar)->get("patrimonio/descartes/{$id}")->assertDontSee('Aprovar descarte');
        $this->como($this->auxiliar)->excluir("patrimonio/descartes/{$id}/aprovar")->assertStatus(403);

        $this->como($this->coordenacao)->get("patrimonio/descartes/{$id}")->assertSee('Aprovar descarte');
        $this->como($this->coordenacao)->excluir("patrimonio/descartes/{$id}/aprovar", 'confirmo');
        $this->seeInDatabase('descartes', ['id_descarte' => $id, 'status' => 'pendente']);

        $this->como($this->coordenacao)->excluir("patrimonio/descartes/{$id}/aprovar")->assertRedirect();

        $this->seeInDatabase('descartes', ['id_descarte' => $id, 'status' => 'aprovado', 'aprovador_id' => $this->coordenacao['id_usuario']]);
        $item = $this->db->table('itens')->where('id_item', $this->item)->get()->getRowArray();
        $this->assertSame('descartado', $item['status']);
        $this->assertNotNull($item['deletado_em'], 'baixa é soft delete');

        // Sai da lista, continua consultável, e o número nunca volta a ser usado.
        $this->como($this->coordenacao)->get('patrimonio/itens')->assertDontSee('FJ-0500');
        $this->como($this->coordenacao)->get("patrimonio/itens/{$this->item}")->assertSee('Descartado');

        $novo = $this->criarItem($this->criarModelo('Monitor novo'), $this->laboratorio);
        $this->como($this->coordenacao)->post("patrimonio/itens/{$novo}", ['numero_patrimonio' => 'FJ-0500'])->assertStatus(422);
    }

    public function testRejeicaoMantemOItem(): void
    {
        $id = $this->pedir();

        $this->como($this->auxiliar)->post("patrimonio/descartes/{$id}/rejeitar")->assertStatus(403);
        $this->como($this->coordenacao)->post("patrimonio/descartes/{$id}/rejeitar")->assertRedirect();

        $this->seeInDatabase('descartes', ['id_descarte' => $id, 'status' => 'rejeitado']);
        $this->seeInDatabase('itens', ['id_item' => $this->item, 'status' => 'em_uso', 'deletado_em' => null]);

        // Decidido não se decide de novo.
        $this->como($this->coordenacao)->excluir("patrimonio/descartes/{$id}/aprovar");
        $this->seeInDatabase('itens', ['id_item' => $this->item, 'deletado_em' => null]);
    }
}

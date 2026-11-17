<?php

use Tests\Support\FeatureBancoTestCase;

/**
 * Unidades (replicação do modelo), número de patrimônio e movimentação com protocolo de recebimento:
 * em trânsito até quem recebe confirmar, prazo do campus (ou o padrão), alerta de atraso a cada acesso.
 *
 * @internal
 */
final class ItensMovimentacoesTest extends FeatureBancoTestCase
{
    private int $campus;
    private int $almoxarifado;
    private int $laboratorio;

    /** @var array<string, mixed> editor de patrimônio no campus, pode confirmar recebimento */
    private array $auxiliar;

    /** @var array<string, mixed> também auxiliar do campus: o destinatário */
    private array $tecnico;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus       = $this->criarCampus('Campus A');
        $this->almoxarifado = $this->criarAlmoxarifado($this->campus);
        $this->laboratorio  = $this->criarAmbiente($this->criarPredio($this->campus, 'Prédio 1'), '101', 'Laboratório 1');

        $this->auxiliar = $this->criarUsuario(['nome' => 'Ana Auxiliar']);
        $this->vincular($this->auxiliar['id_usuario'], 'Auxiliares', 'campus', $this->campus);

        $this->tecnico = $this->criarUsuario(['nome' => 'Tiago Técnico']);
        $this->vincular($this->tecnico['id_usuario'], 'Auxiliares', 'campus', $this->campus);
    }

    public function testReplicacaoGeraUnidadesOuSomaQuantidade(): void
    {
        $notebook = $this->criarModelo('Notebook Dell');
        $cabo     = $this->criarModelo('Cabo HDMI', false);

        $this->como($this->auxiliar)->post("patrimonio/modelos/{$notebook}/replicar", [
            'quantidade' => '3', 'local' => "almoxarifado:{$this->almoxarifado}",
        ])->assertRedirectTo(site_url("patrimonio/modelos/{$notebook}"));

        $this->assertSame(3, $this->db->table('itens')
            ->where(['modelo_item_id' => $notebook, 'status' => 'almoxarifado', 'numero_patrimonio' => null])->countAllResults());

        foreach (['10', '5'] as $quantidade) {
            $this->como($this->auxiliar)->post("patrimonio/modelos/{$cabo}/replicar", [
                'quantidade' => $quantidade, 'local' => "ambiente:{$this->laboratorio}",
            ])->assertRedirect();
        }
        // Sem patrimônio: um registro só, com a quantidade somada (e os dados copiados do modelo).
        $this->assertSame(1, $this->db->table('itens')->where('modelo_item_id', $cabo)->where('deletado_em', null)->countAllResults());
        $this->seeInDatabase('itens', ['modelo_item_id' => $cabo, 'nome' => 'Cabo HDMI', 'patrimoniavel' => 0, 'ambiente_id' => $this->laboratorio, 'quantidade' => 15, 'status' => 'em_uso']);

        // Local fora do escopo e quantidade inválida são recusados.
        $alheio = $this->criarAlmoxarifado($this->criarCampus('Campus B'));
        $this->como($this->auxiliar)->post("patrimonio/modelos/{$notebook}/replicar", ['quantidade' => '1', 'local' => "almoxarifado:{$alheio}"])->assertStatus(422);
        $this->como($this->auxiliar)->post("patrimonio/modelos/{$notebook}/replicar", ['quantidade' => '0', 'local' => "almoxarifado:{$this->almoxarifado}"])->assertStatus(422);
        $this->assertSame(4, $this->db->table('itens')->where('deletado_em', null)->countAllResults());
    }

    /**
     * Dados do formulário de alteração do item (nome e categoria como estão, mais o número).
     *
     * @return array<string, string>
     */
    private function dadosItem(int $id, string $numero): array
    {
        $item = $this->db->table('itens')->where('id_item', $id)->get()->getRowArray();

        return [
            'nome'              => (string) $item['nome'],
            'categoria_item_id' => (string) $item['categoria_item_id'],
            'patrimoniavel'     => '1',
            'numero_patrimonio' => $numero,
        ];
    }

    public function testNumeroDePatrimonioNuncaSeRepete(): void
    {
        $modelo = $this->criarModelo('Monitor');
        $item   = $this->criarItem($modelo, $this->laboratorio);
        $outro  = $this->criarItem($modelo, $this->laboratorio);

        $this->como($this->auxiliar)->post("patrimonio/itens/{$item}", $this->dadosItem($item, 'FJ-0001'))
            ->assertRedirectTo(site_url("patrimonio/itens/{$item}"));

        $repetido = $this->como($this->auxiliar)->post("patrimonio/itens/{$outro}", $this->dadosItem($outro, 'FJ-0001'));
        $repetido->assertStatus(422);
        $repetido->assertSee('não pode ser reaproveitado');

        $this->como($this->auxiliar)->get('patrimonio/itens')->assertSee('FJ-0001');
    }

    public function testMovimentacaoSoCompletaComAConfirmacaoDeQuemRecebe(): void
    {
        $item = $this->criarItem($this->criarModelo('Projetor'), null, $this->almoxarifado, 'FJ-0100');

        // O destinatário vem de uma lista sob demanda (opcoes/destinatarios): a tela só traz o select.
        $tela = $this->como($this->auxiliar)->get("patrimonio/itens/{$item}/movimentar");
        $tela->assertSee('Quem recebe');
        $this->assertStringContainsString('opcoes/destinatarios', (string) $tela->getBody());

        $this->como($this->auxiliar)->post("patrimonio/itens/{$item}/movimentar", [
            'destino' => "ambiente:{$this->laboratorio}", 'destinatario_id' => (string) $this->tecnico['id_usuario'],
        ])->assertRedirect();

        $mov = $this->db->table('movimentacoes_itens')->get()->getRowArray();
        $this->assertSame('pendente', $mov['status']);
        $this->assertSame('almoxarifado', $mov['origem_tipo']);
        $this->seeInDatabase('itens', ['id_item' => $item, 'status' => 'em_transito', 'almoxarifado_id' => $this->almoxarifado]);

        // Prazo padrão (48 h) quando o campus não tem configuração.
        $horas = (strtotime($mov['data_limite_confirmacao']) - strtotime($mov['data_envio'])) / 3600;
        $this->assertEquals(48, $horas);

        // Em trânsito não se move de novo; e só o destinatário confirma.
        $this->como($this->auxiliar)->post("patrimonio/itens/{$item}/movimentar", [
            'destino' => "ambiente:{$this->laboratorio}", 'destinatario_id' => (string) $this->tecnico['id_usuario'],
        ])->assertStatus(422);

        $id = (int) $mov['id_movimentacao_item'];
        $this->como($this->auxiliar)->get("patrimonio/movimentacoes/{$id}")->assertDontSee('Confirmar recebimento</button>');
        $this->como($this->auxiliar)->post("patrimonio/movimentacoes/{$id}/confirmar")->assertStatus(403);

        $tela = $this->como($this->tecnico)->get("patrimonio/movimentacoes/{$id}");
        $tela->assertSee('Confirmar recebimento');
        $tela->assertSee('FJ-0100');

        $this->como($this->tecnico)->post("patrimonio/movimentacoes/{$id}/confirmar")->assertRedirect();
        $this->seeInDatabase('movimentacoes_itens', ['id_movimentacao_item' => $id, 'status' => 'confirmada']);
        $this->seeInDatabase('itens', [
            'id_item' => $item, 'status' => 'em_uso', 'ambiente_id' => $this->laboratorio, 'almoxarifado_id' => null,
        ]);
    }

    public function testPrazoDoCampusEDestinatarioSemPermissaoNoDestino(): void
    {
        $this->db->table('configuracoes_campus')->insert(['campus_id' => $this->campus, 'prazo_resposta_movimentacao_horas' => 24]);
        $item = $this->criarItem($this->criarModelo('Câmera'), $this->laboratorio, null, 'FJ-0300');

        $docente = $this->criarUsuario(['nome' => 'Docente']);
        $this->vincular($docente['id_usuario'], 'Docentes', 'campus', $this->campus);

        $this->como($this->auxiliar)->post("patrimonio/itens/{$item}/movimentar", [
            'destino' => "almoxarifado:{$this->almoxarifado}", 'destinatario_id' => (string) $docente['id_usuario'],
        ])->assertStatus(422);

        // Pode ser a mesma pessoa que envia.
        $this->como($this->auxiliar)->post("patrimonio/itens/{$item}/movimentar", [
            'destino' => "almoxarifado:{$this->almoxarifado}", 'destinatario_id' => (string) $this->auxiliar['id_usuario'],
        ])->assertRedirect();

        $mov = $this->db->table('movimentacoes_itens')->get()->getRowArray();
        $this->assertEquals(24, (strtotime($mov['data_limite_confirmacao']) - strtotime($mov['data_envio'])) / 3600);
        $this->assertSame((int) $this->auxiliar['id_usuario'], (int) $mov['destinatario_id']);
    }

    public function testAtrasoAlertaQuemRecebeACadaAcessoECancelamentoDevolveOItem(): void
    {
        $item = $this->criarItem($this->criarModelo('Tablet'), $this->laboratorio, null, 'FJ-0200');

        $this->como($this->auxiliar)->post("patrimonio/itens/{$item}/movimentar", [
            'destino' => "almoxarifado:{$this->almoxarifado}", 'destinatario_id' => (string) $this->tecnico['id_usuario'],
        ]);
        $id = (int) $this->db->table('movimentacoes_itens')->get()->getRow()->id_movimentacao_item;

        $this->como($this->tecnico)->get('/')->assertDontSee('recebimento em atraso');

        $this->db->query('UPDATE movimentacoes_itens SET data_limite_confirmacao = NOW() - INTERVAL 1 HOUR');

        foreach (['/', 'patrimonio/itens'] as $pagina) {
            $this->como($this->tecnico)->get($pagina)->assertSee('Você tem 1 item(ns) com recebimento em atraso');
        }
        $this->como($this->auxiliar)->get('/')->assertDontSee('recebimento em atraso');

        $fila = $this->como($this->tecnico)->get('patrimonio/movimentacoes/a-receber');
        $fila->assertSee('FJ-0200');
        $fila->assertSee('Recebimento em atraso');

        // Sem cancelamento automático; o remetente cancela o envio (com CONFIRMO) e o item volta a ficar livre.
        $this->como($this->auxiliar)->excluir("patrimonio/movimentacoes/{$id}", 'nao');
        $this->seeInDatabase('movimentacoes_itens', ['id_movimentacao_item' => $id, 'deletado_em' => null]);

        $this->como($this->auxiliar)->excluir("patrimonio/movimentacoes/{$id}")->assertRedirectTo(site_url("patrimonio/itens/{$item}"));
        $this->seeInDatabase('itens', ['id_item' => $item, 'status' => 'em_uso', 'ambiente_id' => $this->laboratorio]);
        $this->como($this->tecnico)->get('/')->assertDontSee('recebimento em atraso');
    }

    public function testItemForaDoEscopoNaoApareceNemAbre(): void
    {
        $outroCampus = $this->criarCampus('Campus B');
        $alheio      = $this->criarItem($this->criarModelo('Impressora'), null, $this->criarAlmoxarifado($outroCampus), 'FJ-9999');

        $this->como($this->auxiliar)->get('patrimonio/itens')->assertDontSee('FJ-9999');
        $this->como($this->auxiliar)->get("patrimonio/itens/{$alheio}")->assertStatus(403);
        $this->como($this->auxiliar)->get("patrimonio/itens/{$alheio}/movimentar")->assertStatus(403);
    }
}

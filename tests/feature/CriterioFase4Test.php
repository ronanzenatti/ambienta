<?php

use App\Libraries\Patrimonio\LembreteAtrasos;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\Mock\MockEmail;
use Config\Email;
use Tests\Support\FeatureBancoTestCase;

/**
 * Critério de conclusão da Fase 4: um item completa o ciclo cadastro → movimentação → confirmação →
 * descarte, com os alertas de atraso disparando de verdade (aviso a cada acesso e e-mail diário).
 * Tudo pelas telas, com os perfis de partida.
 *
 * @internal
 */
final class CriterioFase4Test extends FeatureBancoTestCase
{
    /** @var list<array<string, mixed>> */
    private array $emails = [];

    protected function setUp(): void
    {
        parent::setUp();

        Events::on('email', function (array $email): void {
            $this->emails[] = $email;
        });
    }

    protected function tearDown(): void
    {
        Events::removeAllListeners('email');

        parent::tearDown();
    }

    public function testCicloCompletoDeUmItemComAlertaDeAtraso(): void
    {
        $campus      = $this->criarCampus('Fatec Jahu');
        $laboratorio = $this->criarAmbiente($this->criarPredio($campus, 'Prédio Principal'), 'L1', 'Laboratório de Redes');

        $coordenacao = $this->criarUsuario(['nome' => 'Carla Coordenadora', 'email' => 'carla@fatec.sp.gov.br']);
        $this->vincular($coordenacao['id_usuario'], 'Coordenação', 'campus', $campus);
        $tecnico = $this->criarUsuario(['nome' => 'Tiago Técnico', 'email' => 'tiago@fatec.sp.gov.br']);
        $this->vincular($tecnico['id_usuario'], 'Auxiliares', 'campus', $campus);

        // Cadastro: almoxarifado, categoria, modelo, unidade e número de patrimônio.
        $this->como($coordenacao)->post('patrimonio/almoxarifados', ['campus_id' => (string) $campus, 'nome' => 'Almoxarifado Central'])->assertRedirect();
        $almox = (int) $this->db->table('almoxarifados')->get()->getRow()->id_almoxarifado;

        $this->como($coordenacao)->post('patrimonio/categorias', ['nome' => 'Informática'])->assertRedirect();
        $categoria = (int) $this->db->table('categorias_itens')->get()->getRow()->id_categoria_item;

        $this->como($coordenacao)->post('patrimonio/modelos', [
            'categoria_item_id' => (string) $categoria, 'nome' => 'Switch Cisco 2960', 'marca' => 'Cisco', 'patrimoniavel' => '1',
        ])->assertRedirect();
        $modelo = (int) $this->db->table('modelos_itens')->get()->getRow()->id_modelo_item;

        $this->como($coordenacao)->post("patrimonio/modelos/{$modelo}/replicar", ['quantidade' => '1', 'local' => "almoxarifado:{$almox}"])->assertRedirect();
        $item = (int) $this->db->table('itens')->get()->getRow()->id_item;

        $this->como($coordenacao)->post("patrimonio/itens/{$item}", [
            'nome' => 'Switch Cisco 2960', 'categoria_item_id' => (string) $categoria, 'patrimoniavel' => '1', 'numero_patrimonio' => 'CPS-123456',
        ])->assertRedirect();
        $this->como($coordenacao)->get('patrimonio/itens')->assertSee('CPS-123456');

        // Movimentação para o laboratório, recebida pelo técnico.
        $this->como($coordenacao)->post("patrimonio/itens/{$item}/movimentar", [
            'destino' => "ambiente:{$laboratorio}", 'destinatario_id' => (string) $tecnico['id_usuario'],
        ])->assertRedirect();
        $mov = (int) $this->db->table('movimentacoes_itens')->get()->getRow()->id_movimentacao_item;
        $this->como($coordenacao)->get("patrimonio/itens/{$item}")->assertSee('Em trânsito');

        // O prazo vence: alerta a cada acesso de quem recebe e e-mail diário para os dois lados.
        $this->db->query('UPDATE movimentacoes_itens SET data_limite_confirmacao = NOW() - INTERVAL 1 HOUR');
        $this->como($tecnico)->get('/')->assertSee('Você tem 1 item(ns) com recebimento em atraso');

        $lembrete = (new LembreteAtrasos($this->db))->enviar(new MockEmail(config(Email::class)));
        $this->assertSame(2, $lembrete['emails']);
        $destinos = array_merge(...array_map(static fn (array $e): array => (array) $e['recipients'], $this->emails));
        $this->assertEqualsCanonicalizing(['carla@fatec.sp.gov.br', 'tiago@fatec.sp.gov.br'], $destinos);

        $this->como($tecnico)->get("patrimonio/movimentacoes/{$mov}")->assertSee('O prazo de resposta venceu');
        $this->como($tecnico)->post("patrimonio/movimentacoes/{$mov}/confirmar")->assertRedirect();
        $this->seeInDatabase('itens', ['id_item' => $item, 'ambiente_id' => $laboratorio, 'status' => 'em_uso']);
        $this->como($tecnico)->get('/')->assertDontSee('recebimento em atraso');

        // Descarte: pedido pelo técnico, aprovado pela coordenação com CONFIRMO.
        $motivo = (int) $this->db->table('motivos_descarte')->get()->getRow()->id_motivo_descarte;
        $this->como($tecnico)->post("patrimonio/itens/{$item}/descartar", [
            'motivo_descarte_id' => (string) $motivo, 'justificativa' => 'Queimado após descarga elétrica',
        ])->assertRedirect();
        $descarte = (int) $this->db->table('descartes')->get()->getRow()->id_descarte;

        $this->como($coordenacao)->excluir("patrimonio/descartes/{$descarte}/aprovar")->assertRedirect();

        $baixado = $this->db->table('itens')->where('id_item', $item)->get()->getRowArray();
        $this->assertSame('descartado', $baixado['status']);
        $this->assertNotNull($baixado['deletado_em']);
        $this->assertSame('CPS-123456', $baixado['numero_patrimonio']);

        // O histórico continua lá, e o número nunca volta.
        $pagina = $this->como($coordenacao)->get("patrimonio/itens/{$item}");
        $pagina->assertSee('Descartado');
        $pagina->assertSee('Recebida');
        $this->como($coordenacao)->get('patrimonio/itens')->assertDontSee('CPS-123456');
    }
}

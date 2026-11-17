<?php

use CodeIgniter\HTTP\DownloadResponse;
use Tests\Support\FeatureBancoTestCase;

/**
 * Catálogos do patrimônio (categorias de item, modelos, softwares), almoxarifados (um por campus, escopo de
 * campus) e o vínculo de softwares com ambientes. Todas as listagens abrem e exportam.
 *
 * @internal
 */
final class PatrimonioCatalogosTest extends FeatureBancoTestCase
{
    private int $campus;
    private int $outroCampus;

    /** @var array<string, mixed> Auxiliar com escopo de campus: editor de patrimônio */
    private array $auxiliar;

    /** @var array<string, mixed> Docente: sem acesso ao patrimônio */
    private array $docente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus      = $this->criarCampus('Campus A');
        $this->outroCampus = $this->criarCampus('Campus B');

        $this->auxiliar = $this->criarUsuario(['nome' => 'Auxiliar']);
        $this->vincular($this->auxiliar['id_usuario'], 'Auxiliares', 'campus', $this->campus);

        $this->docente = $this->criarUsuario(['nome' => 'Docente']);
        $this->vincular($this->docente['id_usuario'], 'Docentes', 'campus', $this->campus);
    }

    public function testDocenteNaoAcessaPatrimonioEMenuSoApareceParaQuemTem(): void
    {
        foreach (['itens', 'movimentacoes', 'descartes', 'modelos', 'categorias', 'almoxarifados', 'softwares'] as $rota) {
            $this->como($this->docente)->get("patrimonio/{$rota}")->assertStatus(403);
            $this->como($this->auxiliar)->get("patrimonio/{$rota}")->assertStatus(200);
        }

        $this->assertStringNotContainsString(site_url('patrimonio/itens'), (string) $this->como($this->docente)->get('/')->getBody());
        $this->assertStringContainsString(site_url('patrimonio/itens'), (string) $this->como($this->auxiliar)->get('/')->getBody());
    }

    public function testListagensExportamExcel(): void
    {
        $this->criarModelo('Notebook');

        foreach (['itens', 'movimentacoes', 'descartes', 'modelos', 'categorias', 'almoxarifados', 'softwares'] as $rota) {
            $resposta = $this->como($this->auxiliar)->get("patrimonio/{$rota}/exportar", ['formato' => 'xlsx', 'abrangencia' => 'tudo']);
            $this->assertInstanceOf(DownloadResponse::class, $resposta->response(), $rota);
        }
    }

    public function testCategoriaDeItemNomeUnicoEExclusaoBloqueadaPorModelo(): void
    {
        $this->como($this->auxiliar)->post('patrimonio/categorias', ['nome' => 'Informática'])
            ->assertRedirectTo(site_url('patrimonio/categorias'));

        $repetida = $this->como($this->auxiliar)->post('patrimonio/categorias?modal=1', ['nome' => 'Informática']);
        $repetida->assertStatus(422);
        $repetida->assertSee('Já existe uma categoria de item com este nome.');

        $id = (int) $this->db->table('categorias_itens')->where('nome', 'Informática')->get()->getRow()->id_categoria_item;
        $this->criarModelo('Notebook', true, $id);

        $this->como($this->auxiliar)->excluir("patrimonio/categorias/{$id}");
        $this->assertStringContainsString('1 modelo(s) de item', (string) $_SESSION['erro']);

        $this->como($this->auxiliar)->excluir("patrimonio/categorias/{$id}", 'confirmo');
        $this->seeInDatabase('categorias_itens', ['id_categoria_item' => $id, 'deletado_em' => null]);
    }

    public function testModeloEUmMoldeEOItemSegueIndependente(): void
    {
        $categoria = $this->criarCategoriaItem();

        $this->como($this->auxiliar)->post('patrimonio/modelos', [
            'categoria_item_id' => (string) $categoria, 'nome' => 'Projetor Epson', 'marca' => 'Epson', 'patrimoniavel' => '1',
        ])->assertRedirect();

        $id = (int) $this->db->table('modelos_itens')->where('nome', 'Projetor Epson')->get()->getRow()->id_modelo_item;
        $this->criarItem($id, null, $this->criarAlmoxarifado($this->campus));

        // O modelo é só um molde: mudar o tipo ou o nome dele não mexe nos itens que já saíram dele.
        $this->como($this->auxiliar)->post("patrimonio/modelos/{$id}", [
            'categoria_item_id' => (string) $categoria, 'nome' => 'Projetor Epson EX', 'patrimoniavel' => '0',
        ])->assertRedirect();
        $this->seeInDatabase('modelos_itens', ['id_modelo_item' => $id, 'nome' => 'Projetor Epson EX', 'patrimoniavel' => 0]);
        $this->seeInDatabase('itens', ['modelo_item_id' => $id, 'nome' => 'Projetor Epson', 'patrimoniavel' => 1]);

        // E o modelo pode ser excluído: o item continua, com os próprios dados.
        $this->como($this->auxiliar)->excluir("patrimonio/modelos/{$id}");
        $this->dontSeeInDatabase('modelos_itens', ['id_modelo_item' => $id, 'deletado_em' => null]);
        $this->seeInDatabase('itens', ['modelo_item_id' => $id, 'nome' => 'Projetor Epson', 'deletado_em' => null]);
    }

    public function testAlmoxarifadoUmPorCampusENoEscopoDoCampus(): void
    {
        $this->como($this->auxiliar)->post('patrimonio/almoxarifados', ['campus_id' => (string) $this->campus, 'nome' => 'Central'])
            ->assertRedirectTo(site_url('patrimonio/almoxarifados'));

        // Segundo almoxarifado no mesmo campus e almoxarifado em campus fora do escopo: recusados.
        $this->como($this->auxiliar)->post('patrimonio/almoxarifados', ['campus_id' => (string) $this->campus, 'nome' => 'Outro'])->assertStatus(422);
        $this->como($this->auxiliar)->post('patrimonio/almoxarifados', ['campus_id' => (string) $this->outroCampus, 'nome' => 'Outro'])->assertStatus(422);
        $this->assertSame(1, $this->db->table('almoxarifados')->countAllResults());

        $alheio = $this->criarAlmoxarifado($this->outroCampus, 'Almoxarifado B');
        $this->como($this->auxiliar)->get("patrimonio/almoxarifados/{$alheio}/editar")->assertStatus(403);
        $this->como($this->auxiliar)->get('patrimonio/almoxarifados')->assertDontSee('Almoxarifado B');
    }

    public function testSoftwareUnicoPorNomeEVersaoEVinculoComAmbiente(): void
    {
        $this->como($this->auxiliar)->post('patrimonio/softwares', [
            'nome' => 'Python', 'versao' => '3.12', 'requisitos_tecnicos' => '4 GB de RAM',
        ])->assertRedirect();
        $this->como($this->auxiliar)->post('patrimonio/softwares', ['nome' => 'Python', 'versao' => '3.12'])->assertStatus(422);
        $this->como($this->auxiliar)->post('patrimonio/softwares', ['nome' => 'Python', 'versao' => '3.13'])->assertRedirect();

        $software = (int) $this->db->table('softwares')->where('versao', '3.12')->get()->getRow()->id_software;
        $this->seeInDatabase('softwares', ['id_software' => $software, 'requisitos_tecnicos' => '4 GB de RAM']);

        $ambiente = $this->criarAmbiente($this->criarPredio($this->campus, 'Prédio 1'), '101', 'Laboratório 1');
        $this->como($this->auxiliar)->post("ambientes/{$ambiente}/softwares", ['software_ids' => [(string) $software]])->assertRedirect();
        $this->como($this->auxiliar)->post("ambientes/{$ambiente}/softwares", ['software_ids' => [(string) $software]])->assertStatus(422);
        $this->assertSame(1, $this->db->table('ambientes_softwares')->where('ambiente_id', $ambiente)->countAllResults());

        $pagina = $this->como($this->auxiliar)->get("ambientes/{$ambiente}");
        $pagina->assertSee('Python 3.12');
        $pagina->assertSee('4 GB de RAM');

        // Software instalado não pode ser excluído; o vínculo sai só com CONFIRMO.
        $this->como($this->auxiliar)->excluir("patrimonio/softwares/{$software}");
        $this->seeInDatabase('softwares', ['id_software' => $software, 'deletado_em' => null]);

        $vinculo = (int) $this->db->table('ambientes_softwares')->get()->getRow()->id_ambiente_software;
        $this->como($this->auxiliar)->excluir("ambientes/{$ambiente}/softwares/{$vinculo}", 'errado');
        $this->seeInDatabase('ambientes_softwares', ['id_ambiente_software' => $vinculo, 'deletado_em' => null]);
        $this->como($this->auxiliar)->excluir("ambientes/{$ambiente}/softwares/{$vinculo}");
        $this->dontSeeInDatabase('ambientes_softwares', ['id_ambiente_software' => $vinculo, 'deletado_em' => null]);

        // Ambiente de outro campus: fora do escopo de patrimônio.
        $alheio = $this->criarAmbiente($this->criarPredio($this->outroCampus, 'Prédio B'), '201', 'Sala B');
        $this->como($this->auxiliar)->post("ambientes/{$alheio}/softwares", ['software_ids' => [(string) $software]])->assertStatus(403);
    }
    public function testItensDoAmbienteEntramPorMovimentacaoEVoltamAoAlmoxarifado(): void
    {
        $ambiente = $this->criarAmbiente($this->criarPredio($this->campus, 'Prédio 1'), '101', 'Laboratório 1');
        $almox    = $this->criarAlmoxarifado($this->campus);
        $modelo   = $this->criarModelo('Notebook');
        $a        = $this->criarItem($modelo, null, $almox, 'PAT-A');
        $b        = $this->criarItem($modelo, null, $almox, 'PAT-B');
        $quem     = (string) $this->auxiliar['id_usuario'];

        // O modal lista os itens que podem ir (os dois, que estão no almoxarifado) e a página tem os dois cartões.
        $modal = $this->como($this->auxiliar)->get("ambientes/{$ambiente}/itens/novo?modal=1");
        $modal->assertOk();
        $this->assertStringContainsString('name="item_ids[]"', (string) $modal->getBody());
        $pagina = $this->como($this->auxiliar)->get("ambientes/{$ambiente}");
        $pagina->assertSee('Itens do ambiente');
        $pagina->assertSee('Adicionar itens');
        $pagina->assertSee('Adicionar softwares');

        // Sem item escolhido ou com item de fora da lista: 422, nada é enviado.
        $this->como($this->auxiliar)->post("ambientes/{$ambiente}/itens?modal=1", ['destinatario_id' => $quem])->assertStatus(422);
        $this->como($this->auxiliar)->post("ambientes/{$ambiente}/itens?modal=1", ['item_ids' => ['999999'], 'destinatario_id' => $quem])->assertStatus(422);
        $this->assertSame(0, $this->db->table('movimentacoes_itens')->countAllResults());

        // Dois itens: duas movimentações pendentes, itens em trânsito (continuam fora do ambiente até confirmar).
        $this->como($this->auxiliar)->post("ambientes/{$ambiente}/itens?modal=1", [
            'item_ids' => [(string) $a, (string) $b], 'destinatario_id' => $quem,
        ])->assertJSONFragment(['mensagem' => '2 item(ns) enviado(s) ao ambiente. Entram quando o recebimento for confirmado.']);
        $this->assertSame(2, $this->db->table('movimentacoes_itens')->where('status', 'pendente')->where('destino_ambiente_id', $ambiente)->countAllResults());
        $this->seeInDatabase('itens', ['id_item' => $a, 'status' => 'em_transito']);

        // Em trânsito, o item não é mais oferecido.
        $this->como($this->auxiliar)->post("ambientes/{$ambiente}/itens?modal=1", [
            'item_ids' => [(string) $a], 'destinatario_id' => $quem,
        ])->assertStatus(422);

        // Item que já está no ambiente aparece na tabela; só o em uso tem Excluir (devolver).
        $this->db->table('itens')->where('id_item', $b)->update(['ambiente_id' => $ambiente, 'almoxarifado_id' => null, 'status' => 'em_uso']);
        $tabela = $this->como($this->auxiliar)->get("ambientes/{$ambiente}/itens", ['parcial' => '1']);
        $tabela->assertSee('PAT-B');
        $tabela->assertDontSee('PAT-A');

        $this->como($this->auxiliar)->excluir("ambientes/{$ambiente}/itens/{$b}", 'errado');
        $this->seeInDatabase('itens', ['id_item' => $b, 'status' => 'em_uso']);
        $this->como($this->auxiliar)->excluir("ambientes/{$ambiente}/itens/{$b}");
        $this->seeInDatabase('itens', ['id_item' => $b, 'status' => 'em_transito']);
        $this->seeInDatabase('movimentacoes_itens', ['item_id' => $b, 'destino_almoxarifado_id' => $almox, 'status' => 'pendente']);

        // Docente (sem patrimônio) não vê a tabela de itens nem adiciona.
        $this->como($this->docente)->get("ambientes/{$ambiente}/itens", ['parcial' => '1'])->assertStatus(403);
        $this->como($this->docente)->post("ambientes/{$ambiente}/itens", ['item_ids' => [(string) $a]])->assertStatus(403);
    }
}

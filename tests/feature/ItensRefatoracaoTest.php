<?php

use App\Libraries\Patrimonio\ImportadorNovosItens;
use CodeIgniter\HTTP\DownloadResponse;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Support\FeatureBancoTestCase;

/**
 * Itens são itens: patrimonial (um registro por número) ou sem patrimônio (por quantidade), cada um com dados
 * próprios copiados do modelo. Patrimoniável sem número fica pendente (amarelo, não se move). Quantidade se separa
 * ao mover ou baixar e se reúne ao chegar. A tela de números cria itens novos de um modelo por planilha.
 *
 * @internal
 */
final class ItensRefatoracaoTest extends FeatureBancoTestCase
{
    private int $campus;
    private int $almoxarifado;
    private int $laboratorio;
    private int $predio;

    /** @var array<string, mixed> */
    private array $auxiliar;

    private string $arquivo = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus       = $this->criarCampus('Campus A');
        $this->almoxarifado = $this->criarAlmoxarifado($this->campus);
        $this->predio       = $this->criarPredio($this->campus, 'Prédio 1');
        $this->laboratorio  = $this->criarAmbiente($this->predio, '101', 'Laboratório 1');

        $this->auxiliar = $this->criarUsuario(['nome' => 'Ana Auxiliar']);
        $this->vincular($this->auxiliar['id_usuario'], 'Auxiliares', 'campus', $this->campus);
    }

    protected function tearDown(): void
    {
        if ($this->arquivo !== '') {
            @unlink($this->arquivo);
        }

        parent::tearDown();
    }

    public function testPatrimoniavelSemNumeroFicaPendenteEmAmareloEBloqueado(): void
    {
        $pendente = $this->criarItem($this->criarModelo('Notebook'), $this->laboratorio);

        $lista = $this->como($this->auxiliar)->get('patrimonio/itens');
        $lista->assertSee('Aguardando número');
        $lista->assertSee('só pode ser movido, alocado e usado depois de receber um número');
        $this->assertStringContainsString('bg-action-edit/15', (string) $lista->getBody());

        // Na página do item o aviso aparece e Movimentar não é oferecido; o POST direto é recusado.
        $pagina = $this->como($this->auxiliar)->get("patrimonio/itens/{$pendente}");
        $pagina->assertSee('Aguardando número');
        $pagina->assertDontSee('Movimentar');

        $this->como($this->auxiliar)->post("patrimonio/itens/{$pendente}/movimentar", [
            'destino' => "almoxarifado:{$this->almoxarifado}", 'destinatario_id' => (string) $this->auxiliar['id_usuario'],
        ])->assertStatus(422);
        $this->assertSame(0, $this->db->table('movimentacoes_itens')->countAllResults());
        $this->seeInDatabase('itens', ['id_item' => $pendente, 'status' => 'em_uso']);

        // Recebendo o número, o item deixa de ser pendente e pode ser movido.
        $this->como($this->auxiliar)->post("patrimonio/itens/{$pendente}", [
            'nome' => 'Notebook', 'categoria_item_id' => (string) $this->db->table('itens')->where('id_item', $pendente)->get()->getRow()->categoria_item_id,
            'patrimoniavel' => '1', 'numero_patrimonio' => 'FJ-7000',
        ])->assertRedirect();
        $this->como($this->auxiliar)->post("patrimonio/itens/{$pendente}/movimentar", [
            'destino' => "almoxarifado:{$this->almoxarifado}", 'destinatario_id' => (string) $this->auxiliar['id_usuario'],
        ])->assertRedirect();
    }

    public function testItemAvulsoTemDadosProprios(): void
    {
        $categoria = $this->criarCategoriaItem('Informática');

        $this->como($this->auxiliar)->post('patrimonio/itens', [
            'nome' => 'PC com SSD de 1 TB', 'categoria_item_id' => (string) $categoria, 'marca' => 'Dell',
            'especificacoes' => 'SSD 1 TB, 16 GB', 'patrimoniavel' => '1', 'numero_patrimonio' => 'FJ-1',
            'local' => "ambiente:{$this->laboratorio}",
        ])->assertRedirect();

        $this->seeInDatabase('itens', [
            'nome' => 'PC com SSD de 1 TB', 'modelo_item_id' => null, 'numero_patrimonio' => 'FJ-1',
            'patrimoniavel' => 1, 'quantidade' => 1, 'ambiente_id' => $this->laboratorio, 'status' => 'em_uso',
        ]);

        // Sem patrimônio: um registro com a quantidade; número é ignorado.
        $this->como($this->auxiliar)->post('patrimonio/itens', [
            'nome' => 'Marcador azul', 'categoria_item_id' => (string) $categoria, 'quantidade' => '50',
            'numero_patrimonio' => 'IGNORADO', 'local' => "almoxarifado:{$this->almoxarifado}",
        ])->assertRedirect();
        $this->seeInDatabase('itens', ['nome' => 'Marcador azul', 'patrimoniavel' => 0, 'numero_patrimonio' => null, 'quantidade' => 50]);

        // Número repetido, quantidade inválida e local fora do escopo: recusados.
        $this->como($this->auxiliar)->post('patrimonio/itens', [
            'nome' => 'Outro PC', 'categoria_item_id' => (string) $categoria, 'patrimoniavel' => '1',
            'numero_patrimonio' => 'FJ-1', 'local' => "almoxarifado:{$this->almoxarifado}",
        ])->assertStatus(422);
        $this->como($this->auxiliar)->post('patrimonio/itens', [
            'nome' => 'Marcador', 'categoria_item_id' => (string) $categoria, 'quantidade' => '0',
            'local' => "almoxarifado:{$this->almoxarifado}",
        ])->assertStatus(422);
        $alheio = $this->criarAlmoxarifado($this->criarCampus('Campus B'));
        $this->como($this->auxiliar)->post('patrimonio/itens', [
            'nome' => 'PC', 'categoria_item_id' => (string) $categoria, 'patrimoniavel' => '1', 'local' => "almoxarifado:{$alheio}",
        ])->assertStatus(422);
        $this->assertSame(2, $this->db->table('itens')->countAllResults());
    }

    public function testTrocarPecaAlteraSoOItemNaoOModelo(): void
    {
        $modelo = $this->criarModelo('Desktop Dell');
        $item   = $this->criarItem($modelo, $this->laboratorio, null, 'FJ-9');
        $outro  = $this->criarItem($modelo, $this->laboratorio, null, 'FJ-10');
        $dados  = $this->db->table('itens')->where('id_item', $item)->get()->getRowArray();

        $this->como($this->auxiliar)->post("patrimonio/itens/{$item}", [
            'nome' => 'Desktop Dell (SSD trocado)', 'categoria_item_id' => (string) $dados['categoria_item_id'],
            'especificacoes' => 'SSD 2 TB', 'patrimoniavel' => '1', 'numero_patrimonio' => 'FJ-9',
        ])->assertRedirect();

        $this->seeInDatabase('itens', ['id_item' => $item, 'nome' => 'Desktop Dell (SSD trocado)', 'especificacoes' => 'SSD 2 TB']);
        $this->seeInDatabase('itens', ['id_item' => $outro, 'nome' => 'Desktop Dell', 'especificacoes' => null]);
        $this->seeInDatabase('modelos_itens', ['id_modelo_item' => $modelo, 'nome' => 'Desktop Dell', 'especificacoes' => null]);

        // O número, uma vez dado, não é retirado.
        $this->como($this->auxiliar)->post("patrimonio/itens/{$item}", [
            'nome' => 'Desktop Dell', 'categoria_item_id' => (string) $dados['categoria_item_id'], 'numero_patrimonio' => '',
        ])->assertStatus(422);
        $this->seeInDatabase('itens', ['id_item' => $item, 'numero_patrimonio' => 'FJ-9']);
    }

    public function testMoverParteDaQuantidadeSeparaEChegandoReune(): void
    {
        $item = $this->criarItem($this->criarModelo('Marcador', false), null, $this->almoxarifado, null, 10);
        $fica = (string) $this->auxiliar['id_usuario'];

        // Quantidade acima da que existe é recusada; 4 de 10 segue em trânsito, os 6 continuam no almoxarifado.
        $this->como($this->auxiliar)->post("patrimonio/itens/{$item}/movimentar", [
            'destino' => "ambiente:{$this->laboratorio}", 'destinatario_id' => $fica, 'quantidade' => '11',
        ])->assertStatus(422);

        $this->como($this->auxiliar)->post("patrimonio/itens/{$item}/movimentar", [
            'destino' => "ambiente:{$this->laboratorio}", 'destinatario_id' => $fica, 'quantidade' => '4',
        ])->assertRedirect();

        $this->seeInDatabase('itens', ['id_item' => $item, 'quantidade' => 6, 'status' => 'almoxarifado']);
        $mov = $this->db->table('movimentacoes_itens')->get()->getRowArray();
        $this->assertNotSame($item, (int) $mov['item_id']);
        $this->seeInDatabase('itens', ['id_item' => $mov['item_id'], 'quantidade' => 4, 'status' => 'em_transito', 'almoxarifado_id' => $this->almoxarifado]);

        $this->como($this->auxiliar)->post('patrimonio/movimentacoes/' . $mov['id_movimentacao_item'] . '/confirmar')->assertRedirect();
        $this->seeInDatabase('itens', ['id_item' => $mov['item_id'], 'quantidade' => 4, 'status' => 'em_uso', 'ambiente_id' => $this->laboratorio]);

        // Mais 2 para o mesmo ambiente: ao chegar, somam ao item igual que já está lá (um registro só).
        $this->como($this->auxiliar)->post("patrimonio/itens/{$item}/movimentar", [
            'destino' => "ambiente:{$this->laboratorio}", 'destinatario_id' => $fica, 'quantidade' => '2',
        ])->assertRedirect();
        $segunda = $this->db->table('movimentacoes_itens')->orderBy('id_movimentacao_item', 'DESC')->get()->getRowArray();
        $this->como($this->auxiliar)->post('patrimonio/movimentacoes/' . $segunda['id_movimentacao_item'] . '/confirmar')->assertRedirect();

        $noLab = $this->db->table('itens')->where(['ambiente_id' => $this->laboratorio, 'deletado_em' => null])->get()->getResultArray();
        $this->assertCount(1, $noLab);
        $this->assertSame(6, (int) $noLab[0]['quantidade']);
        $this->seeInDatabase('itens', ['id_item' => $item, 'quantidade' => 4, 'status' => 'almoxarifado']);

        // Cancelar um envio parcial devolve a parte ao registro de origem.
        $this->como($this->auxiliar)->post("patrimonio/itens/{$item}/movimentar", [
            'destino' => "ambiente:{$this->laboratorio}", 'destinatario_id' => $fica, 'quantidade' => '3',
        ])->assertRedirect();
        $terceira = $this->db->table('movimentacoes_itens')->orderBy('id_movimentacao_item', 'DESC')->get()->getRowArray();
        $this->como($this->auxiliar)->excluir('patrimonio/movimentacoes/' . $terceira['id_movimentacao_item'])->assertRedirect();
        $this->seeInDatabase('itens', ['id_item' => $item, 'quantidade' => 4, 'status' => 'almoxarifado', 'deletado_em' => null]);
        $this->assertSame(6, (int) $this->db->table('itens')->where('ambiente_id', $this->laboratorio)->where('deletado_em', null)->get()->getRow()->quantidade);
    }

    public function testDescarteParcialSeparaERejeitadoReune(): void
    {
        $item = $this->criarItem($this->criarModelo('Apagador', false), $this->laboratorio, null, null, 20);
        $motivo = (string) $this->db->table('motivos_descarte')->get()->getRow()->id_motivo_descarte;

        $this->como($this->auxiliar)->post("patrimonio/itens/{$item}/descartar", [
            'motivo_descarte_id' => $motivo, 'quantidade' => '5',
        ])->assertRedirect();

        $this->seeInDatabase('itens', ['id_item' => $item, 'quantidade' => 15, 'status' => 'em_uso']);
        $descarte = $this->db->table('descartes')->get()->getRowArray();
        $this->assertNotSame($item, (int) $descarte['item_id']);
        $this->seeInDatabase('itens', ['id_item' => $descarte['item_id'], 'quantidade' => 5]);

        // Quem decide é a coordenação (patrimonio.aprovar_descarte).
        $coord = $this->criarUsuario(['nome' => 'Carla Coordenação']);
        $this->vincular($coord['id_usuario'], 'Coordenação', 'campus', $this->campus);
        $this->como($coord)->post('patrimonio/descartes/' . $descarte['id_descarte'] . '/rejeitar')->assertRedirect();
        $this->seeInDatabase('itens', ['id_item' => $item, 'quantidade' => 20, 'deletado_em' => null]);
    }

    public function testPlanilhaEmBrancoDeModeloEImportacaoCriamItensNumerados(): void
    {
        $modelo = $this->criarModelo('Notebook Dell');
        $cabo   = $this->criarModelo('Cabo HDMI', false);
        $local  = "almoxarifado:{$this->almoxarifado}";

        // A tela mostra as duas formas de preencher, com o aviso.
        $tela = $this->como($this->auxiliar)->get('patrimonio/numeros');
        $tela->assertSee('Itens novos de um modelo');
        $tela->assertSee('Baixar planilha em branco');
        $tela->assertSee('só pode ser movido, alocado e usado depois de receber um número');

        // Planilha em branco: Excel, modelo patrimoniável apenas, dentro do limite e do escopo.
        $ok = $this->como($this->auxiliar)->get('patrimonio/numeros/modelo', ['modelo_novo' => (string) $modelo, 'quantidade' => '3', 'local' => $local]);
        $this->assertInstanceOf(DownloadResponse::class, $ok->response());
        $this->como($this->auxiliar)->get('patrimonio/numeros/modelo', ['modelo_novo' => (string) $cabo, 'quantidade' => '3', 'local' => $local])->assertRedirect();
        $this->como($this->auxiliar)->get('patrimonio/numeros/modelo', ['modelo_novo' => (string) $modelo, 'quantidade' => '0', 'local' => $local])->assertRedirect();
        $alheio = $this->criarAlmoxarifado($this->criarCampus('Campus B'));
        $this->como($this->auxiliar)->get('patrimonio/numeros/modelo', ['modelo_novo' => (string) $modelo, 'quantidade' => '2', 'local' => "almoxarifado:{$alheio}"])->assertRedirect();

        // Importação: cada linha preenchida cria um item numerado; as ruins são rejeitadas sozinhas.
        $this->criarItem($modelo, null, $this->almoxarifado, 'FJ-JA');
        $arquivo = $this->planilhaNovos([
            [(string) $modelo, 'Notebook Dell', $local, 'Almox', 'FJ-100'],
            [(string) $modelo, 'Notebook Dell', $local, 'Almox', 'FJ-101'],
            [(string) $modelo, 'Notebook Dell', $local, 'Almox', ''],                       // em branco: ignorada
            [(string) $modelo, 'Notebook Dell', $local, 'Almox', 'FJ-100'],                 // repetido na planilha
            [(string) $modelo, 'Notebook Dell', $local, 'Almox', 'FJ-JA'],                  // já em uso
            [(string) $cabo, 'Cabo HDMI', $local, 'Almox', 'FJ-102'],                       // modelo sem patrimônio
            ['999999', 'Fantasma', $local, 'Almox', 'FJ-103'],                              // modelo inexistente
            [(string) $modelo, 'Notebook Dell', "almoxarifado:{$alheio}", 'Alheio', 'FJ-104'], // fora do escopo
        ]);

        $resultado = (new ImportadorNovosItens($this->db))->importar(
            $arquivo,
            fn (array $registro): bool => (int) $registro['almoxarifado_id'] === $this->almoxarifado
        );

        $this->assertNotNull($resultado);
        $this->assertSame(2, $resultado['gravados']);
        $this->assertSame(
            [
                [5, 'Patrimonio.importDuplicateInFile'],
                [6, 'Patrimonio.numberInUse'],
                [7, 'Patrimonio.importModelNotAsset'],
                [8, 'Patrimonio.importModelInvalid'],
                [9, 'Patrimonio.importLocationInvalid'],
            ],
            array_map(static fn (array $r): array => [$r['linha'], $r['motivo']], $resultado['rejeitados'])
        );
        $this->seeInDatabase('itens', ['numero_patrimonio' => 'FJ-100', 'nome' => 'Notebook Dell', 'modelo_item_id' => $modelo, 'patrimoniavel' => 1, 'almoxarifado_id' => $this->almoxarifado, 'status' => 'almoxarifado']);
        $this->seeInDatabase('itens', ['numero_patrimonio' => 'FJ-101']);
    }

    public function testNumerosFiltraPorModeloEPreencheItensJaLancados(): void
    {
        $notebook = $this->criarModelo('Notebook');
        $monitor  = $this->criarModelo('Monitor');
        $a        = $this->criarItem($notebook, $this->laboratorio);
        $b        = $this->criarItem($monitor, $this->laboratorio);

        $todos = $this->como($this->auxiliar)->get('patrimonio/numeros');
        $todos->assertSee('Notebook');
        $todos->assertSee('Monitor');
        $this->assertStringContainsString('bg-action-edit/15', (string) $todos->getBody());

        $filtrado = $this->como($this->auxiliar)->get('patrimonio/numeros', ['modelo' => (string) $notebook, 'parcial' => '1']);
        $filtrado->assertSee('Notebook');
        $filtrado->assertDontSee('Monitor');

        $this->assertNotSame($a, $b);
    }

    public function testChamadosDoAmbienteEAberturaDaPaginaDoAmbiente(): void
    {
        $pagina = $this->como($this->auxiliar)->get("ambientes/{$this->laboratorio}");
        $pagina->assertSee('Chamados do ambiente');
        $pagina->assertSee('Abrir chamado');

        // Abre o formulário (modal) com o ambiente fixo e grava; o chamado entra na tabela do ambiente.
        $form = $this->como($this->auxiliar)->get('chamados/novo', ['ambiente' => (string) $this->laboratorio, 'modal' => '1']);
        $form->assertOk();
        $this->assertStringContainsString('data-tabela="ambiente-chamados"', (string) $form->getBody());

        $this->como($this->auxiliar)->post('chamados?modal=1', [
            'ambiente_id' => (string) $this->laboratorio, 'ambiente_fixo' => '1', 'descricao' => 'Projetor sem imagem',
        ])->assertOk();
        $this->seeInDatabase('chamados', ['ambiente_id' => $this->laboratorio, 'descricao' => 'Projetor sem imagem', 'status' => 'aberto']);

        $this->como($this->auxiliar)->get("ambientes/{$this->laboratorio}/chamados", ['parcial' => '1'])->assertSee('Projetor sem imagem');

        // Quem não está logado não abre nem vê; ambiente fora do escopo é recusado.
        $this->get("ambientes/{$this->laboratorio}/chamados")->assertRedirect();
        $alheio = $this->criarAmbiente($this->criarPredio($this->criarCampus('Campus B'), 'Prédio B'), '201', 'Sala B');
        $this->como($this->auxiliar)->get('chamados/novo', ['ambiente' => (string) $alheio, 'modal' => '1'])->assertStatus(403);
        $this->como($this->auxiliar)->get("ambientes/{$alheio}/chamados", ['parcial' => '1'])->assertStatus(403);
    }

    /** @param list<list<int|string>> $linhas */
    private function planilhaNovos(array $linhas): string
    {
        $this->arquivo = (string) tempnam(sys_get_temp_dir(), 'imp');
        $writer        = new Writer();
        $writer->openToFile($this->arquivo);
        $writer->addRow(Row::fromValues(['ID do modelo', 'Modelo', 'Código do local', 'Local', 'Número de patrimônio']));

        foreach ($linhas as $linha) {
            $writer->addRow(Row::fromValues($linha));
        }

        $writer->close();

        return $this->arquivo;
    }
}

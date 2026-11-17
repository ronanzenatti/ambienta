<?php

use Tests\Support\FeatureBancoTestCase;

/**
 * Opções dos selects do banco sob demanda (rota opcoes/<fonte>): pesquisa, paginação de 20 em 20, escopo
 * de quem consulta, filtros e permissão (públicas sem login; as demais exigem login e a permissão certa).
 *
 * @internal
 */
final class OpcoesTest extends FeatureBancoTestCase
{
    /** @var array<string, mixed> Direção, escopo global */
    private array $direcao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->direcao = $this->criarUsuario(['nome' => 'Diretora']);
        $this->vincular($this->direcao['id_usuario'], 'Direção');
    }

    /**
     * @param array<string, mixed>|null $usuario
     * @param array<string, mixed>      $query
     *
     * @return array{status: int, itens: list<array{valor: string, rotulo: string}>, proxima: ?string}
     */
    private function opcoes(?array $usuario, string $fonte, array $query = []): array
    {
        $resposta = $this->como($usuario)->get('opcoes/' . $fonte, $query);
        $json     = json_decode((string) $resposta->response()->getBody(), true);

        return ['status' => $resposta->response()->getStatusCode(), 'itens' => $json['itens'] ?? [], 'proxima' => $json['proxima'] ?? null];
    }

    /** @param list<array{valor: string, rotulo: string}> $itens */
    private function rotulos(array $itens): array
    {
        return array_column($itens, 'rotulo');
    }

    public function testPaginaDeVinteComProximaEPesquisa(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->criarCurso(sprintf('Curso %02d', $i));
        }
        $this->criarCurso('Análise de Sistemas');

        $primeira = $this->opcoes(null, 'cursos');
        $this->assertSame(200, $primeira['status'], 'cursos é pública (consulta sem login)');
        $this->assertCount(20, $primeira['itens']);
        $this->assertNotNull($primeira['proxima']);
        $this->assertStringContainsString('pagina=2', (string) $primeira['proxima']);

        $segunda = $this->opcoes(null, 'cursos', ['pagina' => 2]);
        $this->assertCount(6, $segunda['itens']);
        $this->assertNull($segunda['proxima']);

        $busca = $this->opcoes(null, 'cursos', ['q' => 'analise sis']);
        $this->assertSame(['Análise de Sistemas'], $this->rotulos($busca['itens']), 'sem acento, cada palavra');

        $curinga = $this->opcoes(null, 'cursos', ['q' => '%']);
        $this->assertSame([], $curinga['itens'], '% é literal');
    }

    public function testAmbientesELocaisRespeitamOEscopo(): void
    {
        $campusA = $this->criarCampus('Campus A');
        $campusB = $this->criarCampus('Campus B');
        $this->criarAmbiente($this->criarPredio($campusA, 'Bloco A'), '101', 'Lab A');
        $this->criarAmbiente($this->criarPredio($campusB, 'Bloco B'), '201', 'Lab B');
        $this->db->table('almoxarifados')->insert(['campus_id' => $campusA, 'nome' => 'Almox A']);

        $coord = $this->criarUsuario(['nome' => 'Coord A']);
        $this->vincular($coord['id_usuario'], 'Coordenação', 'campus', $campusA);

        $ambientes = $this->rotulos($this->opcoes($coord, 'ambientes', ['chave' => 'institucional.acesso', 'nivel' => 'editor'])['itens']);
        $this->assertSame(['Campus A › Bloco A › 101 Lab A'], $ambientes);

        $todos = $this->rotulos($this->opcoes($this->direcao, 'ambientes', ['chave' => 'institucional.acesso'])['itens']);
        $this->assertCount(2, $todos);

        $locais = $this->opcoes($this->direcao, 'locais', ['chave' => 'patrimonio.acesso']);
        $this->assertSame('Almox A — Campus A', $locais['itens'][0]['rotulo'], 'almoxarifados primeiro');
        $this->assertStringStartsWith('almoxarifado:', $locais['itens'][0]['valor']);
        $this->assertCount(3, $locais['itens']);

        $semAlmox = $this->rotulos($this->opcoes($this->direcao, 'campi', ['chave' => 'patrimonio.acesso', 'nivel' => 'editor', 'sem_almoxarifado' => '1'])['itens']);
        $this->assertSame(['Campus B'], $semAlmox);

        $predios = $this->rotulos($this->opcoes($this->direcao, 'predios', ['chave' => 'relatorios.acesso', 'campus' => $campusB])['itens']);
        $this->assertSame(['Campus B › Bloco B'], $predios);

        $this->assertSame(403, $this->opcoes(null, 'ambientes', ['chave' => 'institucional.acesso'])['status']);
    }

    public function testFiltrosDeDisciplinaSoftwareECurso(): void
    {
        $curso      = $this->criarCurso('ADS');
        $outro      = $this->criarCurso('GTI');
        $this->criarDisciplina('ALG', 'Algoritmos', $curso);
        $this->criarDisciplina('BD', 'Banco de Dados', $outro);

        $doCurso = $this->rotulos($this->opcoes(null, 'disciplinas', ['curso' => $curso])['itens']);
        $this->assertSame(['ALG · Algoritmos'], $doCurso);


        $ambiente = $this->criarAmbiente($this->criarPredio($this->criarCampus('C'), 'P'), '1', 'Lab');
        $this->db->table('softwares')->insertBatch([['nome' => 'Python', 'versao' => '3.12'], ['nome' => 'Java', 'versao' => null]]);
        $python = (int) $this->db->table('softwares')->where('nome', 'Python')->get()->getRow()->id_software;
        $this->db->table('ambientes_softwares')->insert(['ambiente_id' => $ambiente, 'software_id' => $python]);

        $this->assertSame(['Java', 'Python 3.12'], $this->rotulos($this->opcoes($this->direcao, 'softwares')['itens']));
        $this->assertSame(['Java'], $this->rotulos($this->opcoes($this->direcao, 'softwares', ['fora_ambiente' => $ambiente])['itens']));
    }

    public function testPessoasEPerfisExigemAPermissaoDaTela(): void
    {
        $campus  = $this->criarCampus('Campus A');
        $docente = $this->criarUsuario(['nome' => 'Docente']);
        $this->vincular($docente['id_usuario'], 'Docentes', 'campus', $campus);

        $this->assertSame(403, $this->opcoes($docente, 'usuarios', ['chave' => 'agendamento.alocar', 'nivel' => 'editor'])['status']);
        $this->assertSame(403, $this->opcoes($docente, 'usuarios', ['chave' => 'agendamento.acesso'])['status'], 'chave fora da lista');
        $this->assertSame(403, $this->opcoes($docente, 'perfis')['status']);

        $pessoas = $this->rotulos($this->opcoes($this->direcao, 'usuarios', ['chave' => 'agendamento.alocar', 'nivel' => 'editor'])['itens']);
        $this->assertContains('Docente', $pessoas);

        $perfis = $this->rotulos($this->opcoes($this->direcao, 'perfis')['itens']);
        $this->assertContains('Docentes', $perfis);

        $this->assertSame(403, $this->opcoes($this->direcao, 'inexistente')['status']);
    }

    public function testCatalogosESemestresAbertos(): void
    {
        $this->criarSemestre('2025/2', 'encerrado');
        $this->criarSemestre('2026/2', 'aberto');
        $this->criarCategoria('Laboratório');

        $this->assertSame(['2026/2'], $this->rotulos($this->opcoes($this->direcao, 'semestres-abertos')['itens']));
        $this->assertContains('Laboratório', $this->rotulos($this->opcoes($this->direcao, 'categorias-ambiente')['itens']));
        $this->assertSame(200, $this->opcoes($this->direcao, 'motivos-descarte')['status']);
        $this->assertSame(200, $this->opcoes($this->direcao, 'categorias-item')['status']);
        $this->assertSame(200, $this->opcoes($this->direcao, 'modelos')['status']);
        $this->assertSame(200, $this->opcoes($this->direcao, 'destinatarios')['status']);
        $this->assertSame(200, $this->opcoes($this->direcao, 'locais-destino', ['exceto' => ['ambiente:1']])['status']);
        $this->assertSame(403, $this->opcoes(null, 'categorias-ambiente')['status']);
    }

    public function testSelectDoFormularioTrazSoAEscolhida(): void
    {
        helper('ui');
        $a = $this->criarCurso('ADS');
        $this->criarCurso('GTI');

        $html = ui_select_fonte('curso_id', 'Curso', 'cursos', [], $a, 'Selecione');

        $this->assertStringContainsString('x-busca', $html);
        $this->assertStringContainsString('data-fonte="' . esc(site_url('opcoes/cursos'), 'attr') . '"', $html);
        $this->assertMatchesRegularExpression('/value="' . $a . '" selected>\s*ADS\s*</', $html);
        $this->assertStringNotContainsString('GTI', $html);

        $invalido = ui_select_fonte('curso_id', 'Curso', 'cursos', [], 99999, 'Selecione');
        $this->assertStringNotContainsString('value="99999"', $invalido, 'valor fora da fonte não aparece');
    }
}

<?php

use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\Test\TestResponse;
use Tests\Support\FeatureBancoTestCase;

/**
 * Listagem padrão (App\Libraries\Tabela), exercitada pela tela de cursos: busca em todas as colunas
 * visíveis (inclusive rótulos traduzidos), ordenação, registros por página, paginação, fragmento parcial,
 * coluna Ações conforme a permissão e exportação Excel/PDF nas três abrangências.
 *
 * @internal
 */
final class TabelaListagemTest extends FeatureBancoTestCase
{
    /** @var array<string, mixed> editor institucional */
    private array $coordenacao;

    /** @var array<string, mixed> leitor institucional */
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

    private function criarCursos(int $quantos): void
    {
        for ($i = 1; $i <= $quantos; $i++) {
            $this->criarCurso(sprintf('Curso %02d', $i));
        }
    }

    public function testPadraoDezPorPaginaComPaginacaoEResumo(): void
    {
        $this->criarCursos(23);

        $html = $this->html($this->como($this->coordenacao)->get('cursos'));

        $this->assertStringContainsString('Curso 10', $html);
        $this->assertStringNotContainsString('Curso 11', $html);
        $this->assertStringContainsString('Mostrando 1 a 10 de 23 registros', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('cursos?pagina=3', $html);

        $ultima = $this->html($this->como($this->coordenacao)->get('cursos', ['pagina' => 3]));
        $this->assertStringContainsString('Curso 23', $ultima);
        $this->assertStringContainsString('Mostrando 21 a 23 de 23 registros', $ultima);

        // Página além da última cai na última; "por" fora da lista volta ao padrão.
        $alem = $this->html($this->como($this->coordenacao)->get('cursos', ['pagina' => 99, 'por' => 7]));
        $this->assertStringContainsString('Mostrando 21 a 23 de 23 registros', $alem);

        $vinte = $this->html($this->como($this->coordenacao)->get('cursos', ['por' => 25]));
        $this->assertStringContainsString('Mostrando 1 a 23 de 23 registros', $vinte);
    }

    public function testBuscaEmColunasVisiveisERotulosTraduzidos(): void
    {
        $this->criarCurso('Análise e Desenvolvimento de Sistemas');
        $this->criarCurso('Gestão Empresarial');
        $this->criarCurso('MBA em Dados', 'lato_sensu');

        $porNome = $this->html($this->como($this->coordenacao)->get('cursos', ['q' => 'analise']));
        $this->assertStringContainsString('Análise e Desenvolvimento', $porNome, 'sem acento e sem maiúscula');
        $this->assertStringNotContainsString('Gestão Empresarial', $porNome);
        $this->assertStringContainsString('(pesquisa em 3 registros)', $porNome);

        $porTipo = $this->html($this->como($this->coordenacao)->get('cursos', ['q' => 'lato']));
        $this->assertStringContainsString('MBA em Dados', $porTipo, 'o rótulo "Lato sensu" é pesquisável');
        $this->assertStringNotContainsString('Gestão Empresarial', $porTipo);

        $palavras = $this->html($this->como($this->coordenacao)->get('cursos', ['q' => 'gestão emp']));
        $this->assertStringContainsString('Gestão Empresarial', $palavras, 'cada palavra em qualquer coluna');

        $curinga = $this->html($this->como($this->coordenacao)->get('cursos', ['q' => '%']));
        $this->assertStringContainsString('Nenhum registro encontrado para “%”.', $curinga, '% é literal, não curinga');
    }

    public function testOrdenacaoSoPorColunaConhecida(): void
    {
        $this->criarCurso('Bravo');
        $this->criarCurso('Alfa');
        $this->criarCurso('Charlie');

        $desc = $this->html($this->como($this->coordenacao)->get('cursos', ['ordem' => 'nome', 'dir' => 'desc']));
        $this->assertLessThan(strpos($desc, 'Alfa'), strpos($desc, 'Charlie'));
        $this->assertStringContainsString('aria-sort="descending"', $desc);

        $invalida = $this->como($this->coordenacao)->get('cursos', ['ordem' => 'nome; DROP TABLE cursos', 'dir' => 'x']);
        $invalida->assertStatus(200);
        $html = $this->html($invalida);
        $this->assertLessThan(strpos($html, 'Bravo'), strpos($html, 'Alfa'), 'volta à ordem padrão');
    }

    public function testParcialDevolveSoARegiaoDaTabela(): void
    {
        $this->criarCursos(3);

        $html = $this->html($this->como($this->coordenacao)->get('cursos', ['parcial' => '1']));

        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('data-resumo="Mostrando 1 a 3 de 3 registros"', $html);
        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringNotContainsString('role="search"', $html, 'a barra de busca fica fora da região');
    }

    public function testAcoesConformeAPermissao(): void
    {
        $id = $this->criarCurso('Tecnologia');

        $editor = $this->html($this->como($this->coordenacao)->get('cursos'));
        $this->assertStringContainsString('aria-label="Alterar: Tecnologia"', $editor);
        $this->assertStringContainsString('aria-label="Excluir: Tecnologia"', $editor);
        $this->assertStringContainsString('data-acao="' . site_url("cursos/{$id}") . '"', $editor);
        $this->assertMatchesRegularExpression('/<button[^>]*form="excluir-cursos-form"[^>]*\sdisabled/', $editor);

        $leitor = $this->html($this->como($this->docente)->get('cursos'));
        $this->assertStringNotContainsString('bg-action-edit', $leitor);
        $this->assertDoesNotMatchRegularExpression('/<(a|button)\s[^>]*bg-signal-critical/', $leitor);
        $this->assertStringNotContainsString('excluir-cursos', $leitor);

        $disciplina = $this->criarDisciplina('ALG', 'Algoritmos');
        $this->assertStringContainsString(
            'aria-label="Ver: Algoritmos"',
            $this->html($this->como($this->docente)->get('disciplinas')),
            'quem só consulta ainda tem o botão Ver'
        );
        $this->assertGreaterThan(0, $disciplina);
    }

    public function testExportaExcelNasTresAbrangencias(): void
    {
        $this->criarCursos(12);
        $this->criarCurso('Zeta Especial');

        $contar = function (array $query): int {
            $resposta = $this->como($this->docente)->get('cursos/exportar', $query)->response();
            $this->assertInstanceOf(DownloadResponse::class, $resposta);

            $arquivo = tempnam(sys_get_temp_dir(), 'xlsx');
            file_put_contents($arquivo, $this->conteudo($resposta));
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($arquivo));
            $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();
            unlink($arquivo);

            return substr_count($xml, '<row ') - 1; // menos o cabeçalho
        };

        $this->assertSame(10, $contar(['formato' => 'xlsx', 'abrangencia' => 'pagina']));
        $this->assertSame(1, $contar(['formato' => 'xlsx', 'abrangencia' => 'pesquisa', 'q' => 'zeta']));
        $this->assertSame(13, $contar(['formato' => 'xlsx', 'abrangencia' => 'tudo', 'q' => 'zeta']));
    }

    public function testExportaPdf(): void
    {
        $this->criarCursos(2);

        $resposta = $this->como($this->docente)->get('cursos/exportar', ['formato' => 'pdf', 'abrangencia' => 'tudo'])->response();

        $this->assertInstanceOf(DownloadResponse::class, $resposta);
        $this->assertStringStartsWith('%PDF', $this->conteudo($resposta));
    }

    /** HTML bruto da resposta, com as entidades de atributo (esc 'attr') decodificadas. */
    private function html(TestResponse $resposta): string
    {
        return html_entity_decode((string) $resposta->response()->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function conteudo(DownloadResponse $resposta): string
    {
        ob_start();
        $resposta->sendBody();

        return (string) ob_get_clean();
    }
}

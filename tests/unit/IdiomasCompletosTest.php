<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Os arquivos de idioma da interface têm exatamente as mesmas chaves em pt-BR, en e es
 * (Validation é parcial de propósito: o que falta cai no inglês do framework).
 *
 * @internal
 */
final class IdiomasCompletosTest extends CIUnitTestCase
{
    private const ARQUIVOS = [
        'App', 'Ui', 'Auth', 'Usuarios', 'Perfis', 'Dominios', 'Institucional', 'Campus', 'Predios', 'Ambientes',
        'Categorias', 'Cursos', 'Disciplinas', 'Semestres', 'Consulta', 'Tabela', 'Patrimonio',
        'Chamados', 'Agendamento', 'Notificacoes', 'Auditoria', 'Relatorios', 'Errors', 'Painel',
    ];

    /**
     * @param array<string, mixed> $linhas
     *
     * @return list<string>
     */
    private function chaves(array $linhas, string $prefixo = ''): array
    {
        $chaves = [];

        foreach ($linhas as $chave => $valor) {
            is_array($valor)
                ? array_push($chaves, ...$this->chaves($valor, $prefixo . $chave . '.'))
                : $chaves[] = $prefixo . $chave;
        }

        sort($chaves);

        return $chaves;
    }

    /**
     * @return array<string, mixed>
     */
    private function carregar(string $idioma, string $arquivo): array
    {
        return require APPPATH . "Language/{$idioma}/{$arquivo}.php";
    }

    public function testTodosOsIdiomasTemAsMesmasChaves(): void
    {
        foreach (self::ARQUIVOS as $arquivo) {
            $referencia = $this->chaves($this->carregar('pt-BR', $arquivo));

            foreach (['en', 'es'] as $idioma) {
                $traduzido = $this->chaves($this->carregar($idioma, $arquivo));

                $this->assertSame($referencia, $traduzido, "{$idioma}/{$arquivo}");
            }
        }
    }

    public function testNenhumaTraducaoEstaVazia(): void
    {
        foreach (['pt-BR', 'en', 'es'] as $idioma) {
            foreach (self::ARQUIVOS as $arquivo) {
                $linhas = $this->carregar($idioma, $arquivo);

                array_walk_recursive($linhas, function ($texto, $chave) use ($idioma, $arquivo): void {
                    $this->assertNotSame('', trim((string) $texto), "{$idioma}/{$arquivo}: {$chave}");
                });
            }
        }
    }

    public function testMarcadoresDeParametroSaoOsMesmosEmTodosOsIdiomas(): void
    {
        foreach (self::ARQUIVOS as $arquivo) {
            $referencia = $this->carregar('pt-BR', $arquivo);

            foreach ($this->chaves($referencia) as $chave) {
                $marcadores = static function (array $linhas) use ($chave): string {
                    $valor = $linhas;
                    foreach (explode('.', $chave) as $parte) {
                        $valor = $valor[$parte];
                    }
                    preg_match_all('/\{\d+\}/', (string) $valor, $achados);
                    sort($achados[0]);

                    return implode(',', $achados[0]);
                };

                foreach (['en', 'es'] as $idioma) {
                    $this->assertSame(
                        $marcadores($referencia),
                        $marcadores($this->carregar($idioma, $arquivo)),
                        "{$idioma}/{$arquivo}: {$chave}"
                    );
                }
            }
        }
    }

    public function testTodoArquivoDeIdiomaEstaNaChecagemEExisteNosTresIdiomas(): void
    {
        foreach (['pt-BR', 'en', 'es'] as $idioma) {
            $arquivos = array_map(
                static fn (string $caminho): string => basename($caminho, '.php'),
                glob(APPPATH . "Language/{$idioma}/*.php") ?: []
            );
            $esperados = [...self::ARQUIVOS, 'Validation'];

            sort($arquivos);
            sort($esperados);

            $this->assertSame($esperados, $arquivos, $idioma);
        }
    }

    public function testPalavraDeConfirmacaoDaExclusaoContinuaSendoConfirmoEmTodosOsIdiomas(): void
    {
        foreach (['pt-BR', 'en', 'es'] as $idioma) {
            $this->assertSame('CONFIRMO', $this->carregar($idioma, 'Ui')['confirmWord'], $idioma);
        }
    }
}

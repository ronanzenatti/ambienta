<?php

namespace App\Libraries\Tabela;

use Closure;

/**
 * Coluna de uma listagem (ver Tabela). Declarativa: a mesma definição serve à célula HTML, à busca,
 * à ordenação e à exportação (Excel/PDF), para as quatro nunca divergirem.
 *
 * - $sql é a expressão SQL da coluna (ordenação e busca); null = coluna calculada, sem busca nem ordem.
 * - O texto da célula vem de texto() (ou do valor bruto da linha); html() troca só a célula da tela,
 *   a exportação usa sempre o texto.
 * - rotulos() mapeia valores fixos (ENUM, booleano) para rótulos traduzidos: a busca procura pelo
 *   rótulo no idioma do usuário ("Ativo", "Graduação"), não pelo valor gravado.
 */
final class Coluna
{
    private bool $principal = false;
    private bool $numerica  = false;
    private bool $ordenavel;
    private bool $pesquisavel;

    /** @var list<string> */
    private array $buscaSql = [];

    /** @var array<int|string, string>|null */
    private ?array $rotulos = null;

    private ?Closure $texto = null;
    private ?Closure $html  = null;

    private function __construct(
        public readonly string $chave,
        public readonly string $rotulo,
        public readonly ?string $sql,
    ) {
        $this->ordenavel   = $sql !== null;
        $this->pesquisavel = $sql !== null;

        if ($sql !== null) {
            $this->buscaSql = [$sql];
        }
    }

    /**
     * @param string      $chave  Alias do campo na linha (o SELECT da Tabela deve trazê-lo com esse nome)
     * @param string      $rotulo Cabeçalho, já traduzido
     * @param string|null $sql    Expressão SQL da coluna (ex.: 'cursos.nome'); null = calculada
     */
    public static function nova(string $chave, string $rotulo, ?string $sql = null): self
    {
        return new self($chave, $rotulo, $sql);
    }

    /** Situação ativo/inativo (BOOLEAN): ponto colorido + rótulo, pesquisável por "Ativo"/"Inativo". */
    public static function ativo(string $sql, string $chave = 'ativo'): self
    {
        return self::nova($chave, lang('Ui.status'), $sql)
            ->rotulos([1 => lang('Ui.active'), 0 => lang('Ui.inactive')])
            ->html(static fn (array $linha, string $texto): string => ui_status_dot(
                (bool) $linha[$chave] ? 'confirmed' : 'critical',
                $texto
            ));
    }

    /** Coluna que identifica a linha: vira <th scope="row"> em negrito. */
    public function principal(): self
    {
        $this->principal = true;

        return $this;
    }

    /** Números: alinhados à direita, algarismos tabulares. */
    public function numerica(): self
    {
        $this->numerica = true;

        return $this;
    }

    public function semOrdem(): self
    {
        $this->ordenavel = false;

        return $this;
    }

    public function semBusca(): self
    {
        $this->pesquisavel = false;

        return $this;
    }

    /**
     * Expressões SQL onde a busca procura (substitui a expressão da coluna). Use para colunas cujo texto
     * junta mais de um campo visível (ex.: nome + descrição logo abaixo).
     */
    public function buscaEm(string ...$expressoes): self
    {
        $this->buscaSql    = array_values($expressoes);
        $this->pesquisavel = $expressoes !== [];

        return $this;
    }

    /** @param array<int|string, string> $rotulos valor gravado => rótulo traduzido */
    public function rotulos(array $rotulos): self
    {
        $this->rotulos = $rotulos;

        return $this;
    }

    /** Data (DATE): exibida e pesquisada no formato do idioma (Institucional.dateFormat). */
    public function data(): self
    {
        return $this->comFormatoData((string) lang('Institucional.dateFormat'));
    }

    /** Data e hora (DATETIME), no formato do idioma, sem segundos; $vazio é o texto quando é NULL. */
    public function dataHora(string $vazio = ''): self
    {
        return $this->comFormatoData(lang('Institucional.dateFormat') . ' H:i', $vazio);
    }

    private function comFormatoData(string $formato, string $vazio = ''): self
    {
        $this->numerica = true;
        $this->texto    = static function (array $linha, mixed $valor) use ($formato, $vazio): string {
            return $valor === null || $valor === '' ? $vazio : date($formato, (int) strtotime((string) $valor));
        };

        if ($this->sql !== null) {
            $mysql          = strtr($formato, ['d' => '%d', 'm' => '%m', 'Y' => '%Y', 'H' => '%H', 'i' => '%i']);
            $this->buscaSql = ["DATE_FORMAT({$this->sql}, '{$mysql}')"];
        }

        return $this;
    }

    /** @param Closure(array<string, mixed>, mixed): string $texto texto da célula (tela e exportação) */
    public function texto(Closure $texto): self
    {
        $this->texto = $texto;

        return $this;
    }

    /** @param Closure(array<string, mixed>, string): string $html célula rica na tela (recebe o texto já calculado) */
    public function html(Closure $html): self
    {
        $this->html = $html;

        return $this;
    }

    public function ehPrincipal(): bool
    {
        return $this->principal;
    }

    public function ehNumerica(): bool
    {
        return $this->numerica;
    }

    public function ehOrdenavel(): bool
    {
        return $this->ordenavel && $this->sql !== null;
    }

    public function ehPesquisavel(): bool
    {
        return $this->pesquisavel && $this->buscaSql !== [];
    }

    /** @return list<string> */
    public function expressoesBusca(): array
    {
        return $this->ehPesquisavel() ? $this->buscaSql : [];
    }

    /**
     * Valores gravados cujo rótulo contém o termo (sem diferenciar maiúsculas e acentos).
     *
     * @return list<int|string>|null null quando a coluna não tem rótulos fixos
     */
    public function valoresPorRotulo(string $termo): ?array
    {
        if ($this->rotulos === null) {
            return null;
        }

        $alvo    = self::normalizar($termo);
        $valores = [];

        foreach ($this->rotulos as $valor => $rotulo) {
            if (str_contains(self::normalizar($rotulo), $alvo)) {
                $valores[] = $valor;
            }
        }

        return $valores;
    }

    /** @param array<string, mixed> $linha */
    public function textoDe(array $linha): string
    {
        $valor = $linha[$this->chave] ?? null;

        if ($this->texto !== null) {
            return ($this->texto)($linha, $valor);
        }

        if ($this->rotulos !== null) {
            $chave = is_bool($valor) ? (int) $valor : $valor;

            return $this->rotulos[$chave] ?? (string) $valor;
        }

        return $valor === null ? '' : (string) $valor;
    }

    /** @param array<string, mixed> $linha */
    public function htmlDe(array $linha): string
    {
        $texto = $this->textoDe($linha);

        return $this->html === null ? esc($texto) : ($this->html)($linha, $texto);
    }

    private static function normalizar(string $texto): string
    {
        $semAcento = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $texto);

        return is_string($semAcento) ? $semAcento : mb_strtolower($texto);
    }
}

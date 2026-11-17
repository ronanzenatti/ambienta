<?php

namespace App\Libraries\Tabela;

/**
 * Uma página de uma listagem, com os totais para o resumo "1–10 de 312".
 */
final class Resultado
{
    /**
     * @param list<array<string, mixed>> $linhas
     * @param int                        $total      registros que passam na busca
     * @param int                        $totalGeral registros sem a busca (igual a $total quando não há busca)
     */
    public function __construct(
        public readonly array $linhas,
        public readonly int $total,
        public readonly int $totalGeral,
        public readonly int $pagina,
        public readonly int $paginas,
        public readonly int $porPagina,
    ) {
    }

    public function inicio(): int
    {
        return $this->total === 0 ? 0 : ($this->pagina - 1) * $this->porPagina + 1;
    }

    public function fim(): int
    {
        return min($this->total, $this->pagina * $this->porPagina);
    }

    /**
     * Páginas a mostrar na paginação: primeira, última e vizinhas da atual; null marca o salto (…).
     *
     * @return list<int|null>
     */
    public function paginasVisiveis(): array
    {
        $vizinhas = range(max(1, $this->pagina - 1), min($this->paginas, $this->pagina + 1));
        $numeros  = array_unique([1, $this->paginas, ...$vizinhas]);
        sort($numeros);

        $lista    = [];
        $anterior = 0;

        foreach ($numeros as $numero) {
            if ($numero - $anterior === 2) {
                $lista[] = $numero - 1;
            } elseif ($numero - $anterior > 2) {
                $lista[] = null;
            }

            $lista[]  = $numero;
            $anterior = $numero;
        }

        return $lista;
    }
}

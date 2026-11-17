<?php

namespace App\Libraries\Tabela;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;

/**
 * Estado de uma listagem lido da query string: busca (q), ordenação (ordem, dir), registros por página
 * (por) e página. Tudo validado contra listas fechadas: nada da URL chega cru ao SQL.
 */
final class Parametros
{
    public const POR_PAGINA        = [10, 25, 50, 75, 100];
    public const POR_PAGINA_PADRAO = 10;

    public function __construct(
        public readonly string $busca,
        public readonly string $ordem,
        public readonly string $direcao,
        public readonly int $porPagina,
        public readonly int $pagina,
    ) {
    }

    public static function daRequisicao(RequestInterface $request, Tabela $tabela): self
    {
        $ler = static fn (string $campo): string => $request instanceof IncomingRequest
            ? trim((string) $request->getGet($campo))
            : '';

        [$ordemPadrao, $direcaoPadrao] = $tabela->ordemPadrao();

        $ordem   = $ler('ordem');
        $ordem   = $tabela->colunaOrdenavel($ordem) !== null ? $ordem : $ordemPadrao;
        $direcao = strtolower($ler('dir'));
        if (! in_array($direcao, ['asc', 'desc'], true)) {
            $direcao = $ler('ordem') === '' ? $direcaoPadrao : 'asc';
        }
        $por     = (int) $ler('por');

        return new self(
            mb_substr($ler('q'), 0, 100),
            $ordem,
            $direcao,
            in_array($por, self::POR_PAGINA, true) ? $por : self::POR_PAGINA_PADRAO,
            max(1, (int) $ler('pagina')),
        );
    }

    public function comPagina(int $pagina): self
    {
        return new self($this->busca, $this->ordem, $this->direcao, $this->porPagina, $pagina);
    }

    public function semBusca(): self
    {
        return new self('', $this->ordem, $this->direcao, $this->porPagina, $this->pagina);
    }

    /**
     * Query string da listagem, só com o que difere do padrão (URLs curtas e estáveis), mais os filtros da tela.
     *
     * @param array<string, int|string> $extra
     */
    public function query(Tabela $tabela, array $extra = []): string
    {
        [$ordemPadrao, $direcaoPadrao] = $tabela->ordemPadrao();

        $campos = [
            'q'      => $this->busca,
            'ordem'  => $this->ordem === $ordemPadrao && $this->direcao === $direcaoPadrao ? '' : $this->ordem,
            'dir'    => $this->ordem === $ordemPadrao && $this->direcao === $direcaoPadrao ? '' : $this->direcao,
            'por'    => $this->porPagina === self::POR_PAGINA_PADRAO ? '' : $this->porPagina,
            'pagina' => $this->pagina === 1 ? '' : $this->pagina,
        ];

        $campos = array_filter($extra + $tabela->filtros() + $campos, static fn ($valor): bool => $valor !== '');

        return $campos === [] ? '' : '?' . http_build_query($campos);
    }
}

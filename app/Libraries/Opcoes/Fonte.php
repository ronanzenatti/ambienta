<?php

namespace App\Libraries\Opcoes;

use Closure;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Fonte de opções de um select (ui_select_fonte): a mesma consulta serve à lista sob demanda (pesquisa e
 * página, rota opcoes/<nome>), ao rótulo da opção já escolhida e à validação do valor postado. Assim o
 * que a tela oferece e o que o servidor aceita nunca divergem.
 *
 * A consulta recebida já traz escopo de permissão, deletado_em IS NULL e filtros, e seleciona três
 * colunas: valor, rotulo (o texto exibido e pesquisado) e ordem.
 */
final class Fonte
{
    public const POR_PAGINA = 20;

    /**
     * @param Closure(): BaseBuilder $consulta
     * @param string                 $colunaBusca coluna da consulta onde a pesquisa procura (padrão: o rótulo)
     */
    public function __construct(private readonly Closure $consulta, private readonly string $colunaBusca = 'rotulo')
    {
    }

    /**
     * Uma página da lista: cada palavra da pesquisa precisa aparecer no rótulo, ou na coluna de busca
     * própria da fonte (sem diferenciar maiúsculas).
     *
     * @return array{itens: list<array{valor: string, rotulo: string}>, temMais: bool}
     */
    public function pagina(string $busca, int $pagina): array
    {
        $builder = $this->base();
        $db      = $builder->db();

        foreach (preg_split('/\s+/u', trim(mb_substr($busca, 0, 100)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $palavra) {
            $like = $db->escape('%' . $db->escapeLikeString($palavra) . '%');
            $escape = $db->escape($db->likeEscapeChar);
            $builder->where("o.{$this->colunaBusca} LIKE {$like} ESCAPE {$escape}", null, false);
        }

        $linhas = $builder->select('o.valor, o.rotulo')
            ->orderBy('o.ordem', 'ASC')
            ->orderBy('o.rotulo', 'ASC')
            ->limit(self::POR_PAGINA + 1, (max(1, $pagina) - 1) * self::POR_PAGINA)
            ->get()
            ->getResultArray();

        $itens = array_map(
            static fn (array $l): array => ['valor' => (string) $l['valor'], 'rotulo' => (string) $l['rotulo']],
            array_slice($linhas, 0, self::POR_PAGINA)
        );

        return ['itens' => $itens, 'temMais' => count($linhas) > self::POR_PAGINA];
    }

    /** Rótulo de um valor que a fonte oferece; null quando ela não o oferece (fora do escopo, excluído...). */
    public function rotuloDe(int|string|null $valor): ?string
    {
        if ($valor === null || (string) $valor === '') {
            return null;
        }

        $linha = $this->base()->select('o.rotulo')->where('o.valor', (string) $valor)->get(1)->getRowArray();

        return $linha === null ? null : (string) $linha['rotulo'];
    }

    /** O valor postado é um dos que a fonte oferece? */
    public function contem(int|string|null $valor): bool
    {
        return $this->rotuloDe($valor) !== null;
    }

    /** Oferece alguma opção? (ex.: esconder o botão "Novo" quando não há onde cadastrar) */
    public function existe(): bool
    {
        return $this->base()->select('o.valor')->get(1)->getRowArray() !== null;
    }

    /**
     * Todas as opções, valor => rótulo, na ordem da lista. Só para quem precisa do mapa inteiro fora de um
     * select (relatórios, validação de listas antigas); a tela usa a lista sob demanda.
     *
     * @return array<int|string, string>
     */
    public function todas(): array
    {
        $opcoes = [];

        $linhas = $this->base()->select('o.valor, o.rotulo')->orderBy('o.ordem')->orderBy('o.rotulo')->get();

        foreach ($linhas->getResultArray() as $l) {
            $opcoes[$l['valor']] = (string) $l['rotulo'];
        }

        return $opcoes;
    }

    private function base(): BaseBuilder
    {
        $consulta = ($this->consulta)();

        return $consulta->db()->newQuery()->fromSubquery($consulta, 'o');
    }
}

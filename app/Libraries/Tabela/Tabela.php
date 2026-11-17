<?php

namespace App\Libraries\Tabela;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\RequestInterface;
use Generator;

/**
 * Listagem padrão do sistema (Design System, "Tabela de listagem"): busca em todas as colunas visíveis,
 * ordenação por coluna, 10/25/50/75/100 por página, paginação e carregamento sob demanda (só a página
 * pedida sai do banco), mais a exportação para Excel/PDF (ver Exportador).
 *
 * Cada listagem é uma subclasse (app/Tabelas) que declara a consulta base (já com o escopo de permissão
 * do usuário e sem registros soft-deletados), as colunas e as ações por linha. O resto é daqui.
 */
abstract class Tabela
{
    /** Tamanho do lote lido do banco na exportação (memória limitada na hospedagem compartilhada). */
    private const LOTE_EXPORTACAO = 500;

    /** @var list<Coluna>|null */
    private ?array $colunas = null;

    private ?Parametros $parametros = null;

    /** Identificador da listagem: ids no HTML e nome do arquivo exportado ([a-z0-9-]). */
    abstract public function id(): string;

    /** Título (cabeçalho do PDF e nome da planilha), já traduzido. */
    abstract public function titulo(): string;

    /** URL da página que exibe a listagem (links de ordenação e paginação apontam para ela). */
    abstract public function urlLista(): string;

    /** URL que gera o arquivo exportado (rota GET que chama BaseController::exportarTabela). */
    abstract public function urlExportar(): string;

    /**
     * Consulta base: FROM/JOIN, SELECT com os aliases das colunas, escopo do usuário e deletado_em IS NULL.
     * Sem ORDER BY nem LIMIT (a Tabela aplica). Chamada de novo a cada uso: sempre devolva um builder novo.
     */
    abstract protected function consulta(): BaseBuilder;

    /** Expressão SQL da chave primária: desempate da ordenação, para a paginação ser estável. */
    abstract protected function chavePrimaria(): string;

    /** @return list<Coluna> */
    abstract protected function definirColunas(): array;

    /**
     * Ações da linha (coluna Ações). Chaves: 'ver', 'editar', 'excluir' (URL ou null quando o usuário não
     * pode) e 'extra' (HTML confiável de ações próprias da tela, já escapado).
     *
     * @param array<string, mixed> $linha
     *
     * @return array{ver?: string|null, editar?: string|null, excluir?: string|null, extra?: string}
     */
    public function acoes(array $linha): array
    {
        return [];
    }

    /**
     * Cadastro e alteração em modal (formulários curtos: até uns 5 campos simples, sem upload nem sub-lista).
     * O botão "Novo …" da tela e o Alterar da linha abrem o formulário no modal; ao salvar, a tabela recarrega.
     */
    public function formulariosEmModal(): bool
    {
        return false;
    }

    /**
     * A tela carrega o modal dos formulários, mesmo que Novo/Alterar sejam páginas: serve a uma ação própria
     * da linha ('extra') que abre um formulário curto (ex.: troca rápida de senha).
     */
    public function temModalDeFormulario(): bool
    {
        return $this->formulariosEmModal();
    }

    /**
     * Listagem independente da URL da página: várias na mesma tela (ex.: cartões da página de um ambiente),
     * cada uma com a própria rota (urlLista devolve só o fragmento com ?parcial=1). Busca, ordem e página
     * não vão para a URL da tela, e o modal de formulário só a recarrega quando o evento é dela.
     */
    public function independente(): bool
    {
        return false;
    }

    /**
     * Destaque da linha (classes Tailwind), para a linha que pede atenção (ex.: item patrimonial sem número, em
     * amarelo). A informação nunca vai só na cor: a própria linha precisa dizer o motivo em texto.
     *
     * @param array<string, mixed> $linha
     */
    public function classeLinha(array $linha): string
    {
        return 'even:bg-table-stripe';
    }

    /** Se a listagem tem coluna Ações para este usuário. */
    public function temAcoes(): bool
    {
        return true;
    }

    /**
     * Se alguma linha pode ter Excluir para este usuário: sem isso, o modal de exclusão nem é renderizado
     * (quem só consulta não recebe botão nem formulário de exclusão no HTML).
     */
    public function temExclusao(): bool
    {
        return $this->temAcoes();
    }

    /** @param array<string, mixed> $linha */
    public function mensagemExclusao(array $linha): string
    {
        return '';
    }

    public function tituloExclusao(): string
    {
        return lang('Ui.confirmDeleteTitle');
    }

    public function vazio(): string
    {
        return lang('Tabela.empty');
    }

    /**
     * Filtros próprios da tela (ex.: campus e período de um relatório), já validados: vão em toda URL da
     * listagem (ordenação, paginação, recarga e exportação) junto com a busca, a ordem e a página.
     *
     * @return array<string, string>
     */
    public function filtros(): array
    {
        return [];
    }

    /**
     * Linhas extras no cabeçalho do PDF exportado (ex.: filtros aplicados, totais do relatório).
     *
     * @return list<string>
     */
    public function cabecalhoExportacao(): array
    {
        return [];
    }

    /** @return array{0: string, 1: string} [chave da coluna, 'asc'|'desc'] */
    public function ordemPadrao(): array
    {
        foreach ($this->colunas() as $coluna) {
            if ($coluna->ehOrdenavel()) {
                return [$coluna->chave, 'asc'];
            }
        }

        return ['', 'asc'];
    }

    /**
     * Condições SQL extras da busca, para campos que a tela mostra de outro jeito (ex.: CPF só com dígitos).
     * Recebe uma palavra do termo; devolva condições já escapadas.
     *
     * @return list<string>
     */
    protected function buscaExtra(string $palavra, BaseConnection $db): array
    {
        return [];
    }

    /**
     * Completa as linhas de uma página com dados de outras tabelas (uma consulta por página, não por linha).
     *
     * @param list<array<string, mixed>> $linhas
     *
     * @return list<array<string, mixed>>
     */
    protected function enriquecer(array $linhas): array
    {
        return $linhas;
    }

    /** @return list<Coluna> */
    final public function colunas(): array
    {
        return $this->colunas ??= $this->definirColunas();
    }

    final public function colunaOrdenavel(string $chave): ?Coluna
    {
        foreach ($this->colunas() as $coluna) {
            if ($coluna->chave === $chave && $coluna->ehOrdenavel()) {
                return $coluna;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $linha */
    final public function rotuloDaLinha(array $linha): string
    {
        foreach ($this->colunas() as $coluna) {
            if ($coluna->ehPrincipal()) {
                return $coluna->textoDe($linha);
            }
        }

        return $this->colunas() === [] ? '' : $this->colunas()[0]->textoDe($linha);
    }

    final public function comRequisicao(RequestInterface $request): static
    {
        $this->parametros = Parametros::daRequisicao($request, $this);

        return $this;
    }

    final public function parametros(): Parametros
    {
        if ($this->parametros === null) {
            [$ordem, $direcao] = $this->ordemPadrao();
            $this->parametros  = new Parametros('', $ordem, $direcao, Parametros::POR_PAGINA_PADRAO, 1);
        }

        return $this->parametros;
    }

    final public function resultado(): Resultado
    {
        $parametros = $this->parametros();
        $total      = $this->filtrada($parametros->busca)->countAllResults();
        $paginas    = max(1, (int) ceil($total / $parametros->porPagina));
        $pagina     = min($parametros->pagina, $paginas);

        if ($pagina !== $parametros->pagina) {
            $this->parametros = $parametros = $parametros->comPagina($pagina);
        }

        $linhas = $total === 0 ? [] : $this->ordenada($this->filtrada($parametros->busca), $parametros)
            ->limit($parametros->porPagina, ($pagina - 1) * $parametros->porPagina)
            ->get()
            ->getResultArray();

        return new Resultado(
            $this->enriquecer($linhas),
            $total,
            $parametros->busca === '' ? $total : $this->filtrada('')->countAllResults(),
            $pagina,
            $paginas,
            $parametros->porPagina,
        );
    }

    /** Quantas linhas a exportação teria. */
    final public function contarExportacao(Abrangencia $abrangencia): int
    {
        if ($abrangencia === Abrangencia::Pagina) {
            return count($this->resultado()->linhas);
        }

        return $this->filtrada($abrangencia === Abrangencia::Pesquisa ? $this->parametros()->busca : '')
            ->countAllResults();
    }

    /**
     * Linhas a exportar, na ordem da tela, lidas em lotes.
     *
     * @return Generator<int, array<string, mixed>>
     */
    final public function linhasExportacao(Abrangencia $abrangencia): Generator
    {
        if ($abrangencia === Abrangencia::Pagina) {
            yield from $this->resultado()->linhas;

            return;
        }

        $busca  = $abrangencia === Abrangencia::Pesquisa ? $this->parametros()->busca : '';
        $inicio = 0;

        do {
            $lote = $this->ordenada($this->filtrada($busca), $this->parametros())
                ->limit(self::LOTE_EXPORTACAO, $inicio)
                ->get()
                ->getResultArray();

            yield from $this->enriquecer($lote);

            $inicio += self::LOTE_EXPORTACAO;
        } while (count($lote) === self::LOTE_EXPORTACAO);
    }

    /** A listagem inteira: barra (registros por página e busca), tabela, paginação e o modal de exclusão. */
    final public function renderizar(): string
    {
        return view(
            'components/tabela/tabela',
            ['tabela' => $this, 'regiao' => $this->fragmento()],
            ['saveData' => false]
        );
    }

    /** Só a tabela e a paginação: a parte trocada a cada carregamento sob demanda (?parcial=1). */
    final public function fragmento(): string
    {
        return view(
            'components/tabela/regiao',
            ['tabela' => $this, 'resultado' => $this->resultado(), 'parametros' => $this->parametros()],
            ['saveData' => false]
        );
    }

    /** Menu Exportar (Excel/PDF × página, pesquisa, tudo). Fica fora da tabela, no cabeçalho da tela. */
    final public function botaoExportar(): string
    {
        return view('components/tabela/exportar', ['tabela' => $this], ['saveData' => false]);
    }

    /** Id do modal de exclusão compartilhado pelas linhas. */
    final public function idModalExclusao(): string
    {
        return 'excluir-' . $this->id();
    }

    private function filtrada(string $busca): BaseBuilder
    {
        $builder = $this->consulta();
        $db      = $builder->db();

        foreach (preg_split('/\s+/u', trim($busca), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $palavra) {
            $like      = $db->escape('%' . $db->escapeLikeString($palavra) . '%');
            $escape    = $db->escape($db->likeEscapeChar);
            $condicoes = [];

            foreach ($this->colunas() as $coluna) {
                $valores = $coluna->valoresPorRotulo($palavra);

                if ($valores !== null) {
                    if ($valores !== [] && $coluna->ehPesquisavel()) {
                        $lista       = implode(', ', array_map(static fn ($v) => $db->escape($v), $valores));
                        $condicoes[] = "{$coluna->sql} IN ({$lista})";
                    }

                    continue;
                }

                foreach ($coluna->expressoesBusca() as $expressao) {
                    $condicoes[] = "{$expressao} LIKE {$like} ESCAPE {$escape}";
                }
            }

            array_push($condicoes, ...$this->buscaExtra($palavra, $db));

            $builder->where('(' . ($condicoes === [] ? '1 = 0' : implode(' OR ', $condicoes)) . ')', null, false);
        }

        return $builder;
    }

    private function ordenada(BaseBuilder $builder, Parametros $parametros): BaseBuilder
    {
        $coluna = $this->colunaOrdenavel($parametros->ordem);

        if ($coluna !== null) {
            $builder->orderBy($coluna->sql . ' ' . strtoupper($parametros->direcao), '', false);
        }

        return $builder->orderBy($this->chavePrimaria() . ' ASC', '', false);
    }
}

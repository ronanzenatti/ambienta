<?php

namespace App\Tabelas\Relatorios;

use App\Libraries\Relatorios\Filtros;
use App\Libraries\Tabela\Tabela;

/**
 * Base dos relatórios (REQUISITOS §12): listagem padrão sem ações por linha, com os filtros do relatório
 * (escopo de relatorios.acesso + campus/prédio/período) na consulta, na URL e no cabeçalho do PDF.
 */
abstract class RelatorioTabela extends Tabela
{
    /** @var list<array{0: string, 1: string}>|null */
    private ?array $resumo = null;

    public function __construct(protected readonly Filtros $filtrosRelatorio)
    {
    }

    /** Trecho da URL: relatorios/<slug>. */
    abstract public function slug(): string;

    /** Se o relatório é recortado por período (inventário e itens por status são retrato do momento). */
    public function usaPeriodo(): bool
    {
        return true;
    }

    /** Se o relatório tem o seletor de agrupamento (campus/prédio/ambiente). */
    public function usaAgrupamento(): bool
    {
        return false;
    }

    /**
     * Totais do relatório, em destaque acima da tabela e no cabeçalho do PDF (calculados uma vez).
     *
     * @return list<array{0: string, 1: string}> [rótulo, valor já formatado]
     */
    final public function resumo(): array
    {
        return $this->resumo ??= $this->calcularResumo();
    }

    /**
     * Totais sobre a consulta inteira do relatório (escopo e filtros, sem a busca da tabela).
     *
     * @return list<array{0: string, 1: string}>
     */
    protected function calcularResumo(): array
    {
        return [];
    }

    public function id(): string
    {
        return 'relatorio-' . $this->slug();
    }

    public function titulo(): string
    {
        return lang('Relatorios.title_' . $this->slug());
    }

    public function descricao(): string
    {
        return lang('Relatorios.intro_' . $this->slug());
    }

    public function urlLista(): string
    {
        return site_url('relatorios/' . $this->slug());
    }

    public function urlExportar(): string
    {
        return site_url('relatorios/' . $this->slug() . '/exportar');
    }

    public function filtros(): array
    {
        return $this->filtrosRelatorio->query($this->usaPeriodo(), $this->usaAgrupamento());
    }

    public function cabecalhoExportacao(): array
    {
        $linhas    = [];
        $descricao = $this->filtrosRelatorio->descricao($this->usaPeriodo());

        if ($descricao !== '') {
            $linhas[] = lang('Relatorios.filtersApplied', [$descricao]);
        }

        if ($this->resumo() !== []) {
            $linhas[] = implode(' · ', array_map(
                static fn (array $item): string => $item[0] . ': ' . $item[1],
                $this->resumo()
            ));
        }

        return $linhas;
    }

    public function temAcoes(): bool
    {
        return false;
    }

    public function vazio(): string
    {
        return lang('Relatorios.empty');
    }

    /** Horas (a partir de segundos) com uma casa decimal, no formato do idioma. */
    protected static function horas(mixed $segundos): string
    {
        return self::decimal(((float) $segundos) / 3600);
    }

    protected static function decimal(float $valor): string
    {
        $formatador = new \NumberFormatter(service('request')->getLocale(), \NumberFormatter::DECIMAL);
        $formatador->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 1);
        $formatador->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 1);

        return (string) $formatador->format($valor);
    }
}

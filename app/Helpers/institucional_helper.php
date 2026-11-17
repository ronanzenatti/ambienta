<?php

/**
 * Apoio às telas institucionais: identidade visual do campus e URLs de arquivos enviados.
 */

use App\Libraries\Contraste;

if (! function_exists('campus_estilo')) {
    /**
     * Declarações CSS que trocam brand/brand-ink pela identidade do campus. Vão no atributo style de um
     * contêiner da página do campus (nunca no :root): assim o cabeçalho e o anel de foco continuam com a
     * identidade padrão, e dentro do contêiner brand só é usado como fundo, com brand-ink por cima.
     * Sem identidade cadastrada (ou inválida), devolve '' e vale o padrão do sistema.
     *
     * @param array<string, mixed> $campus
     */
    function campus_estilo(array $campus): string
    {
        $cor   = (string) ($campus['cor_primaria'] ?? '');
        $texto = (string) ($campus['cor_texto'] ?? '');

        $textoValido = in_array($texto, [Contraste::TEXTO_CLARO, Contraste::TEXTO_ESCURO], true);

        if (! Contraste::corValida($cor) || ! $textoValido) {
            return '';
        }

        return '--color-brand: ' . strtoupper($cor) . '; --color-brand-ink: ' . Contraste::corDoTexto($texto) . ';';
    }
}

if (! function_exists('campus_rotulo')) {
    /**
     * Rótulo curto do campus para a navegação (breadcrumb): a abreviação; sem ela, o nome.
     *
     * @param array<string, mixed> $campus
     */
    function campus_rotulo(array $campus): string
    {
        $abreviacao = trim((string) ($campus['abreviacao'] ?? ''));

        return $abreviacao !== '' ? $abreviacao : (string) ($campus['nome'] ?? '');
    }
}

if (! function_exists('arquivo_url')) {
    /** URL pública de um arquivo gravado por App\Libraries\Arquivos (logo, foto, mapa). */
    function arquivo_url(string $caminho): string
    {
        return base_url('uploads/' . $caminho);
    }
}

if (! function_exists('opcoes_traduzidas')) {
    /**
     * Rótulos de um ENUM do banco a partir do idioma: valor => lang("<prefixo><valor>").
     *
     * @param list<string> $valores
     *
     * @return array<string, string>
     */
    function opcoes_traduzidas(string $prefixo, array $valores): array
    {
        $opcoes = [];

        foreach ($valores as $valor) {
            $opcoes[$valor] = lang($prefixo . $valor);
        }

        return $opcoes;
    }
}

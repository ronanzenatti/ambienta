<?php

namespace App\Libraries;

/**
 * Contraste WCAG 2.1 (luminância relativa) e a validação da identidade visual de um campus.
 *
 * Só brand e brand-ink mudam por campus (README do Design System, "Identidade por campus"): o texto
 * "claro" é o brand-ink padrão e o "escuro" é ink. O par escolhido precisa de 4.5:1 (texto normal, AA).
 * A mesma conta roda no navegador (resources/js/app.js) só como aviso; a validação que vale é esta.
 */
class Contraste
{
    public const MINIMO_TEXTO = 4.5;

    public const TEXTO_CLARO  = 'claro';
    public const TEXTO_ESCURO = 'escuro';

    public static function corValida(string $cor): bool
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $cor) === 1;
    }

    /** Cor de texto (hex) que cada opção de campus.cor_texto representa, vinda dos tokens. */
    public static function corDoTexto(string $texto): string
    {
        return match ($texto) {
            self::TEXTO_CLARO  => TokensDesign::cor('brand-ink'),
            self::TEXTO_ESCURO => TokensDesign::cor('ink'),
            default            => throw new \InvalidArgumentException("Opção de texto desconhecida: {$texto}"),
        };
    }

    public static function luminancia(string $cor): float
    {
        if (! self::corValida($cor)) {
            throw new \InvalidArgumentException("Cor inválida: {$cor}");
        }

        $canais = array_map(
            static function (string $par): float {
                $c = hexdec($par) / 255;

                return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            },
            str_split(substr($cor, 1), 2)
        );

        return 0.2126 * $canais[0] + 0.7152 * $canais[1] + 0.0722 * $canais[2];
    }

    public static function razao(string $corA, string $corB): float
    {
        $a = self::luminancia($corA);
        $b = self::luminancia($corB);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /**
     * Avalia a identidade de campus. Devolve null quando o par atende 4.5:1, ou o motivo:
     * - ['motivo' => 'outra', 'razao' => x, 'sugerida' => 'claro'|'escuro', 'razaoSugerida' => y]
     * - ['motivo' => 'nenhuma', 'claro' => x, 'escuro' => y]
     *
     * @return array<string, float|string>|null
     */
    public static function avaliarIdentidade(string $corPrimaria, string $texto): ?array
    {
        $razoes = [
            self::TEXTO_CLARO  => self::razao($corPrimaria, self::corDoTexto(self::TEXTO_CLARO)),
            self::TEXTO_ESCURO => self::razao($corPrimaria, self::corDoTexto(self::TEXTO_ESCURO)),
        ];

        if ($razoes[$texto] >= self::MINIMO_TEXTO) {
            return null;
        }

        $outra = $texto === self::TEXTO_CLARO ? self::TEXTO_ESCURO : self::TEXTO_CLARO;

        if ($razoes[$outra] >= self::MINIMO_TEXTO) {
            return [
                'motivo'        => 'outra',
                'razao'         => $razoes[$texto],
                'sugerida'      => $outra,
                'razaoSugerida' => $razoes[$outra],
            ];
        }

        return ['motivo' => 'nenhuma', 'claro' => $razoes[self::TEXTO_CLARO], 'escuro' => $razoes[self::TEXTO_ESCURO]];
    }

    /** Razão para exibir: truncada em 2 casas, para nunca mostrar "4.50" quando é 4.497. */
    public static function formatar(float $razao, string $separadorDecimal = ','): string
    {
        return number_format(floor($razao * 100) / 100, 2, $separadorDecimal, '');
    }
}

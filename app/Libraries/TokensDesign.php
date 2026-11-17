<?php

namespace App\Libraries;

/**
 * Leitura dos tokens do Design System (design/design-system/tokens.json), a fonte única dos valores.
 * O PHP só precisa deles para cálculos (contraste da identidade de campus); nunca copie um valor à mão.
 */
class TokensDesign
{
    /** @var array<string, string>|null */
    private static ?array $cores = null;

    public static function arquivo(): string
    {
        return ROOTPATH . 'design/design-system/tokens.json';
    }

    /** Valor hexadecimal de um token de cor (referências como "{brand}" são resolvidas). */
    public static function cor(string $nome): string
    {
        $cores = self::cores();
        $valor = $cores[$nome] ?? throw new \InvalidArgumentException("Token de cor desconhecido: {$nome}");

        // Referência a outro token, ex.: focus-ring = {brand}.
        while (preg_match('/^\{([a-z0-9-]+)\}$/', $valor, $ref) === 1) {
            $valor = $cores[$ref[1]] ?? throw new \InvalidArgumentException("Token de cor desconhecido: {$ref[1]}");
        }

        return strtoupper($valor);
    }

    /**
     * @return array<string, string>
     */
    private static function cores(): array
    {
        if (self::$cores !== null) {
            return self::$cores;
        }

        $json = json_decode((string) file_get_contents(self::arquivo()), true, 512, JSON_THROW_ON_ERROR);
        $cores = [];

        foreach ($json['color']['tokens'] as $token) {
            $cores[(string) $token['name']] = (string) $token['value']['default'];
        }

        return self::$cores = $cores;
    }
}

<?php

namespace App\Libraries\Notificacoes;

/**
 * Texto de uma notificação no idioma de quem lê (tela ou e-mail), montado do tipo + parâmetros gravados:
 * Notificacoes.<chave>_title e Notificacoes.<chave>_text, com os parâmetros nomeados ({item}, {autor}...).
 *
 * Parâmetros técnicos são traduzidos aqui (status, tipo de bloqueio, dia da semana) e datas do banco saem no
 * formato do idioma. Conteúdo digitado pelo usuário (títulos, motivos, nomes) vai como foi digitado.
 */
final class Mensagem
{
    /** Parâmetro com valor técnico => prefixo da chave de idioma que o traduz. */
    private const TRADUZIDOS = [
        'status'        => 'Chamados.status_',
        'tipo_bloqueio' => 'Agendamento.blockType_',
        'dia'           => 'Agendamento.weekday_',
    ];

    /** Destino de cada referência (o id entra no fim quando o registro tem página própria). */
    private const DESTINOS = [
        'movimentacao_item'    => 'patrimonio/movimentacoes/',
        'descarte'             => 'patrimonio/descartes/',
        'chamado'              => 'chamados/',
        'conflito_agendamento' => 'agendamento/conflitos',
        'reserva_pontual'      => 'agendamento/reservas/',
        'reserva_item_extra'   => 'agendamento/reservas',
        'bloqueio_ambiente'    => 'agendamento/bloqueios',
        'alocacao_semestral'   => 'agendamento/alocacoes/',
    ];

    /** @param array<string, mixed> $parametros */
    public static function titulo(string $chave, array $parametros = [], ?string $idioma = null): string
    {
        return self::linha($chave . '_title', $parametros, $idioma);
    }

    /** @param array<string, mixed> $parametros */
    public static function texto(string $chave, array $parametros = [], ?string $idioma = null): string
    {
        return self::linha($chave . '_text', $parametros, $idioma);
    }

    /** Página do registro que originou a notificação (null = sem referência). */
    public static function url(?string $referenciaTipo, int|string|null $referenciaId): ?string
    {
        $destino = self::DESTINOS[$referenciaTipo ?? ''] ?? null;

        if ($destino === null) {
            return null;
        }

        return site_url(str_ends_with($destino, '/') ? $destino . (int) $referenciaId : $destino);
    }

    /**
     * Parâmetros gravados (coluna JSON) como array.
     *
     * @return array<string, mixed>
     */
    public static function parametros(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $valor = json_decode($json, true);

        return is_array($valor) ? $valor : [];
    }

    /** @param array<string, mixed> $parametros */
    private static function linha(string $chave, array $parametros, ?string $idioma): string
    {
        $texto = lang('Notificacoes.' . $chave, self::formatar($parametros, $idioma), $idioma);

        return is_string($texto) ? $texto : '';
    }

    /**
     * @param array<string, mixed> $parametros
     *
     * @return array<string, int|string>
     */
    private static function formatar(array $parametros, ?string $idioma): array
    {
        $formato   = lang('Institucional.dateFormat', [], $idioma);
        $formatado = [];

        foreach ($parametros as $nome => $valor) {
            if (is_int($valor)) {
                $formatado[$nome] = $valor;

                continue;
            }

            $valor = (string) $valor;

            if (isset(self::TRADUZIDOS[$nome])) {
                $valor = lang(self::TRADUZIDOS[$nome] . $valor, [], $idioma);
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $valor) === 1) {
                $valor = date($formato . ' H:i', (int) strtotime($valor));
            }

            $formatado[$nome] = is_string($valor) ? $valor : '';
        }

        return $formatado;
    }
}

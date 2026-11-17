<?php

namespace App\Libraries\Agendamento;

use DateTimeImmutable;

/**
 * Monta, para a consulta pública, a grade de uma semana (domingo a sábado) de um campus: os agendamentos
 * (aulas recorrentes do semestre e reservas pontuais) de cada ambiente em cada dia, já com os textos de cada
 * bloco e do modal de detalhes, e a "faixa" (linha) de cada um quando dois se sobrepõem no mesmo ambiente.
 *
 * Não consulta o banco: recebe as linhas de ConsultaPublicaModel. Segunda a sexta sempre existem; sábado e
 * domingo só entram em `dias` se houver o que mostrar.
 *
 * @phpstan-type Evento array{
 *     k: string, tipo: string, amb: int, dia: string, data: string, imin: int, fmin: int, faixa: int, faixas: int,
 *     l1: string, l2: string, pre: string, l3: string, d: array<string, string>
 * }
 * @phpstan-type Grade array{
 *     ambientes: list<array<string, mixed>>, eventos: array<string, Evento>,
 *     grade: array<int, array<string, list<string>>>,
 *     totais: array<string, int>, dias: list<string>, datas: array<string, string>
 * }
 */
final class GradeConsulta
{
    /** Início e fim da régua do dia (minutos desde 00:00): 07:00 e 23:00. */
    public const INICIO = 420;
    public const FIM    = 1380;

    public const DIAS = ['segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado', 'domingo'];

    public const OBRIGATORIOS = ['segunda', 'terca', 'quarta', 'quinta', 'sexta'];

    public const TURNOS = ['manha', 'tarde', 'noite'];

    /** Dia da semana pelo número do `w` do PHP (0 = domingo). */
    private const POR_NUMERO = ['domingo', 'segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado'];

    /** "07:40" ou "07:40:00" em minutos desde 00:00. */
    public static function minutos(string $hora): int
    {
        [$h, $m] = array_map('intval', explode(':', $hora) + [0, 0]);

        return $h * 60 + $m;
    }

    public static function hora(int $minutos): string
    {
        return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
    }

    /**
     * Faixa [início, fim) da régua de um turno, com os limites do campus. 'todos' é a régua inteira.
     *
     * @param array{manha: int, tarde: int, noite: int} $fins
     *
     * @return array{0: int, 1: int}
     */
    public static function faixaDoTurno(string $turno, array $fins): array
    {
        return match ($turno) {
            'manha' => [self::INICIO, $fins['manha']],
            'tarde' => [$fins['manha'], $fins['tarde']],
            'noite' => [$fins['tarde'], $fins['noite']],
            default => [self::INICIO, self::FIM],
        };
    }

    /**
     * Turno em que o horário começa (antes do fim da manhã, da tarde; senão noite).
     *
     * @param array{manha: int, tarde: int, noite: int} $fins
     */
    public static function turnoDe(int $minuto, array $fins): string
    {
        return match (true) {
            $minuto < $fins['manha'] => 'manha',
            $minuto < $fins['tarde'] => 'tarde',
            default                  => 'noite',
        };
    }

    /**
     * O evento toca a faixa [início, fim)?
     *
     * @param array{imin: int, fmin: int} $evento
     */
    public static function toca(array $evento, int $inicio, int $fim): bool
    {
        return $evento['imin'] < $fim && $evento['fmin'] > $inicio;
    }

    /**
     * @param list<array<string, mixed>> $ambientes todos os ambientes do campus
     * @param list<array<string, mixed>> $aulas
     * @param list<array<string, mixed>> $excecoes
     * @param list<array<string, mixed>> $reservas
     * @param array{manha: int, tarde: int, noite: int} $fins
     * @param bool $soComEventos só os ambientes com agendamento (há filtro ativo além do campus)
     *
     * @return Grade
     */
    public static function montar(
        DateTimeImmutable $domingo,
        array $ambientes,
        array $aulas,
        array $excecoes,
        array $reservas,
        array $fins,
        bool $soComEventos
    ): array {
        $porAmbiente = array_column($ambientes, null, 'id');
        $datas       = [];

        for ($i = 0; $i < 7; $i++) {
            $data                                             = $domingo->modify("+{$i} days");
            $datas[self::POR_NUMERO[(int) $data->format('w')]] = $data->format('Y-m-d');
        }

        $semExcecao = [];

        foreach ($excecoes as $e) {
            $semExcecao[$e['alocacao_semestral_id'] . '|' . $e['data_excecao']] = true;
        }

        $eventos = [];

        foreach ($aulas as $aula) {
            $dia  = (string) $aula['dia_semana'];
            $data = $datas[$dia];

            if (
                ! isset($porAmbiente[$aula['ambiente_id']])
                || $data < $aula['data_inicio'] || $data > $aula['data_fim']
                || isset($semExcecao[$aula['id'] . '|' . $data])
            ) {
                continue;
            }

            $evento = self::evento(
                'a' . $aula['id'] . '-' . $data,
                'aula',
                $aula,
                $porAmbiente[$aula['ambiente_id']],
                $dia,
                $data,
                self::minutos((string) $aula['hora_inicio']),
                self::minutos((string) $aula['hora_fim']),
                $fins
            );

            $eventos[$evento['k']] = $evento;
        }

        foreach ($reservas as $reserva) {
            if (! isset($porAmbiente[$reserva['ambiente_id']])) {
                continue;
            }

            // A reserva pode passar da meia-noite: vira um bloco por dia da semana que ela toca.
            foreach ($datas as $dia => $data) {
                $de  = max((string) $reserva['data_hora_inicio'], $data . ' 00:00:00');
                $ate = min(
                    (string) $reserva['data_hora_fim'],
                    (new DateTimeImmutable($data))->modify('+1 day')->format('Y-m-d 00:00:00')
                );

                if ($de >= $ate) {
                    continue;
                }

                $evento = self::evento(
                    'p' . $reserva['id'] . '-' . $data,
                    'pontual',
                    $reserva,
                    $porAmbiente[$reserva['ambiente_id']],
                    $dia,
                    $data,
                    self::minutos(substr($de, 11, 5)),
                    substr($ate, 0, 10) > $data ? 1440 : self::minutos(substr($ate, 11, 5)),
                    $fins
                );

                $eventos[$evento['k']] = $evento;
            }
        }

        $eventos = self::faixas($eventos);
        $grade   = [];
        $totais  = array_fill_keys(self::DIAS, 0);

        foreach ($eventos as $chave => $evento) {
            $grade[$evento['amb']][$evento['dia']][] = $chave;
            $totais[$evento['dia']]++;
        }

        foreach ($grade as &$porDia) {
            foreach ($porDia as &$chaves) {
                usort($chaves, static fn (string $x, string $y): int => [$eventos[$x]['imin'], $eventos[$x]['fmin'], $x]
                    <=> [$eventos[$y]['imin'], $eventos[$y]['fmin'], $y]);
            }
        }
        unset($porDia, $chaves);

        $lista = array_values(array_filter(
            $ambientes,
            static fn (array $a): bool => isset($grade[$a['id']]) || (! $soComEventos && (bool) $a['ativo'])
        ));
        usort($lista, static fn (array $x, array $y): int => strnatcasecmp((string) $x['predio'], (string) $y['predio'])
            ?: $x['predio_id'] <=> $y['predio_id']
            ?: strnatcasecmp((string) $x['numero'], (string) $y['numero']));

        $dias = array_values(array_filter(
            self::DIAS,
            static fn (string $dia): bool => in_array($dia, self::OBRIGATORIOS, true) || $totais[$dia] > 0
        ));

        return [
            'ambientes' => $lista,
            'eventos'   => $eventos,
            'grade'     => $grade,
            'totais'    => $totais,
            'dias'      => $dias,
            'datas'     => $datas,
        ];
    }

    /**
     * Agrupa os ambientes (já ordenados por prédio e número) em blocos, um por prédio, na mesma ordem.
     *
     * @param list<array<string, mixed>> $ambientes
     *
     * @return list<array{id: int, nome: string, ambientes: list<array<string, mixed>>}>
     */
    public static function porPredio(array $ambientes): array
    {
        $blocos = [];

        foreach ($ambientes as $ambiente) {
            $id = (int) $ambiente['predio_id'];

            $blocos[$id] ??= ['id' => $id, 'nome' => (string) $ambiente['predio'], 'ambientes' => []];
            $blocos[$id]['ambientes'][] = $ambiente;
        }

        return array_values($blocos);
    }

    /**
     * Um agendamento num dia: textos do bloco (3 linhas) e do modal. `$origem` é a linha da consulta.
     *
     * @param array<string, mixed>                      $origem
     * @param array<string, mixed>                      $ambiente
     * @param array{manha: int, tarde: int, noite: int} $fins
     *
     * @return Evento
     */
    private static function evento(
        string $chave,
        string $tipo,
        array $origem,
        array $ambiente,
        string $dia,
        string $data,
        int $inicio,
        int $fim,
        array $fins
    ): array {
        $horario    = lang('Consulta.timeRange', [self::hora($inicio), self::hora($fim)]);
        $curso      = (string) ($origem['curso'] ?? '');
        $disciplina = (string) ($origem['disciplina'] ?? '');
        $sigla      = (string) ($origem['curso_sigla'] ?? '');
        $titulo     = (string) ($origem['titulo'] ?? '');
        $pessoa     = $origem['pessoa'] === null ? '' : (string) $origem['pessoa'];

        $prefixo = $disciplina !== '' ? $sigla . ' - ' : '';
        $linha2  = $disciplina !== '' ? $prefixo . $disciplina : $titulo;
        $dataFmt = new DateTimeImmutable($data);

        $recorrencia = null;
        $tipoRotulo  = lang('Consulta.typeOneOff');

        if ($tipo === 'aula') {
            $tipoRotulo  = lang('Consulta.typeClass');
            $recorrencia = lang('Consulta.recurrenceClass', [
                lang('Consulta.weekday_' . $dia),
                (new DateTimeImmutable((string) $origem['data_inicio']))->format(lang('Institucional.dateFormat')),
                (new DateTimeImmutable((string) $origem['data_fim']))->format(lang('Institucional.dateFormat')),
            ]);
        } elseif ($origem['recorrencia_id'] !== null) {
            $repeticao   = lang('Consulta.recurrence_' . ($origem['recorrencia_tipo'] ?? 'semanal'));
            $tipoRotulo  = lang('Consulta.typeOneOffRecurring', [$repeticao]);
            $recorrencia = ucfirst($repeticao);
        }

        // Textos do modal de detalhes, por chave (a ordem e os rótulos vêm do store do navegador).
        $detalhes = ['tipo' => $tipoRotulo];

        if ($recorrencia !== null) {
            $detalhes['rec'] = $recorrencia;
        }

        $detalhes['data'] = lang('Consulta.dateWithWeekday', [
            lang('Consulta.weekday_' . $dia),
            $dataFmt->format(lang('Institucional.dateFormat')),
        ]);
        $detalhes['hora']  = $horario . ' (' . lang('Consulta.shift_' . self::turnoDe($inicio, $fins)) . ')';
        $detalhes['amb']   = $ambiente['numero'] . ' — ' . $ambiente['nome'];
        $detalhes['local'] = $ambiente['predio'] . ' · ' . $ambiente['campus'];

        if ($curso !== '') {
            $detalhes['curso'] = $curso . ' (' . $sigla . ')';
            $detalhes['disc']  = $disciplina . ' (' . $origem['disciplina_sigla'] . ')';
        }

        $detalhes[$tipo === 'aula' ? 'prof' : 'resp'] = $pessoa !== '' ? $pessoa : lang('Consulta.personHidden');

        if ($tipo === 'pontual') {
            $detalhes['titulo'] = $titulo;
        } else {
            $detalhes['sem'] = (string) $origem['semestre'];
        }

        return [
            'k'        => $chave,
            'tipo'     => $tipo,
            'amb'      => (int) $ambiente['id'],
            'dia'      => $dia,
            'data'     => $data,
            'imin'     => $inicio,
            'fmin'     => $fim,
            'faixa'    => 0,
            'faixas'   => 1,
            'l1'       => $horario,
            'l2'       => $linha2,
            'pre'      => $prefixo,
            'l3'      => $pessoa !== '' ? $pessoa : lang('Consulta.personHidden'),
            'd'        => $detalhes,
        ];
    }

    /**
     * Distribui em faixas (linhas) os agendamentos que se sobrepõem no mesmo ambiente e dia, para um não cobrir
     * o outro. Cada evento recebe `faixa` (0...) e `faixas` (quantas há naquele ambiente e dia).
     *
     * @param array<string, Evento> $eventos
     *
     * @return array<string, Evento>
     */
    private static function faixas(array $eventos): array
    {
        $grupos = [];

        foreach ($eventos as $chave => $evento) {
            $grupos[$evento['amb'] . '|' . $evento['data']][] = $chave;
        }

        foreach ($grupos as $chaves) {
            usort($chaves, static fn (string $x, string $y): int => [$eventos[$x]['imin'], $eventos[$x]['fmin'], $x]
                <=> [$eventos[$y]['imin'], $eventos[$y]['fmin'], $y]);

            $fins = [];

            foreach ($chaves as $chave) {
                $faixa = 0;

                while (isset($fins[$faixa]) && $fins[$faixa] > $eventos[$chave]['imin']) {
                    $faixa++;
                }

                $fins[$faixa]             = $eventos[$chave]['fmin'];
                $eventos[$chave]['faixa'] = $faixa;
            }

            foreach ($chaves as $chave) {
                $eventos[$chave]['faixas'] = count($fins);
            }
        }

        return $eventos;
    }
}

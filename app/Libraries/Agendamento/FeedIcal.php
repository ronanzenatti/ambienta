<?php

namespace App\Libraries\Agendamento;

use CodeIgniter\Database\BaseConnection;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Feed iCal (só leitura, sem sincronização de volta) de um ambiente ou de um usuário, numa janela de
 * 30 dias para trás a 180 para a frente. Cada aula da alocação semestral vira um evento por data (sem RRULE:
 * exceção e feriado somem da ocorrência sem depender de EXDATE nos clientes).
 *
 * Ambiente: feed público, com o mesmo recorte da agenda pública (primeiro nome do professor, reserva
 * confirmada como "Reservado", bloqueio só pelo tipo, sem semestre planejado). Usuário: as aulas em que é o
 * professor e as reservas que pediu ou de que é beneficiário (pendentes como TENTATIVE).
 *
 * Gerado aqui mesmo (RFC 5545 é texto simples: escape, dobra de linha em 75 octetos e CRLF), sem biblioteca:
 * a única mantida para PHP (eluceo/ical 2.x) emite deprecations no PHP 8.4. Horários em UTC ("Z"), o que
 * dispensa o bloco VTIMEZONE; o calendário do usuário converte para o fuso local.
 */
final class FeedIcal
{
    public const DIAS_ANTES  = 30;
    public const DIAS_DEPOIS = 180;

    private DateTimeZone $fuso;
    private DateTimeImmutable $inicio;
    private DateTimeImmutable $fim;

    public function __construct(private readonly BaseConnection $db, ?DateTimeImmutable $hoje = null)
    {
        $this->fuso   = new DateTimeZone(app_timezone());
        $hoje         = ($hoje ?? new DateTimeImmutable('today', $this->fuso))->setTime(0, 0);
        $this->inicio = $hoje->modify('-' . self::DIAS_ANTES . ' days');
        $this->fim    = $hoje->modify('+' . self::DIAS_DEPOIS . ' days');
    }

    public function doAmbiente(int $ambienteId, string $nome): string
    {
        $eventos = $this->aulas('a.ambiente_id = ?', $ambienteId, true);

        foreach ($this->reservas('r.ambiente_id = ? AND r.status = \'confirmada\'', [$ambienteId]) as $r) {
            $eventos[] = $this->evento(
                'reserva-' . $r['id_reserva_pontual'],
                lang('Agendamento.publicReserved'),
                '',
                (string) $r['local'],
                new DateTimeImmutable((string) $r['data_hora_inicio'], $this->fuso),
                new DateTimeImmutable((string) $r['data_hora_fim'], $this->fuso),
            );
        }

        foreach ($this->bloqueios($ambienteId) as $b) {
            $eventos[] = $this->evento(
                'bloqueio-' . $b['id_bloqueio_ambiente'],
                lang('Agendamento.blockType_' . $b['tipo']),
                '',
                (string) $b['local'],
                new DateTimeImmutable((string) $b['data_hora_inicio'], $this->fuso),
                new DateTimeImmutable((string) $b['data_hora_fim'], $this->fuso),
            );
        }

        foreach ($this->feriadosDoAmbiente($ambienteId) as $data => $descricao) {
            $dia       = new DateTimeImmutable($data, $this->fuso);
            $eventos[] = self::componente('VEVENT', [
                'UID'                => 'feriado-' . $ambienteId . '-' . $dia->format('Ymd') . '@ambienta',
                'DTSTAMP'            => $this->agora(),
                'SUMMARY'            => self::texto(lang('Agendamento.holidayLabel', [$descricao])),
                'DTSTART;VALUE=DATE' => $dia->format('Ymd'),
                'DTEND;VALUE=DATE'   => $dia->modify('+1 day')->format('Ymd'),
                'TRANSP'             => 'TRANSPARENT',
            ]);
        }

        return $this->calendario($eventos, $nome);
    }

    public function doUsuario(int $usuarioId, string $nome): string
    {
        $eventos = $this->aulas('a.professor_id = ?', $usuarioId, false);

        $reservas = $this->reservas(
            "r.status <> 'cancelada' AND (r.solicitante_id = ? OR r.beneficiario_id = ?)",
            [$usuarioId, $usuarioId]
        );

        foreach ($reservas as $r) {
            $eventos[] = $this->evento(
                'reserva-' . $r['id_reserva_pontual'],
                (string) $r['titulo'],
                lang('Agendamento.reservationStatus_' . $r['status']),
                (string) $r['local'],
                new DateTimeImmutable((string) $r['data_hora_inicio'], $this->fuso),
                new DateTimeImmutable((string) $r['data_hora_fim'], $this->fuso),
                $r['status'] === 'confirmada' ? 'CONFIRMED' : 'TENTATIVE',
            );
        }

        return $this->calendario($eventos, $nome);
    }

    /**
     * Uma ocorrência por data de cada alocação ativa, pulando as datas com exceção e os feriados do campus.
     *
     * @return list<string>
     */
    private function aulas(string $filtro, int $id, bool $publica): array
    {
        $professor = $publica ? Agenda::PRIMEIRO_NOME : 'u.nome';
        $semestre  = $publica ? " AND s.status <> 'planejado'" : '';

        $alocacoes = $this->db->query(
            "SELECT a.id_alocacao_semestral, a.dia_semana, a.hora_inicio, a.hora_fim, s.data_inicio, s.data_fim,
                    d.sigla, d.nome AS disciplina, c.nome AS curso, {$professor} AS professor, p.campus_id,
                    CONCAT(amb.numero, ' — ', amb.nome, ' · ', p.nome, ' · ', cp.nome) AS local
             FROM alocacoes_semestrais a
             JOIN semestres s   ON s.id_semestre = a.semestre_id AND s.deletado_em IS NULL{$semestre}
             JOIN disciplinas d ON d.id_disciplina = a.disciplina_id
             JOIN cursos c      ON c.id_curso = d.curso_id
             JOIN usuarios u    ON u.id_usuario = a.professor_id
             JOIN ambientes amb ON amb.id_ambiente = a.ambiente_id
             JOIN predios p     ON p.id_predio = amb.predio_id
             JOIN campus cp     ON cp.id_campus = p.campus_id
             WHERE {$filtro} AND a.deletado_em IS NULL AND a.status <> 'cancelada'
               AND s.data_inicio <= ? AND s.data_fim >= ?",
            [$id, $this->fim->format('Y-m-d'), $this->inicio->format('Y-m-d')]
        )->getResultArray();

        if ($alocacoes === []) {
            return [];
        }

        $excecoes = $this->excecoes(array_map('intval', array_column($alocacoes, 'id_alocacao_semestral')));
        $feriados = $this->feriados();
        $eventos  = [];

        foreach ($alocacoes as $a) {
            $alocacaoId = (int) $a['id_alocacao_semestral'];
            $de         = max($this->inicio, new DateTimeImmutable((string) $a['data_inicio'], $this->fuso));
            $ate        = min($this->fim, new DateTimeImmutable((string) $a['data_fim'], $this->fuso));
            $passo      = array_search($a['dia_semana'], Alocacoes::DIAS, true) + 1 - (int) $de->format('N');
            $data       = $de->modify('+' . (($passo + 7) % 7) . ' days');

            for (; $data <= $ate; $data = $data->add(new DateInterval('P7D'))) {
                $dia = $data->format('Y-m-d');

                if (
                    isset($excecoes[$alocacaoId][$dia]) || isset($feriados[0][$dia])
                    || isset($feriados[(int) $a['campus_id']][$dia])
                ) {
                    continue;
                }

                $eventos[] = $this->evento(
                    'alocacao-' . $alocacaoId . '-' . $data->format('Ymd'),
                    $a['sigla'] . ' — ' . $a['disciplina'],
                    implode(' · ', array_filter([(string) $a['curso'], (string) $a['professor']])),
                    (string) $a['local'],
                    new DateTimeImmutable($dia . ' ' . $a['hora_inicio'], $this->fuso),
                    new DateTimeImmutable($dia . ' ' . $a['hora_fim'], $this->fuso),
                );
            }
        }

        return $eventos;
    }

    /**
     * @param list<int> $alocacoes
     *
     * @return array<int, array<string, true>> alocação => datas com exceção
     */
    private function excecoes(array $alocacoes): array
    {
        $linhas = $this->db->query(
            'SELECT alocacao_semestral_id, data_excecao FROM alocacoes_semestrais_excecoes
             WHERE deletado_em IS NULL AND alocacao_semestral_id IN (' . implode(', ', $alocacoes) . ')
               AND data_excecao BETWEEN ? AND ?',
            [$this->inicio->format('Y-m-d'), $this->fim->format('Y-m-d')]
        )->getResultArray();

        $excecoes = [];

        foreach ($linhas as $linha) {
            $excecoes[(int) $linha['alocacao_semestral_id']][(string) $linha['data_excecao']] = true;
        }

        return $excecoes;
    }

    /** @return array<int, array<string, string>> campus (0 = todos) => data => descrição */
    private function feriados(): array
    {
        $linhas = $this->db->query(
            'SELECT campus_id, data_feriado, descricao FROM feriados
             WHERE deletado_em IS NULL AND data_feriado BETWEEN ? AND ?',
            [$this->inicio->format('Y-m-d'), $this->fim->format('Y-m-d')]
        )->getResultArray();

        $feriados = [];

        foreach ($linhas as $linha) {
            $feriados[(int) $linha['campus_id']][(string) $linha['data_feriado']] = (string) $linha['descricao'];
        }

        return $feriados;
    }

    /** @return array<string, string> data => descrição (nacionais e do campus do ambiente) */
    private function feriadosDoAmbiente(int $ambienteId): array
    {
        $campusId = (int) ($this->db->query(
            'SELECT p.campus_id FROM ambientes a JOIN predios p ON p.id_predio = a.predio_id WHERE a.id_ambiente = ?',
            [$ambienteId]
        )->getRow()->campus_id ?? 0);
        $feriados = $this->feriados();

        return ($feriados[$campusId] ?? []) + ($feriados[0] ?? []);
    }

    /**
     * @param list<int> $binds
     *
     * @return list<array<string, mixed>>
     */
    private function reservas(string $filtro, array $binds): array
    {
        return $this->db->query(
            "SELECT r.id_reserva_pontual, r.titulo, r.status, r.data_hora_inicio, r.data_hora_fim,
                    CONCAT(amb.numero, ' — ', amb.nome, ' · ', p.nome, ' · ', cp.nome) AS local
             FROM reservas_pontuais r
             JOIN ambientes amb ON amb.id_ambiente = r.ambiente_id
             JOIN predios p     ON p.id_predio = amb.predio_id
             JOIN campus cp     ON cp.id_campus = p.campus_id
             WHERE {$filtro} AND r.deletado_em IS NULL AND r.data_hora_inicio < ? AND r.data_hora_fim > ?",
            [...$binds, $this->fim->format('Y-m-d 00:00:00'), $this->inicio->format('Y-m-d 00:00:00')]
        )->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    private function bloqueios(int $ambienteId): array
    {
        return $this->db->query(
            "SELECT b.id_bloqueio_ambiente, b.tipo, b.data_hora_inicio, b.data_hora_fim,
                    CONCAT(amb.numero, ' — ', amb.nome, ' · ', p.nome, ' · ', cp.nome) AS local
             FROM bloqueios_ambientes b
             JOIN ambientes amb ON amb.id_ambiente = b.ambiente_id
             JOIN predios p     ON p.id_predio = amb.predio_id
             JOIN campus cp     ON cp.id_campus = p.campus_id
             WHERE b.ambiente_id = ? AND b.deletado_em IS NULL AND b.data_hora_inicio < ? AND b.data_hora_fim > ?",
            [$ambienteId, $this->fim->format('Y-m-d 00:00:00'), $this->inicio->format('Y-m-d 00:00:00')]
        )->getResultArray();
    }

    private function evento(
        string $uid,
        string $resumo,
        string $descricao,
        string $local,
        DateTimeImmutable $inicio,
        DateTimeImmutable $fim,
        ?string $status = null,
    ): string {
        return self::componente('VEVENT', array_filter([
            'UID'         => $uid . '@ambienta',
            'DTSTAMP'     => $this->agora(),
            'SUMMARY'     => self::texto($resumo),
            'DESCRIPTION' => $descricao === '' ? null : self::texto($descricao),
            'LOCATION'    => self::texto($local),
            'DTSTART'     => self::utc($inicio),
            'DTEND'       => self::utc($fim),
            'STATUS'      => $status,
        ], static fn (?string $valor): bool => $valor !== null));
    }


    /** @param list<string> $eventos componentes VEVENT já serializados */
    private function calendario(array $eventos, string $nome): string
    {
        $fim       = "END:VCALENDAR\r\n";
        $idioma    = strtoupper(service('request')->getLocale());
        $cabecalho = self::componente('VCALENDAR', [
            'VERSION'                         => '2.0',
            'PRODID'                          => '-//Fatec Jahu//Ambienta//' . $idioma,
            'CALSCALE'                        => 'GREGORIAN',
            'METHOD'                          => 'PUBLISH',
            // Nome que Google Agenda, Outlook e Apple mostram para a assinatura, e a frequência de atualização.
            'X-WR-CALNAME'                    => self::texto($nome),
            'REFRESH-INTERVAL;VALUE=DURATION' => 'PT1H',
            'X-PUBLISHED-TTL'                 => 'PT1H',
        ]);

        return substr($cabecalho, 0, -strlen($fim)) . implode('', $eventos) . $fim;
    }

    /** @param array<string, string> $propriedades */
    private static function componente(string $nome, array $propriedades): string
    {
        $linhas = ["BEGIN:{$nome}"];

        foreach ($propriedades as $chave => $valor) {
            $linhas[] = self::dobrar($chave . ':' . $valor);
        }

        $linhas[] = "END:{$nome}";

        return implode("\r\n", $linhas) . "\r\n";
    }

    /** Valor TEXT (RFC 5545 §3.3.11): barra invertida, ponto e vírgula, vírgula e quebra de linha escapados. */
    private static function texto(string $valor): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $valor);
    }

    /** Linha com mais de 75 octetos continua na seguinte começando com espaço (§3.1), sem partir caractere UTF-8. */
    private static function dobrar(string $linha): string
    {
        $partes = [];

        while (strlen($linha) > 75) {
            $corte    = mb_strcut($linha, 0, $partes === [] ? 75 : 74, 'UTF-8');
            $partes[] = $corte;
            $linha    = substr($linha, strlen($corte));
        }

        $partes[] = $linha;

        return implode("\r\n ", $partes);
    }

    private static function utc(DateTimeImmutable $data): string
    {
        return $data->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    private function agora(): string
    {
        return self::utc(new DateTimeImmutable('now', $this->fuso));
    }
}

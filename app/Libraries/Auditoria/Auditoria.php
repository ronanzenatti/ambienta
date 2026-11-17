<?php

namespace App\Libraries\Auditoria;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\IncomingRequest;
use Config\Notificacoes;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Log de auditoria (REQUISITOS §13): o registro inteiro antes e depois, em JSON, mais a lista de campos
 * alterados, para reconstruir o estado de qualquer registro em qualquer momento.
 *
 * Uso nas operações das áreas críticas, dentro da mesma transação da alteração:
 *   $antes = $auditoria->ler('descartes', $id);   // null numa inserção
 *   ... grava ...
 *   $auditoria->registrarDepois('descartes', $id, $antes);
 *
 * Os cadastros feitos por Model usam o trait Auditavel, que faz o mesmo nos eventos do Model.
 * Quem fez a ação vem da sessão (nulo em rotinas automáticas); o IP, da requisição.
 */
final class Auditoria
{
    public const ACOES = ['insercao', 'atualizacao', 'exclusao_logica', 'restauracao', 'anonimizacao'];

    /** Tabelas auditadas (áreas críticas) => chave primária. */
    public const TABELAS = [
        'campus'                    => 'id_campus',
        'configuracoes_campus'      => 'id_configuracao_campus',
        'predios'                   => 'id_predio',
        'ambientes'                 => 'id_ambiente',
        'usuarios'                  => 'id_usuario',
        'usuarios_perfis'           => 'id_usuario_perfil',
        'perfis'                    => 'id_perfil',
        'perfis_permissoes'         => 'id_perfil_permissao',
        'almoxarifados'             => 'id_almoxarifado',
        'itens'                     => 'id_item',
        'movimentacoes_itens'       => 'id_movimentacao_item',
        'descartes'                 => 'id_descarte',
        'chamados'                  => 'id_chamado',
        'alocacoes_semestrais'      => 'id_alocacao_semestral',
        'reservas_pontuais'         => 'id_reserva_pontual',
        'conflitos_agendamento'     => 'id_conflito_agendamento',
        'reservas_itens_extras'     => 'id_reserva_item_extra',
        'reservas_softwares_extras' => 'id_reserva_software_extra',
        'bloqueios_ambientes'       => 'id_bloqueio_ambiente',
    ];

    /** Segredos: a mudança aparece em campos_alterados, mas o valor nunca vai para o log. */
    private const OCULTOS = ['senha_hash', 'token_ical', 'token_ical_unico'];

    /** Mudança só nestes campos não gera registro. */
    private const IGNORADOS = ['alterado_em', 'ultimo_acesso_em'];

    private const AMBIENTE = ' JOIN ambientes a ON a.id_ambiente = %s JOIN predios p ON p.id_predio = a.predio_id';

    private const ALMOXARIFADO = ' JOIN almoxarifados x ON x.id_almoxarifado = %s';

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** @return array<string, mixed>|null a linha inteira, inclusive soft-deletada */
    public function ler(string $tabela, int $id): ?array
    {
        return $this->db->table($tabela)->where(self::chave($tabela), $id)->get()->getRowArray();
    }

    /** Lê o estado atual do registro e grava o log contra o estado anterior ($antes null = inserção). */
    public function registrarDepois(string $tabela, int $id, ?array $antes): void
    {
        $depois = $this->ler($tabela, $id);

        if ($depois !== null) {
            $this->registrar($tabela, $id, $antes, $depois);
        }
    }

    /**
     * @param array<string, mixed>|null $antes
     * @param array<string, mixed>      $depois
     */
    public function registrar(string $tabela, int $id, ?array $antes, array $depois): void
    {
        self::chave($tabela);

        $acao    = self::acao($tabela, $antes, $depois);
        $campos  = $antes === null ? [] : self::camposAlterados($antes, $depois);
        $semNada = array_diff($campos, self::IGNORADOS) === [];

        if ($antes !== null && $semNada) {
            return;
        }

        $this->db->table('logs_auditoria')->insert([
            'tabela_afetada'    => $tabela,
            'registro_id'       => $id,
            'acao'              => $acao,
            'campos_alterados'  => in_array($acao, ['insercao', 'exclusao_logica'], true) ? null : self::json($campos),
            'dados_anteriores'  => $antes === null ? null : self::json(self::ocultar($antes)),
            'dados_novos'       => self::json(self::ocultar($depois)),
            'usuario_id'        => $this->ator(),
            'ip_origem'         => $this->ip(),
        ]);
    }

    /**
     * @param array<string, mixed>|null $antes
     * @param array<string, mixed>      $depois
     */
    public static function acao(string $tabela, ?array $antes, array $depois): string
    {
        if ($antes === null) {
            return 'insercao';
        }

        if ($tabela === 'usuarios' && $antes['anonimizado_em'] === null && $depois['anonimizado_em'] !== null) {
            return 'anonimizacao';
        }

        $estavaExcluido = ($antes['deletado_em'] ?? null) !== null;
        $estaExcluido   = ($depois['deletado_em'] ?? null) !== null;

        return match (true) {
            ! $estavaExcluido && $estaExcluido => 'exclusao_logica',
            $estavaExcluido && ! $estaExcluido => 'restauracao',
            default                            => 'atualizacao',
        };
    }

    /**
     * @param array<string, mixed> $antes
     * @param array<string, mixed> $depois
     *
     * @return list<string>
     */
    public static function camposAlterados(array $antes, array $depois): array
    {
        $campos = [];

        foreach ($depois as $campo => $valor) {
            if (
                ! array_key_exists($campo, $antes) || (string) $antes[$campo] !== (string) $valor
                || ($antes[$campo] === null) !== ($valor === null)
            ) {
                $campos[] = (string) $campo;
            }
        }

        return $campos;
    }

    /**
     * Onde o registro auditado está (para limitar a consulta ao escopo de quem consulta): por tabela, os
     * caminhos até um prédio e/ou campus. Tabela fora daqui (usuários, perfis) só aparece em escopo global.
     *
     * @return array<string, list<array{0: string, 1: string, 2: ?string, 3: string}>> [FROM, id, prédio, campus]
     */
    public static function locais(): array
    {
        $amb   = static fn (string $tabela, string $coluna): array => [
            $tabela . ' t' . sprintf(self::AMBIENTE, $coluna), 'p.id_predio', 'p.campus_id',
        ];
        $almox = static fn (string $tabela, string $coluna): array => [
            $tabela . ' t' . sprintf(self::ALMOXARIFADO, $coluna), null, 'x.campus_id',
        ];
        $item  = ' JOIN itens i ON i.id_item = t.item_id';
        $res   = ' JOIN reservas_pontuais r ON r.id_reserva_pontual = t.reserva_pontual_id';

        $mov = 'movimentacoes_itens';

        $caminhos = [
            'campus'               => [['campus t', null, 't.id_campus']],
            'configuracoes_campus' => [['configuracoes_campus t', null, 't.campus_id']],
            'predios'              => [['predios t', 't.id_predio', 't.campus_id']],
            'ambientes'            => [
                ['ambientes t JOIN predios p ON p.id_predio = t.predio_id', 'p.id_predio', 'p.campus_id'],
            ],
            'almoxarifados'        => [['almoxarifados t', null, 't.campus_id']],
            'itens'                => [
                $amb('itens', 't.ambiente_id'),
                $almox('itens', 't.almoxarifado_id'),
            ],
            $mov                   => [
                $amb($mov, 't.origem_ambiente_id'), $almox($mov, 't.origem_almoxarifado_id'),
                $amb($mov, 't.destino_ambiente_id'), $almox($mov, 't.destino_almoxarifado_id'),
            ],
            'descartes'            => [
                ['descartes t' . $item . sprintf(self::AMBIENTE, 'i.ambiente_id'), 'p.id_predio', 'p.campus_id'],
                ['descartes t' . $item . sprintf(self::ALMOXARIFADO, 'i.almoxarifado_id'), null, 'x.campus_id'],
            ],
            'chamados'             => [$amb('chamados', 't.ambiente_id')],
            'alocacoes_semestrais' => [$amb('alocacoes_semestrais', 't.ambiente_id')],
            'reservas_pontuais'    => [$amb('reservas_pontuais', 't.ambiente_id')],
            'bloqueios_ambientes'  => [$amb('bloqueios_ambientes', 't.ambiente_id')],
        ];

        foreach (['conflitos_agendamento', 'reservas_itens_extras', 'reservas_softwares_extras'] as $tabela) {
            $from               = $tabela . ' t' . $res . sprintf(self::AMBIENTE, 'r.ambiente_id');
            $caminhos[$tabela] = [[$from, 'p.id_predio', 'p.campus_id']];
        }

        $resultado = [];

        foreach ($caminhos as $tabela => $lista) {
            foreach ($lista as [$from, $predio, $campus]) {
                $resultado[$tabela][] = [$from, 't.' . self::chave($tabela), $predio, $campus];
            }
        }

        return $resultado;
    }

    /**
     * Condição SQL do escopo sobre logs_auditoria (alias $alias); null = sem restrição (escopo global).
     *
     * @param array{global: bool, campus: list<int>, predio: list<int>} $escopo
     */
    public static function condicaoEscopo(array $escopo, string $alias = 'la'): ?string
    {
        if ($escopo['global']) {
            return null;
        }

        $campus = implode(', ', array_map('intval', $escopo['campus']));
        $predio = implode(', ', array_map('intval', $escopo['predio']));
        $partes = [];

        foreach (self::locais() as $tabela => $caminhos) {
            foreach ($caminhos as [$from, $id, $colPredio, $colCampus]) {
                $condicoes = [];

                if ($campus !== '') {
                    $condicoes[] = "{$colCampus} IN ({$campus})";
                }

                if ($predio !== '' && $colPredio !== null) {
                    $condicoes[] = "{$colPredio} IN ({$predio})";
                }

                if ($condicoes !== []) {
                    $partes[] = "({$alias}.tabela_afetada = '{$tabela}' AND EXISTS (SELECT 1 FROM {$from}"
                        . " WHERE {$id} = {$alias}.registro_id AND (" . implode(' OR ', $condicoes) . ')))';
                }
            }
        }

        return $partes === [] ? '1 = 0' : '(' . implode(' OR ', $partes) . ')';
    }

    /**
     * Data mais recente que o expurgo aceita: registros criados antes dela já cumpriram a retenção.
     */
    public static function limiteExpurgo(?DateTimeImmutable $hoje = null): DateTimeImmutable
    {
        $anos = config(Notificacoes::class)->retencaoAuditoriaAnos;

        return ($hoje ?? new DateTimeImmutable('today'))->setTime(0, 0)->modify("-{$anos} years");
    }

    /**
     * Expurgo definitivo (DELETE físico, exclusivo do superusuário) dos registros criados antes de $ate.
     *
     * @return int quantos saíram
     */
    public function expurgar(DateTimeImmutable $ate): int
    {
        if ($ate > self::limiteExpurgo()) {
            throw new InvalidArgumentException('Data do expurgo dentro do período de retenção.');
        }

        $this->db->table('logs_auditoria')->where('criado_em <', $ate->format('Y-m-d 00:00:00'))->delete();

        return $this->db->affectedRows();
    }

    private static function chave(string $tabela): string
    {
        return self::TABELAS[$tabela] ?? throw new InvalidArgumentException("Tabela não auditada: {$tabela}");
    }

    /**
     * @param array<string, mixed> $linha
     *
     * @return array<string, mixed>
     */
    private static function ocultar(array $linha): array
    {
        foreach (self::OCULTOS as $campo) {
            if (($linha[$campo] ?? null) !== null) {
                $linha[$campo] = '***';
            }
        }

        return $linha;
    }

    private static function json(mixed $valor): string
    {
        return json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function ator(): ?int
    {
        return service('autenticacao')->id();
    }

    private function ip(): ?string
    {
        $request = service('request');

        return $request instanceof IncomingRequest ? $request->getIPAddress() : null;
    }
}

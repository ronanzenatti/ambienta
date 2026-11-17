<?php

namespace App\Libraries\Relatorios;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use Config\Relatorios as ConfigRelatorios;
use DateTimeImmutable;

/**
 * Filtros de um relatório (campus, prédio, período e agrupamento), lidos da URL e validados contra o escopo
 * de relatorios.acesso de quem consulta: um campus ou prédio fora do escopo é ignorado, nunca obedecido.
 *
 * O escopo vale sempre (superperfil em escopo global não tem restrição); os filtros só estreitam.
 */
final class Filtros
{
    public const AGRUPAMENTOS = ['campus', 'predio', 'ambiente'];

    /** @var array<int, string>|null */
    private ?array $campi = null;

    /** @var array<int, array{rotulo: string, campus_id: int}>|null */
    private ?array $predios = null;

    /**
     * @param array{global: bool, campus: list<int>, predio: list<int>} $escopo
     */
    private function __construct(
        private readonly BaseConnection $db,
        public readonly array $escopo,
        public readonly ?int $campusId,
        public readonly ?int $predioId,
        public readonly DateTimeImmutable $de,
        public readonly DateTimeImmutable $ate,
        public readonly string $agrupamento,
    ) {
    }

    /**
     * @param array{global: bool, campus: list<int>, predio: list<int>} $escopo
     */
    public static function daRequisicao(RequestInterface $request, array $escopo, BaseConnection $db): self
    {
        $ler = static fn (string $campo): string => $request instanceof IncomingRequest
            ? trim((string) $request->getGet($campo))
            : '';

        [$dePadrao, $atePadrao] = self::periodoPadrao($db);

        $de  = self::data($ler('de')) ?? $dePadrao;
        $ate = self::data($ler('ate')) ?? $atePadrao;

        if ($ate < $de) {
            [$de, $ate] = [$ate, $de];
        }

        $maximo = config(ConfigRelatorios::class)->periodoMaximoDias;
        if ((int) $de->diff($ate)->days > $maximo) {
            $ate = $de->modify("+{$maximo} days");
        }

        $agrupamento = in_array($ler('agrupar'), self::AGRUPAMENTOS, true) ? $ler('agrupar') : 'campus';
        $base        = new self($db, $escopo, null, null, $de, $ate, $agrupamento);

        $campus   = ctype_digit($ler('campus')) ? (int) $ler('campus') : 0;
        $campusId = isset($base->campi()[$campus]) ? $campus : null;
        $predioId = null;

        if (ctype_digit($ler('predio')) && isset($base->predios()[(int) $ler('predio')])) {
            $predio = $base->predios()[(int) $ler('predio')];

            if ($campusId === null || $predio['campus_id'] === $campusId) {
                $predioId = (int) $ler('predio');
            }
        }

        $filtros          = new self($db, $escopo, $campusId, $predioId, $de, $ate, $agrupamento);
        $filtros->campi   = $base->campi;
        $filtros->predios = $base->predios;

        return $filtros;
    }

    /**
     * Semestre vigente (hoje entre o início e o fim); sem ele, o mês atual.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    public static function periodoPadrao(BaseConnection $db): array
    {
        $hoje     = new DateTimeImmutable('today');
        $semestre = $db->table('semestres')
            ->select('data_inicio, data_fim')
            ->where('deletado_em', null)
            ->where('data_inicio <=', $hoje->format('Y-m-d'))
            ->where('data_fim >=', $hoje->format('Y-m-d'))
            ->orderBy('data_inicio', 'DESC')
            ->get(1)
            ->getRowArray();

        if ($semestre !== null) {
            return [new DateTimeImmutable($semestre['data_inicio']), new DateTimeImmutable($semestre['data_fim'])];
        }

        return [$hoje->modify('first day of this month'), $hoje->modify('last day of this month')];
    }

    /**
     * Campi que o escopo alcança (inclusive o campus de um prédio do escopo), para a lista de escolha.
     *
     * @return array<int, string>
     */
    public function campi(): array
    {
        if ($this->campi !== null) {
            return $this->campi;
        }

        $consulta = $this->db->table('campus c')
            ->select("c.id_campus, COALESCE(NULLIF(TRIM(c.abreviacao), ''), c.nome) AS nome", false)
            ->where('c.deletado_em', null)
            ->orderBy('c.nome');

        if (! $this->escopo['global']) {
            $predios = $this->escopo['predio'] === [] ? [0] : $this->escopo['predio'];

            $consulta->groupStart()
                ->whereIn('c.id_campus', $this->escopo['campus'] === [] ? [0] : $this->escopo['campus'])
                ->orWhere(
                    'c.id_campus IN (SELECT campus_id FROM predios WHERE id_predio IN ('
                    . implode(', ', array_map('intval', $predios)) . '))',
                    null,
                    false
                )
                ->groupEnd();
        }

        $this->campi = [];
        foreach ($consulta->get()->getResultArray() as $linha) {
            $this->campi[(int) $linha['id_campus']] = (string) $linha['nome'];
        }

        return $this->campi;
    }

    /**
     * Prédios que o escopo alcança ("Campus › Prédio").
     *
     * @return array<int, array{rotulo: string, campus_id: int}>
     */
    public function predios(): array
    {
        if ($this->predios !== null) {
            return $this->predios;
        }

        $consulta = $this->db->table('predios p')
            ->select('p.id_predio, p.nome, p.campus_id')
            ->select("COALESCE(NULLIF(TRIM(c.abreviacao), ''), c.nome) AS campus", false)
            ->join('campus c', 'c.id_campus = p.campus_id AND c.deletado_em IS NULL')
            ->where('p.deletado_em', null)
            ->orderBy('c.nome')
            ->orderBy('p.nome');

        if (! $this->escopo['global']) {
            $consulta->groupStart()
                ->whereIn('p.campus_id', $this->escopo['campus'] === [] ? [0] : $this->escopo['campus'])
                ->orWhereIn('p.id_predio', $this->escopo['predio'] === [] ? [0] : $this->escopo['predio'])
                ->groupEnd();
        }

        $this->predios = [];
        foreach ($consulta->get()->getResultArray() as $linha) {
            $this->predios[(int) $linha['id_predio']] = [
                'rotulo'    => $linha['campus'] . ' › ' . $linha['nome'],
                'campus_id' => (int) $linha['campus_id'],
            ];
        }

        return $this->predios;
    }

    /**
     * Condição SQL do escopo mais os filtros de campus e prédio sobre as colunas de campus e prédio de um
     * local (prédio NULL = almoxarifado, que conta pelo campus). null = sem restrição.
     */
    public function condicaoLocal(string $colunaCampus, string $colunaPredio): ?string
    {
        $partes = [];

        if (! $this->escopo['global']) {
            $escopo = [];

            if ($this->escopo['campus'] !== []) {
                $escopo[] = "{$colunaCampus} IN (" . implode(', ', array_map('intval', $this->escopo['campus'])) . ')';
            }

            if ($this->escopo['predio'] !== []) {
                $escopo[] = "{$colunaPredio} IN (" . implode(', ', array_map('intval', $this->escopo['predio'])) . ')';
            }

            $partes[] = $escopo === [] ? '1 = 0' : '(' . implode(' OR ', $escopo) . ')';
        }

        if ($this->campusId !== null) {
            $partes[] = "{$colunaCampus} = {$this->campusId}";
        }

        if ($this->predioId !== null) {
            $partes[] = "{$colunaPredio} = {$this->predioId}";
        }

        return $partes === [] ? null : implode(' AND ', $partes);
    }

    /** Início do período ('Y-m-d 00:00:00'), para comparar com DATETIME. */
    public function inicioSql(): string
    {
        return $this->db->escape($this->de->format('Y-m-d 00:00:00'));
    }

    /** Fim exclusivo do período (dia seguinte ao último, 00:00). */
    public function fimSql(): string
    {
        return $this->db->escape($this->ate->modify('+1 day')->format('Y-m-d 00:00:00'));
    }

    /** Condição "coluna DATETIME dentro do período". */
    public function condicaoPeriodo(string $coluna): string
    {
        return "{$coluna} >= {$this->inicioSql()} AND {$coluna} < {$this->fimSql()}";
    }

    /**
     * Parâmetros da URL (ver Tabela::filtros()).
     *
     * @return array<string, string>
     */
    public function query(bool $comPeriodo, bool $comAgrupamento = false): array
    {
        $query = [
            'campus' => $this->campusId === null ? '' : (string) $this->campusId,
            'predio' => $this->predioId === null ? '' : (string) $this->predioId,
        ];

        if ($comPeriodo) {
            $query['de']  = $this->de->format('Y-m-d');
            $query['ate'] = $this->ate->format('Y-m-d');
        }

        if ($comAgrupamento) {
            $query['agrupar'] = $this->agrupamento;
        }

        return array_filter($query, static fn (string $valor): bool => $valor !== '');
    }

    /** Filtros aplicados em texto, para o título e o cabeçalho do PDF. */
    public function descricao(bool $comPeriodo): string
    {
        $formato = (string) lang('Institucional.dateFormat');
        $partes  = [];

        if ($comPeriodo) {
            $partes[] = lang(
                'Relatorios.periodDescription',
                [$this->de->format($formato), $this->ate->format($formato)]
            );
        }

        if ($this->predioId !== null) {
            $partes[] = $this->predios()[$this->predioId]['rotulo'];
        } elseif ($this->campusId !== null) {
            $partes[] = $this->campi()[$this->campusId];
        }

        return implode(' · ', $partes);
    }

    private static function data(string $valor): ?DateTimeImmutable
    {
        $data = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        return $data !== false && $data->format('Y-m-d') === $valor ? $data : null;
    }
}

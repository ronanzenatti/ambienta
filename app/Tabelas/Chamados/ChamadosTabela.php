<?php

namespace App\Tabelas\Chamados;

use App\Libraries\Chamados\Chamados;
use App\Libraries\Patrimonio\Locais;
use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/**
 * Chamados de manutenção dos ambientes no escopo de leitura do usuário, mais os que ele mesmo abriu.
 * Em aberto primeiro (ordem do ENUM: aberto, em andamento, concluído, cancelado). Sem exclusão: o
 * cancelamento cumpre esse papel e preserva o histórico.
 *
 * Com $ambienteId, só os chamados de um ambiente (cartão da página do ambiente): tabela independente da URL da
 * página, com a rota própria; quem chama já conferiu a leitura de chamados no ambiente.
 */
final class ChamadosTabela extends Tabela
{
    /** @param array{global: bool, campus: list<int>, predio: list<int>} $escopo */
    public function __construct(
        private readonly array $escopo,
        private readonly int $usuarioId,
        private readonly ?int $ambienteId = null,
    ) {
    }

    public function independente(): bool
    {
        return $this->ambienteId !== null;
    }

    public static function tom(string $status): string
    {
        return match ($status) {
            'concluido' => 'confirmed',
            'cancelado' => 'critical',
            default     => 'pending',
        };
    }

    /**
     * @param array<string, mixed> $linha
     *
     * @return list<string> campus, prédio, ambiente
     */
    private static function linhasLocal(array $linha): array
    {
        return [(string) $linha['local_campus'], (string) $linha['local_predio'], (string) $linha['local_ambiente']];
    }

    public function id(): string
    {
        return $this->ambienteId === null ? 'chamados' : 'ambiente-chamados';
    }

    public function titulo(): string
    {
        return lang('Chamados.ticketsTitle');
    }

    public function urlLista(): string
    {
        return site_url($this->ambienteId === null ? 'chamados' : "ambientes/{$this->ambienteId}/chamados");
    }

    public function urlExportar(): string
    {
        return site_url(
            $this->ambienteId === null ? 'chamados/exportar' : "ambientes/{$this->ambienteId}/chamados/exportar"
        );
    }

    public function ordemPadrao(): array
    {
        return ['status', 'asc'];
    }

    protected function consulta(): BaseBuilder
    {
        $builder = db_connect()->table('chamados c')
            ->select('c.id_chamado, c.status, c.descricao, c.criado_em, c.alterado_em, u.nome AS aberto_por')
            ->select(Locais::rotuloSql('l') . ' AS ambiente', false)
            ->select(
                'IF(LENGTH(TRIM(l_ca.abreviacao)) > 0, TRIM(l_ca.abreviacao), l_ca.nome) AS local_campus',
                false
            )
            ->select('l_pr.abreviacao AS local_predio, CONCAT(l_amb.numero, \' \', l_amb.nome) AS local_ambiente', false)
            ->join('usuarios u', 'u.id_usuario = c.aberto_por_id')
            ->where('c.deletado_em', null);

        Locais::juntar($builder, 'l', 'c.ambiente_id', 'NULL');

        if ($this->ambienteId !== null) {
            return $builder->where('c.ambiente_id', $this->ambienteId);
        }

        $escopo = Locais::condicaoEscopo($this->escopo, 'l');

        if ($escopo !== null) {
            $builder->where('(' . $escopo . ' OR c.aberto_por_id = ' . $this->usuarioId . ')', null, false);
        }

        return $builder;
    }

    protected function chavePrimaria(): string
    {
        return 'c.id_chamado';
    }

    protected function definirColunas(): array
    {
        $colunas = [

            Coluna::nova('id_chamado', lang('Chamados.fieldNumber'), 'c.id_chamado')
                ->principal()
                ->numerica()
                ->html(static fn (array $linha, string $texto): string => '<a href="'
                    . esc(site_url('chamados/' . $linha['id_chamado']), 'attr')
                    . '" class="inline-flex min-h-11 items-center underline">#' . esc($texto) . '</a>'),
            // Uma entidade por linha (campus, prédio, ambiente); a busca e a ordem usam o rótulo completo.
            Coluna::nova('ambiente', lang('Chamados.fieldEnvironment'), Locais::rotuloSql('l'))
                ->texto(static fn (array $linha): string => implode("\n", self::linhasLocal($linha)))
                ->html(static fn (array $linha): string => implode('', array_map(
                    static fn (string $parte): string => '<span class="block">' . esc($parte) . '</span>',
                    self::linhasLocal($linha)
                ))),
            Coluna::nova('descricao', lang('Chamados.fieldDescription'), 'c.descricao')
                ->semOrdem()
                ->texto(static fn (array $linha): string => mb_strimwidth((string) $linha['descricao'], 0, 90, '…')),
            Coluna::nova('status', lang('Ui.status'), 'c.status')
                ->rotulos(opcoes_traduzidas('Chamados.status_', Chamados::STATUS))
                ->html(static fn (array $linha, string $texto): string => ui_status_dot(
                    self::tom((string) $linha['status']),
                    $texto
                )),
            Coluna::nova('aberto_por', lang('Chamados.fieldOpenedBy'), 'u.nome'),
            Coluna::nova('criado_em', lang('Chamados.fieldOpenedAt'), 'c.criado_em')->dataHora(),
            Coluna::nova('alterado_em', lang('Chamados.fieldUpdatedAt'), 'c.alterado_em')->dataHora(),
        ];

        // Na página do ambiente a coluna do ambiente seria sempre a mesma.
        return $this->ambienteId === null
            ? $colunas
            : array_values(array_filter($colunas, static fn (Coluna $c): bool => $c->chave !== 'ambiente'));
    }

    public function acoes(array $linha): array
    {
        return ['ver' => site_url('chamados/' . $linha['id_chamado'])];
    }

    public function temExclusao(): bool
    {
        return false;
    }

    public function formulariosEmModal(): bool
    {
        return $this->ambienteId === null;
    }

    public function vazio(): string
    {
        return lang('Chamados.ticketsEmpty');
    }
}

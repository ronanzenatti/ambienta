<?php

namespace App\Tabelas\Patrimonio;

use App\Libraries\Patrimonio\Locais;
use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/**
 * Movimentações de itens que o usuário pode ver: as em que é remetente ou destinatário, mais as com origem
 * ou destino no seu escopo de leitura. Situação calculada: pendente, atrasada (prazo vencido) ou confirmada.
 *
 * Variações: $itemId (histórico na página do item) e $aReceber (só as pendentes em que o usuário é o
 * destinatário: a fila "Aguardando meu recebimento").
 */
final class MovimentacoesTabela extends Tabela
{
    public const SITUACOES = ['pendente', 'atrasada', 'confirmada'];

    private const SITUACAO_SQL = "CASE WHEN mv.status = 'confirmada' THEN 'confirmada'"
        . " WHEN mv.data_limite_confirmacao < NOW() THEN 'atrasada' ELSE 'pendente' END";

    /** @param array{global: bool, campus: list<int>, predio: list<int>} $escopo */
    public function __construct(
        private readonly array $escopo,
        private readonly int $usuarioId,
        private readonly ?int $itemId = null,
        private readonly bool $aReceber = false
    ) {
    }

    public static function tom(string $situacao): string
    {
        return match ($situacao) {
            'confirmada' => 'confirmed',
            'atrasada'   => 'critical',
            default      => 'pending',
        };
    }

    public function id(): string
    {
        return 'movimentacoes';
    }

    public function titulo(): string
    {
        return lang(match (true) {
            $this->itemId !== null => 'Patrimonio.itemHistory',
            $this->aReceber        => 'Patrimonio.toReceiveTitle',
            default                => 'Patrimonio.movementsTitle',
        });
    }

    public function urlLista(): string
    {
        return site_url(match (true) {
            $this->itemId !== null => "patrimonio/itens/{$this->itemId}",
            $this->aReceber        => 'patrimonio/movimentacoes/a-receber',
            default                => 'patrimonio/movimentacoes',
        });
    }

    public function urlExportar(): string
    {
        return site_url(match (true) {
            $this->itemId !== null => "patrimonio/itens/{$this->itemId}/movimentacoes/exportar",
            $this->aReceber        => 'patrimonio/movimentacoes/a-receber/exportar',
            default                => 'patrimonio/movimentacoes/exportar',
        });
    }

    public function ordemPadrao(): array
    {
        return ['data_envio', 'desc'];
    }

    protected function consulta(): BaseBuilder
    {
        $builder = db_connect()->table('movimentacoes_itens mv')
            ->select('mv.id_movimentacao_item, mv.data_envio, mv.data_limite_confirmacao, mv.data_confirmacao')
            ->select('i.numero_patrimonio, i.patrimoniavel, i.nome, i.quantidade')
            ->select('rem.nome AS remetente, des.nome AS destinatario')
            ->select(Locais::rotuloSql('o') . ' AS origem', false)
            ->select(Locais::rotuloSql('d') . ' AS destino', false)
            ->select(self::SITUACAO_SQL . ' AS situacao', false)
            ->join('itens i', 'i.id_item = mv.item_id')
            ->join('usuarios rem', 'rem.id_usuario = mv.remetente_id')
            ->join('usuarios des', 'des.id_usuario = mv.destinatario_id')
            ->where('mv.deletado_em', null);

        Locais::juntar($builder, 'o', 'mv.origem_ambiente_id', 'mv.origem_almoxarifado_id');
        Locais::juntar($builder, 'd', 'mv.destino_ambiente_id', 'mv.destino_almoxarifado_id');

        if ($this->aReceber) {
            $builder->where('mv.destinatario_id', $this->usuarioId)->where('mv.status', 'pendente');
        } else {
            $origem  = Locais::condicaoEscopo($this->escopo, 'o');
            $destino = Locais::condicaoEscopo($this->escopo, 'd');

            if ($origem !== null && $destino !== null) {
                $builder->where(
                    "(mv.remetente_id = {$this->usuarioId} OR mv.destinatario_id = {$this->usuarioId}"
                    . " OR {$origem} OR {$destino})",
                    null,
                    false
                );
            }
        }

        if ($this->itemId !== null) {
            $builder->where('mv.item_id', $this->itemId);
        }

        return $builder;
    }

    protected function chavePrimaria(): string
    {
        return 'mv.id_movimentacao_item';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('item', lang('Patrimonio.fieldItem'), 'i.numero_patrimonio')
                ->principal()
                ->buscaEm('i.numero_patrimonio', 'i.nome')
                ->texto(static fn (array $linha): string => item_rotulo($linha))
                ->html(static fn (array $linha, string $texto): string => '<a href="'
                    . esc(site_url('patrimonio/movimentacoes/' . $linha['id_movimentacao_item']), 'attr')
                    . '" class="inline-flex min-h-11 items-center underline">' . esc($texto) . '</a>'),
            Coluna::nova('origem', lang('Patrimonio.fieldOrigin'), Locais::rotuloSql('o')),
            Coluna::nova('destino', lang('Patrimonio.fieldDestination'), Locais::rotuloSql('d')),
            Coluna::nova('remetente', lang('Patrimonio.fieldSender'), 'rem.nome'),
            Coluna::nova('destinatario', lang('Patrimonio.fieldRecipient'), 'des.nome'),
            Coluna::nova('data_envio', lang('Patrimonio.fieldSentAt'), 'mv.data_envio')->dataHora(),
            Coluna::nova('data_limite_confirmacao', lang('Patrimonio.fieldDeadline'), 'mv.data_limite_confirmacao')
                ->dataHora(),
            Coluna::nova('situacao', lang('Ui.status'), self::SITUACAO_SQL)
                ->rotulos(opcoes_traduzidas('Patrimonio.movement_', self::SITUACOES))
                ->html(static fn (array $linha, string $texto): string => ui_status_dot(
                    self::tom((string) $linha['situacao']),
                    $texto
                )),
        ];
    }

    public function acoes(array $linha): array
    {
        return ['ver' => site_url('patrimonio/movimentacoes/' . $linha['id_movimentacao_item'])];
    }

    public function temExclusao(): bool
    {
        return false;
    }

    public function vazio(): string
    {
        return lang($this->aReceber ? 'Patrimonio.toReceiveEmpty' : 'Patrimonio.movementsEmpty');
    }
}

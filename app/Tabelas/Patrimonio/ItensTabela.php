<?php

namespace App\Tabelas\Patrimonio;

use App\Libraries\Patrimonio\Locais;
use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use App\Models\Patrimonio\ItemModel;
use CodeIgniter\Database\BaseBuilder;

/**
 * Itens ativos (descartados saem da lista) no escopo de leitura do usuário, pelo local onde estão: patrimoniais
 * (um por número) e sem patrimônio (por quantidade). Patrimonial ainda sem número aparece em amarelo, com o aviso
 * em texto na própria linha. Com $modeloId, só os itens criados a partir de um modelo (página do modelo).
 */
final class ItensTabela extends Tabela
{
    public const STATUS = ['em_uso', 'almoxarifado', 'em_transito', 'descartado'];

    /** @param array{global: bool, campus: list<int>, predio: list<int>} $escopo */
    public function __construct(private readonly array $escopo, private readonly ?int $modeloId = null)
    {
    }

    /** Tom do indicador de status de cada situação do item. */
    public static function tom(string $status): string
    {
        return match ($status) {
            'em_uso'      => 'confirmed',
            'em_transito' => 'pending',
            'descartado'  => 'critical',
            default       => 'neutral',
        };
    }

    public function id(): string
    {
        return 'itens';
    }

    public function titulo(): string
    {
        return lang($this->modeloId === null ? 'Patrimonio.itemsTitle' : 'Patrimonio.modelUnits');
    }

    public function urlLista(): string
    {
        return site_url($this->modeloId === null ? 'patrimonio/itens' : "patrimonio/modelos/{$this->modeloId}");
    }

    public function urlExportar(): string
    {
        return site_url(
            $this->modeloId === null ? 'patrimonio/itens/exportar' : "patrimonio/modelos/{$this->modeloId}/exportar"
        );
    }

    protected function consulta(): BaseBuilder
    {
        $builder = db_connect()->table('itens i')
            ->select('i.id_item, i.numero_patrimonio, i.patrimoniavel, i.nome, i.quantidade, i.status')
            ->select('c.nome AS categoria')
            ->select(Locais::rotuloSql('l') . ' AS local', false)
            ->join('categorias_itens c', 'c.id_categoria_item = i.categoria_item_id')
            ->where('i.deletado_em', null);

        Locais::juntar($builder, 'l', 'i.ambiente_id', 'i.almoxarifado_id');

        $escopo = Locais::condicaoEscopo($this->escopo, 'l');

        if ($escopo !== null) {
            $builder->where($escopo, null, false);
        }

        if ($this->modeloId !== null) {
            $builder->where('i.modelo_item_id', $this->modeloId);
        }

        return $builder;
    }

    protected function chavePrimaria(): string
    {
        return 'i.id_item';
    }

    protected function definirColunas(): array
    {
        return [
            self::colunaNumero(),
            Coluna::nova('nome', lang('Institucional.fieldName'), 'i.nome')->principal(),
            Coluna::nova('categoria', lang('Patrimonio.fieldCategory'), 'c.nome'),
            Coluna::nova('quantidade', lang('Patrimonio.fieldQuantity'), 'i.quantidade')->numerica(),
            Coluna::nova('local', lang('Patrimonio.fieldLocation'), Locais::rotuloSql('l')),
            Coluna::nova('status', lang('Ui.status'), 'i.status')
                ->rotulos(opcoes_traduzidas('Patrimonio.status_', self::STATUS))
                ->html(static fn (array $linha, string $texto): string => ui_status_dot(
                    self::tom((string) $linha['status']),
                    $texto
                )),
        ];
    }

    /**
     * Coluna do número de patrimônio, com link para o item. Sem número, a própria célula diz o motivo: patrimonial
     * "Aguardando número" (ponto amarelo + texto) ou "Sem patrimônio". Usada também nas tabelas de itens de outras
     * telas (ambiente), que selecionam id_item, numero_patrimonio e patrimoniavel.
     */
    public static function colunaNumero(): Coluna
    {
        return Coluna::nova('numero_patrimonio', lang('Patrimonio.fieldNumber'), 'i.numero_patrimonio')
            ->texto(static fn (array $linha, mixed $valor): string => item_numero($linha))
            ->html(static function (array $linha, string $texto): string {
                $link = static fn (string $conteudo): string => '<a href="'
                    . esc(site_url('patrimonio/itens/' . $linha['id_item']), 'attr')
                    . '" class="inline-flex min-h-11 items-center tabular-nums underline">' . $conteudo . '</a>';

                return match (true) {
                    $linha['numero_patrimonio'] !== null => $link(esc($texto)),
                    ItemModel::pendente($linha)         => $link(ui_status_dot('pending', $texto)),
                    default                             => '<span class="text-ink-muted">' . esc($texto) . '</span>',
                };
            });
    }

    public function classeLinha(array $linha): string
    {
        return ItemModel::pendente($linha) ? 'bg-action-edit/15' : parent::classeLinha($linha);
    }

    public function acoes(array $linha): array
    {
        return ['ver' => site_url('patrimonio/itens/' . $linha['id_item'])];
    }

    public function temExclusao(): bool
    {
        return false;
    }

    public function vazio(): string
    {
        return lang('Patrimonio.itemsEmpty');
    }
}

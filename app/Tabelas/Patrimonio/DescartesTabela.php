<?php

namespace App\Tabelas\Patrimonio;

use App\Libraries\Patrimonio\Locais;
use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/**
 * Solicitações de descarte dos itens no escopo de leitura do usuário (pelo local do item; item descartado
 * continua no local onde foi baixado). Pendentes primeiro.
 */
final class DescartesTabela extends Tabela
{
    public const STATUS = ['pendente', 'aprovado', 'rejeitado'];

    /** @param array{global: bool, campus: list<int>, predio: list<int>} $escopo */
    public function __construct(private readonly array $escopo)
    {
    }

    public static function tom(string $status): string
    {
        return match ($status) {
            'aprovado'  => 'confirmed',
            'rejeitado' => 'neutral',
            default     => 'pending',
        };
    }

    public function id(): string
    {
        return 'descartes';
    }

    public function titulo(): string
    {
        return lang('Patrimonio.disposalsTitle');
    }

    public function urlLista(): string
    {
        return site_url('patrimonio/descartes');
    }

    public function urlExportar(): string
    {
        return site_url('patrimonio/descartes/exportar');
    }

    public function ordemPadrao(): array
    {
        return ['status', 'asc'];
    }

    protected function consulta(): BaseBuilder
    {
        $builder = db_connect()->table('descartes ds')
            ->select('ds.id_descarte, ds.status, ds.data_solicitacao, ds.data_decisao')
            ->select('i.numero_patrimonio, i.patrimoniavel, i.nome, i.quantidade, mo.nome AS motivo')
            ->select('sol.nome AS solicitante, apr.nome AS aprovador')
            ->select(Locais::rotuloSql('l') . ' AS local', false)
            ->join('itens i', 'i.id_item = ds.item_id')
            ->join('motivos_descarte mo', 'mo.id_motivo_descarte = ds.motivo_descarte_id')
            ->join('usuarios sol', 'sol.id_usuario = ds.solicitante_id')
            ->join('usuarios apr', 'apr.id_usuario = ds.aprovador_id', 'left')
            ->where('ds.deletado_em', null);

        Locais::juntar($builder, 'l', 'i.ambiente_id', 'i.almoxarifado_id');

        $escopo = Locais::condicaoEscopo($this->escopo, 'l');

        if ($escopo !== null) {
            $builder->where($escopo, null, false);
        }

        return $builder;
    }

    protected function chavePrimaria(): string
    {
        return 'ds.id_descarte';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('item', lang('Patrimonio.fieldItem'), 'i.numero_patrimonio')
                ->principal()
                ->buscaEm('i.numero_patrimonio', 'i.nome')
                ->texto(static fn (array $linha): string => item_rotulo($linha))
                ->html(static fn (array $linha, string $texto): string => '<a href="'
                    . esc(site_url('patrimonio/descartes/' . $linha['id_descarte']), 'attr')
                    . '" class="inline-flex min-h-11 items-center underline">' . esc($texto) . '</a>'),
            Coluna::nova('local', lang('Patrimonio.fieldLocation'), Locais::rotuloSql('l')),
            Coluna::nova('motivo', lang('Patrimonio.fieldReason'), 'mo.nome'),
            Coluna::nova('solicitante', lang('Patrimonio.fieldRequester'), 'sol.nome'),
            Coluna::nova('data_solicitacao', lang('Patrimonio.fieldRequestedAt'), 'ds.data_solicitacao')->dataHora(),
            Coluna::nova('status', lang('Ui.status'), 'ds.status')
                ->rotulos(opcoes_traduzidas('Patrimonio.disposal_', self::STATUS))
                ->html(static fn (array $linha, string $texto): string => ui_status_dot(
                    self::tom((string) $linha['status']),
                    $texto
                )),
            Coluna::nova('aprovador', lang('Patrimonio.fieldApprover'), 'apr.nome'),
        ];
    }

    public function acoes(array $linha): array
    {
        return ['ver' => site_url('patrimonio/descartes/' . $linha['id_descarte'])];
    }

    public function temExclusao(): bool
    {
        return false;
    }

    public function vazio(): string
    {
        return lang('Patrimonio.disposalsEmpty');
    }
}

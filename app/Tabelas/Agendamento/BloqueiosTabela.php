<?php

namespace App\Tabelas\Agendamento;

use App\Libraries\Agendamento\Bloqueios;
use App\Libraries\Patrimonio\Locais;
use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use Closure;
use CodeIgniter\Database\BaseBuilder;

/**
 * Bloqueios de ambiente no escopo de leitura do usuário. Cadastro em modal (ambiente, tipo, motivo e
 * período); excluir só onde o usuário gere o ambiente ($podeGerir).
 */
final class BloqueiosTabela extends Tabela
{
    /**
     * @param array{global: bool, campus: list<int>, predio: list<int>} $escopo
     * @param Closure(int): bool                                        $podeGerir recebe o ambiente
     */
    public function __construct(private readonly array $escopo, private readonly Closure $podeGerir)
    {
    }

    public function id(): string
    {
        return 'bloqueios';
    }

    public function titulo(): string
    {
        return lang('Agendamento.blocksTitle');
    }

    public function urlLista(): string
    {
        return site_url('agendamento/bloqueios');
    }

    public function urlExportar(): string
    {
        return site_url('agendamento/bloqueios/exportar');
    }

    public function ordemPadrao(): array
    {
        return ['data_hora_inicio', 'desc'];
    }

    protected function consulta(): BaseBuilder
    {
        $builder = db_connect()->table('bloqueios_ambientes b')
            ->select('b.id_bloqueio_ambiente, b.ambiente_id, b.tipo, b.motivo, b.data_hora_inicio, b.data_hora_fim,
                      u.nome AS criado_por')
            ->select(Locais::rotuloSql('l') . ' AS ambiente', false)
            ->join('usuarios u', 'u.id_usuario = b.criado_por_id')
            ->where('b.deletado_em', null);

        Locais::juntar($builder, 'l', 'b.ambiente_id', 'NULL');

        $escopo = Locais::condicaoEscopo($this->escopo, 'l');

        if ($escopo !== null) {
            $builder->where($escopo, null, false);
        }

        return $builder;
    }

    protected function chavePrimaria(): string
    {
        return 'b.id_bloqueio_ambiente';
    }

    protected function definirColunas(): array
    {
        return [
            Coluna::nova('ambiente', lang('Agendamento.fieldEnvironment'), Locais::rotuloSql('l'))->principal(),
            Coluna::nova('tipo', lang('Agendamento.fieldBlockType'), 'b.tipo')
                ->rotulos(opcoes_traduzidas('Agendamento.blockType_', Bloqueios::TIPOS))
                ->html(static fn (array $linha, string $texto): string => ui_status_dot('critical', $texto)),
            Coluna::nova('motivo', lang('Agendamento.fieldReason'), 'b.motivo')
                ->semOrdem()
                ->texto(static fn (array $linha): string => mb_strimwidth((string) $linha['motivo'], 0, 90, '…')),
            Coluna::nova('data_hora_inicio', lang('Agendamento.fieldStart'), 'b.data_hora_inicio')->dataHora(),
            Coluna::nova('data_hora_fim', lang('Agendamento.fieldEnd'), 'b.data_hora_fim')->dataHora(),
            Coluna::nova('criado_por', lang('Agendamento.fieldBlockedBy'), 'u.nome'),
        ];
    }

    public function formulariosEmModal(): bool
    {
        return true;
    }

    public function acoes(array $linha): array
    {
        $gere = ($this->podeGerir)((int) $linha['ambiente_id']);

        return ['excluir' => $gere ? site_url('agendamento/bloqueios/' . $linha['id_bloqueio_ambiente']) : null];
    }

    public function mensagemExclusao(array $linha): string
    {
        return lang('Agendamento.blockDeleteText', [(string) $linha['ambiente']]);
    }

    public function tituloExclusao(): string
    {
        return lang('Agendamento.blockDeleteTitle');
    }

    public function vazio(): string
    {
        return lang('Agendamento.blocksEmpty');
    }
}

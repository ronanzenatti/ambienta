<?php

namespace App\Tabelas\Notificacoes;

use App\Libraries\Auditoria\Auditoria;
use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/**
 * Log de auditoria no escopo de quem consulta (auditoria.consultar): global vê tudo; campus/prédio vê o que
 * está no seu escopo (pelo local do registro auditado); usuários e perfis, só o escopo global.
 */
final class LogsAuditoriaTabela extends Tabela
{
    /** @param array{global: bool, campus: list<int>, predio: list<int>} $escopo */
    public function __construct(private readonly array $escopo)
    {
    }

    public function id(): string
    {
        return 'auditoria';
    }

    public function titulo(): string
    {
        return lang('Auditoria.title');
    }

    public function urlLista(): string
    {
        return site_url('auditoria');
    }

    public function urlExportar(): string
    {
        return site_url('auditoria/exportar');
    }

    public function ordemPadrao(): array
    {
        return ['criado_em', 'desc'];
    }

    protected function consulta(): BaseBuilder
    {
        $builder = db_connect()->table('logs_auditoria la')
            ->select('la.id_log_auditoria, la.tabela_afetada, la.registro_id, la.acao, la.criado_em, la.ip_origem')
            ->select('u.nome AS usuario')
            ->join('usuarios u', 'u.id_usuario = la.usuario_id', 'left');

        $escopo = Auditoria::condicaoEscopo($this->escopo);

        if ($escopo !== null) {
            $builder->where($escopo, null, false);
        }

        return $builder;
    }

    protected function chavePrimaria(): string
    {
        return 'la.id_log_auditoria';
    }

    protected function definirColunas(): array
    {
        $tabelas = [];

        foreach (array_keys(Auditoria::TABELAS) as $tabela) {
            $tabelas[$tabela] = lang('Auditoria.table_' . $tabela);
        }

        return [
            Coluna::nova('criado_em', lang('Auditoria.fieldWhen'), 'la.criado_em')->dataHora(),
            Coluna::nova('tabela_afetada', lang('Auditoria.fieldTable'), 'la.tabela_afetada')
                ->principal()
                ->rotulos($tabelas),
            Coluna::nova('registro_id', lang('Auditoria.fieldRecord'), 'la.registro_id')->numerica(),
            Coluna::nova('acao', lang('Auditoria.fieldAction'), 'la.acao')
                ->rotulos(opcoes_traduzidas('Auditoria.action_', Auditoria::ACOES)),
            Coluna::nova('usuario', lang('Auditoria.fieldUser'), 'u.nome')
                ->texto(static fn (array $linha): string => (string) ($linha['usuario'] ?? lang('Auditoria.system'))),
            Coluna::nova('ip_origem', lang('Auditoria.fieldIp'), 'la.ip_origem'),
        ];
    }

    public function acoes(array $linha): array
    {
        return ['ver' => site_url('auditoria/' . $linha['id_log_auditoria'])];
    }

    public function temExclusao(): bool
    {
        return false;
    }

    public function vazio(): string
    {
        return lang('Auditoria.empty');
    }
}

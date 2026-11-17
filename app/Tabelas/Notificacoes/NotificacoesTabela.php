<?php

namespace App\Tabelas\Notificacoes;

use App\Libraries\Notificacoes\Mensagem;
use App\Libraries\Notificacoes\Notificador;
use App\Libraries\Tabela\Coluna;
use App\Libraries\Tabela\Tabela;
use CodeIgniter\Database\BaseBuilder;

/**
 * Caixa de notificações do usuário logado (só as dele). O texto é montado na leitura, no idioma atual; a
 * busca procura pelo tipo (título traduzido), pela situação e pela data. Ver abre o registro de origem e
 * marca como lida.
 */
final class NotificacoesTabela extends Tabela
{
    public function __construct(private readonly int $usuarioId)
    {
    }

    public function id(): string
    {
        return 'notificacoes';
    }

    public function titulo(): string
    {
        return lang('Notificacoes.title');
    }

    public function urlLista(): string
    {
        return site_url('notificacoes');
    }

    public function urlExportar(): string
    {
        return site_url('notificacoes/exportar');
    }

    public function ordemPadrao(): array
    {
        return ['criado_em', 'desc'];
    }

    protected function consulta(): BaseBuilder
    {
        return db_connect()->table('notificacoes n')
            ->select('n.id_notificacao, n.lida, n.criado_em, n.parametros, n.referencia_tipo, n.referencia_id, t.chave')
            ->join('tipos_notificacoes t', 't.id_tipo_notificacao = n.tipo_notificacao_id')
            ->where('n.usuario_id', $this->usuarioId)
            ->where('n.deletado_em', null);
    }

    protected function chavePrimaria(): string
    {
        return 'n.id_notificacao';
    }

    protected function definirColunas(): array
    {
        $tipos = [];

        foreach (array_keys(Notificador::TIPOS) as $chave) {
            $tipos[$chave] = Mensagem::titulo($chave);
        }

        return [
            Coluna::nova('chave', lang('Notificacoes.fieldNotification'), 't.chave')
                ->principal()
                ->semOrdem()
                ->rotulos($tipos)
                ->texto(static function (array $linha): string {
                    $parametros = Mensagem::parametros($linha['parametros']);

                    return Mensagem::titulo((string) $linha['chave'], $parametros) . ': '
                        . Mensagem::texto((string) $linha['chave'], $parametros);
                })
                ->html(static function (array $linha): string {
                    $parametros = Mensagem::parametros($linha['parametros']);

                    return '<span class="block' . ((bool) $linha['lida'] ? '' : ' font-semibold') . '">'
                        . esc(Mensagem::titulo((string) $linha['chave'], $parametros)) . '</span>'
                        . '<span class="block max-w-2xl font-normal text-ink-muted">'
                        . esc(Mensagem::texto((string) $linha['chave'], $parametros)) . '</span>';
                }),
            Coluna::nova('lida', lang('Ui.status'), 'n.lida')
                ->rotulos([0 => lang('Notificacoes.unread'), 1 => lang('Notificacoes.read')])
                ->html(static fn (array $linha, string $texto): string => ui_status_dot(
                    (bool) $linha['lida'] ? 'neutral' : 'pending',
                    $texto
                )),
            Coluna::nova('criado_em', lang('Notificacoes.fieldReceived'), 'n.criado_em')->dataHora(),
        ];
    }

    public function acoes(array $linha): array
    {
        return ['ver' => site_url('notificacoes/' . $linha['id_notificacao'])];
    }

    public function temExclusao(): bool
    {
        return false;
    }

    public function vazio(): string
    {
        return lang('Notificacoes.empty');
    }
}

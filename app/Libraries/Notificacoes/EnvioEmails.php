<?php

namespace App\Libraries\Notificacoes;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Email\Email;
use Config\Email as EmailConfig;
use Config\Notificacoes;

/**
 * Fila de e-mail das notificações: cada notificação ainda não enviada vira um e-mail, por evento (sem resumo
 * consolidado), no idioma de quem recebe. Roda pelo cron (php spark notificacoes:enviar), fora da requisição
 * de quem fez a ação: SMTP lento ou fora do ar nunca trava a tela, e o que falhar tenta de novo na próxima vez.
 *
 * Fica de fora quem desativou o e-mail (com o termo de ciência), usuário inativo/anonimizado e notificação
 * mais velha que a janela de envio (Config\Notificacoes::$janelaEmailHoras).
 */
final class EnvioEmails
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** @return array{enviadas: int, falhas: int} */
    public function enviar(Email $email): array
    {
        $config    = config(Notificacoes::class);
        $resultado = ['enviadas' => 0, 'falhas' => 0];

        $pendentes = $this->db->query(
            'SELECT n.id_notificacao, n.parametros, n.referencia_tipo, n.referencia_id, t.chave,
                    u.email, u.idioma_preferido
             FROM notificacoes n
             JOIN tipos_notificacoes t ON t.id_tipo_notificacao = n.tipo_notificacao_id
             JOIN usuarios u ON u.id_usuario = n.usuario_id
             LEFT JOIN preferencias_notificacao pn ON pn.usuario_id = u.id_usuario AND pn.deletado_em IS NULL
             WHERE n.deletado_em IS NULL AND n.enviada_email = 0
               AND n.criado_em >= DATE_SUB(NOW(), INTERVAL ? HOUR)
               AND u.deletado_em IS NULL AND u.ativo = 1 AND u.anonimizado_em IS NULL
               AND COALESCE(pn.canal_email_ativo, 1) = 1
             ORDER BY n.id_notificacao
             LIMIT ?',
            [$config->janelaEmailHoras, $config->loteEmail]
        )->getResultArray();

        foreach ($pendentes as $notificacao) {
            if ($this->enviarUma($email, $notificacao)) {
                $this->db->query(
                    'UPDATE notificacoes SET enviada_email = 1, enviada_email_em = NOW() WHERE id_notificacao = ?',
                    [(int) $notificacao['id_notificacao']]
                );
                $resultado['enviadas']++;
            } else {
                $resultado['falhas']++;
            }
        }

        return $resultado;
    }

    /** @param array<string, mixed> $notificacao */
    private function enviarUma(Email $email, array $notificacao): bool
    {
        $idioma     = in_array($notificacao['idioma_preferido'], ['pt-BR', 'en', 'es'], true)
            ? (string) $notificacao['idioma_preferido']
            : 'pt-BR';
        $chave      = (string) $notificacao['chave'];
        $parametros = Mensagem::parametros($notificacao['parametros']);
        $url        = Mensagem::url($notificacao['referencia_tipo'], $notificacao['referencia_id']);

        $corpo = [Mensagem::texto($chave, $parametros, $idioma), ''];

        if ($url !== null) {
            $corpo[] = lang('Notificacoes.mailOpen', [], $idioma) . ' ' . $url;
            $corpo[] = '';
        }

        $corpo[] = '--';
        $corpo[] = lang('Notificacoes.mailFooter', [], $idioma) . ' ' . site_url('notificacoes/preferencias');

        $host      = parse_url(base_url(), PHP_URL_HOST) ?: 'localhost';
        $remetente = config(EmailConfig::class)->fromEmail ?: 'nao-responda@' . $host;

        $email->clear();
        $email->setFrom($remetente, lang('App.name', [], $idioma));
        $email->setTo((string) $notificacao['email']);
        $email->setSubject(lang('App.name', [], $idioma) . ': ' . Mensagem::titulo($chave, $parametros, $idioma));
        $email->setMessage(implode("\n", $corpo));

        return $email->send(false);
    }
}

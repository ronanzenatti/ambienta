<?php

namespace App\Libraries\Patrimonio;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Email\Email;
use Config\Email as EmailConfig;

/**
 * Atrasos de patrimônio (REQUISITOS §4): movimentação pendente com o prazo de resposta vencido gera um
 * alerta a cada acesso de quem recebe (aviso no layout) e um e-mail diário para os dois lados, sem
 * cancelamento automático. O e-mail sai pelo comando `php spark patrimonio:lembretes`, agendado no cron.
 *
 * Um e-mail por pessoa com todas as suas pendências, no idioma preferido dela; texto nos arquivos de idioma.
 * Itens extras de reserva deferidos e não devolvidos no prazo (reservas_itens_extras) entram no mesmo e-mail:
 * para quem precisa devolver (beneficiário, ou quem reservou) e para quem deferiu e confirma a devolução.
 */
final class LembreteAtrasos
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** Quantas movimentações a pessoa deveria ter confirmado e ainda não confirmou. */
    public function atrasadasParaReceber(int $usuarioId): int
    {
        return $this->db->table('movimentacoes_itens')
            ->where('destinatario_id', $usuarioId)
            ->where('status', 'pendente')
            ->where('deletado_em', null)
            ->where('data_limite_confirmacao < NOW()', null, false)
            ->countAllResults();
    }

    /**
     * Envia os lembretes do dia.
     *
     * @return array{movimentacoes: int, extras: int, emails: int, falhas: int}
     */
    public function enviar(Email $email): array
    {
        $atrasadas = $this->db->query(
            "SELECT m.id_movimentacao_item, m.data_envio, m.data_limite_confirmacao,
                    m.remetente_id, m.destinatario_id, i.numero_patrimonio, i.nome AS modelo
             FROM movimentacoes_itens m
             JOIN itens i ON i.id_item = m.item_id
             WHERE m.status = 'pendente' AND m.deletado_em IS NULL AND m.data_limite_confirmacao < NOW()
             ORDER BY m.data_limite_confirmacao, m.id_movimentacao_item"
        )->getResultArray();

        $extras = $this->db->query(
            "SELECT x.quantidade, x.data_limite_devolucao, x.decidido_por_id, c.nome AS categoria,
                    r.id_reserva_pontual, r.titulo, COALESCE(r.beneficiario_id, r.solicitante_id) AS responsavel_id
             FROM reservas_itens_extras x
             JOIN categorias_itens c ON c.id_categoria_item = x.categoria_item_id
             JOIN reservas_pontuais r ON r.id_reserva_pontual = x.reserva_pontual_id
             WHERE x.status = 'deferido' AND x.deletado_em IS NULL AND x.devolucao_confirmada_em IS NULL
               AND x.data_limite_devolucao < NOW()
             ORDER BY x.data_limite_devolucao, x.id_reserva_item_extra"
        )->getResultArray();

        /** @var array<int, array<string, list<array<string, mixed>>>> $porPessoa */
        $porPessoa = [];

        foreach ($atrasadas as $mov) {
            $porPessoa[(int) $mov['destinatario_id']]['receber'][] = $mov;
            $porPessoa[(int) $mov['remetente_id']]['enviar'][]     = $mov;
        }

        foreach ($extras as $extra) {
            $porPessoa[(int) $extra['responsavel_id']]['devolver'][] = $extra;

            if ($extra['decidido_por_id'] !== null) {
                $porPessoa[(int) $extra['decidido_por_id']]['aguardar'][] = $extra;
            }
        }

        $enviados = 0;
        $falhas   = 0;

        foreach ($porPessoa as $usuarioId => $pendencias) {
            $usuario = $this->db->query(
                'SELECT nome, email, idioma_preferido FROM usuarios
                 WHERE id_usuario = ? AND ativo = 1 AND deletado_em IS NULL',
                [$usuarioId]
            )->getRowArray();

            if ($usuario === null || empty($usuario['email'])) {
                continue;
            }

            $this->enviarPara($email, $usuario, $pendencias)
                ? $enviados++
                : $falhas++;
        }

        return [
            'movimentacoes' => count($atrasadas),
            'extras'        => count($extras),
            'emails'        => $enviados,
            'falhas'        => $falhas,
        ];
    }

    /**
     * @param array<string, mixed>                      $usuario
     * @param array<string, list<array<string, mixed>>> $pendencias receber/enviar (movimentações),
     *                                                              devolver/aguardar (extras de reserva)
     */
    private function enviarPara(Email $email, array $usuario, array $pendencias): bool
    {
        $idioma = (string) ($usuario['idioma_preferido'] ?: config('App')->defaultLocale);
        $linha  = function (array $mov) use ($idioma): string {
            $formato = lang('Institucional.dateFormat', [], $idioma) . ' H:i';
            $item    = $mov['numero_patrimonio'] ?? lang('Patrimonio.noNumber', [], $idioma);

            return '- ' . lang('Patrimonio.mailOverdueLine', [
                (string) $item,
                (string) $mov['modelo'],
                date($formato, (int) strtotime((string) $mov['data_limite_confirmacao'])),
                site_url('patrimonio/movimentacoes/' . $mov['id_movimentacao_item']),
            ], $idioma);
        };

        $linhaExtra = static function (array $extra) use ($idioma): string {
            $formato = lang('Institucional.dateFormat', [], $idioma) . ' H:i';

            return '- ' . lang('Patrimonio.mailOverdueExtraLine', [
                (int) $extra['quantidade'],
                (string) $extra['categoria'],
                (string) $extra['titulo'],
                date($formato, (int) strtotime((string) $extra['data_limite_devolucao'])),
                site_url('agendamento/reservas/' . $extra['id_reserva_pontual']),
            ], $idioma);
        };

        $corpo  = [lang('Patrimonio.mailOverdueHello', [(string) $usuario['nome']], $idioma), ''];
        $blocos = [
            'receber'  => ['Patrimonio.mailOverdueToReceive', $linha],
            'enviar'   => ['Patrimonio.mailOverdueSent', $linha],
            'devolver' => ['Patrimonio.mailOverdueToReturn', $linhaExtra],
            'aguardar' => ['Patrimonio.mailOverdueAwaitingReturn', $linhaExtra],
        ];

        foreach ($blocos as $chave => [$titulo, $formatar]) {
            if (($pendencias[$chave] ?? []) !== []) {
                $corpo[] = lang($titulo, [], $idioma);
                array_push($corpo, ...array_map($formatar, $pendencias[$chave]));
                $corpo[] = '';
            }
        }

        $corpo[] = lang('Patrimonio.mailOverdueFooter', [], $idioma);

        $host      = parse_url(base_url(), PHP_URL_HOST) ?: 'localhost';
        $remetente = config(EmailConfig::class)->fromEmail ?: 'nao-responda@' . $host;

        $email->clear();
        $email->setFrom($remetente, lang('App.name', [], $idioma));
        $email->setTo((string) $usuario['email']);
        $email->setSubject(lang('Patrimonio.mailOverdueSubject', [], $idioma));
        $email->setMessage(implode("\n", $corpo));

        return $email->send(false);
    }
}

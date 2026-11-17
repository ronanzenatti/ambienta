<?php

namespace App\Libraries\Agendamento;

use App\Libraries\Auditoria\Auditoria;
use App\Libraries\Notificacoes\Notificador;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/**
 * Equipamentos (por categoria de item) e softwares extras pedidos numa reserva (REQUISITOS §4 e §7): cada
 * pedido é deferido ou indeferido por quem gere o patrimônio do ambiente. Item deferido tem prazo de
 * devolução (fim da reserva + prazo de devolução do campus) e a devolução é confirmada depois; o atraso
 * entra no lembrete diário de patrimônio (LembreteAtrasos).
 *
 * A decisão avisa quem reservou e o beneficiário.
 */
final class Extras
{
    public const STATUS = ['pendente', 'deferido', 'indeferido'];

    private readonly Auditoria $auditoria;

    public function __construct(private readonly BaseConnection $db)
    {
        $this->auditoria = new Auditoria($db);
    }

    /**
     * @return array{itens: list<array<string, mixed>>, softwares: list<array<string, mixed>>}
     */
    public function daReserva(int $reservaId): array
    {
        $itens = $this->db->query(
            'SELECT x.*, c.nome AS categoria, d.nome AS decidido_por, dv.nome AS devolucao_por
             FROM reservas_itens_extras x
             JOIN categorias_itens c ON c.id_categoria_item = x.categoria_item_id
             LEFT JOIN usuarios d ON d.id_usuario = x.decidido_por_id
             LEFT JOIN usuarios dv ON dv.id_usuario = x.devolucao_confirmada_por_id
             WHERE x.reserva_pontual_id = ? AND x.deletado_em IS NULL
             ORDER BY c.nome',
            [$reservaId]
        )->getResultArray();

        $softwares = $this->db->query(
            'SELECT x.*, s.nome AS software, s.versao, d.nome AS decidido_por
             FROM reservas_softwares_extras x
             JOIN softwares s ON s.id_software = x.software_id
             LEFT JOIN usuarios d ON d.id_usuario = x.decidido_por_id
             WHERE x.reserva_pontual_id = ? AND x.deletado_em IS NULL
             ORDER BY s.nome',
            [$reservaId]
        )->getResultArray();

        return ['itens' => $itens, 'softwares' => $softwares];
    }

    /** @throws Recusa */
    public function decidirItem(int $id, int $usuarioId, bool $deferir, string $justificativa): void
    {
        $item = $this->db->query(
            "SELECT x.id_reserva_item_extra, r.data_hora_fim, r.ambiente_id
             FROM reservas_itens_extras x
             JOIN reservas_pontuais r ON r.id_reserva_pontual = x.reserva_pontual_id
             WHERE x.id_reserva_item_extra = ? AND x.deletado_em IS NULL AND x.status = 'pendente'",
            [$id]
        )->getRowArray();

        if ($item === null) {
            throw new Recusa('Agendamento.extraAlreadyDecided');
        }

        $limite = null;
        $antes  = $this->auditoria->ler('reservas_itens_extras', $id);

        if ($deferir) {
            $config = (new Configuracoes($this->db))->doAmbiente((int) $item['ambiente_id']);
            $horas  = (int) ($config['prazo_resposta_devolucao_horas'] ?? 0);
            $limite = (new DateTimeImmutable((string) $item['data_hora_fim']))
                ->modify("+{$horas} hours")
                ->format('Y-m-d H:i:s');
        }

        $this->db->query(
            "UPDATE reservas_itens_extras
             SET status = ?, justificativa_decisao = ?, decidido_por_id = ?, data_decisao = NOW(),
                 data_limite_devolucao = ?
             WHERE id_reserva_item_extra = ? AND status = 'pendente'",
            [$deferir ? 'deferido' : 'indeferido', $this->textoOuNulo($justificativa), $usuarioId, $limite, $id]
        );

        if ($this->db->affectedRows() === 0 || $antes === null) {
            throw new Recusa('Agendamento.extraAlreadyDecided');
        }

        $this->depoisDeDecidir('reservas_itens_extras', $id, $antes, $usuarioId, $deferir);
    }

    /** @throws Recusa */
    public function decidirSoftware(int $id, int $usuarioId, bool $deferir, string $justificativa): void
    {
        $antes = $this->auditoria->ler('reservas_softwares_extras', $id);

        $this->db->query(
            "UPDATE reservas_softwares_extras
             SET status = ?, justificativa_decisao = ?, decidido_por_id = ?, data_decisao = NOW()
             WHERE id_reserva_software_extra = ? AND deletado_em IS NULL AND status = 'pendente'",
            [$deferir ? 'deferido' : 'indeferido', $this->textoOuNulo($justificativa), $usuarioId, $id]
        );

        if ($this->db->affectedRows() === 0 || $antes === null) {
            throw new Recusa('Agendamento.extraAlreadyDecided');
        }

        $this->depoisDeDecidir('reservas_softwares_extras', $id, $antes, $usuarioId, $deferir);
    }

    /** @throws Recusa */
    public function confirmarDevolucao(int $id, int $usuarioId): void
    {
        $antes = $this->auditoria->ler('reservas_itens_extras', $id);

        $this->db->query(
            "UPDATE reservas_itens_extras SET devolucao_confirmada_em = NOW(), devolucao_confirmada_por_id = ?
             WHERE id_reserva_item_extra = ? AND deletado_em IS NULL AND status = 'deferido'
               AND devolucao_confirmada_em IS NULL",
            [$usuarioId, $id]
        );

        if ($this->db->affectedRows() === 0 || $antes === null) {
            throw new Recusa('Agendamento.returnNotPending');
        }

        $this->auditoria->registrarDepois('reservas_itens_extras', $id, $antes);
    }

    /**
     * Log de auditoria da decisão e aviso a quem reservou e ao beneficiário.
     *
     * @param array<string, mixed> $antes
     */
    private function depoisDeDecidir(string $tabela, int $id, array $antes, int $usuarioId, bool $deferir): void
    {
        $this->auditoria->registrarDepois($tabela, $id, $antes);

        $nome = $tabela === 'reservas_itens_extras'
            ? 'SELECT nome FROM categorias_itens WHERE id_categoria_item = ?'
            : 'SELECT nome FROM softwares WHERE id_software = ?';
        $pedido  = $this->db->query($nome, [(int) ($antes['categoria_item_id'] ?? $antes['software_id'])])->getRow();
        $reserva = $this->db->table('reservas_pontuais')
            ->where('id_reserva_pontual', (int) $antes['reserva_pontual_id'])
            ->get()
            ->getRowArray() ?? [];

        $notificador = new Notificador($this->db);
        $notificador->notificar(
            [(int) ($reserva['solicitante_id'] ?? 0), (int) ($reserva['beneficiario_id'] ?? 0)],
            $deferir ? 'extra_deferido' : 'extra_indeferido',
            'reserva_pontual',
            (int) $antes['reserva_pontual_id'],
            [
                'pedido' => (string) ($pedido->nome ?? ''),
                'titulo' => (string) ($reserva['titulo'] ?? ''),
                'inicio' => (string) ($reserva['data_hora_inicio'] ?? ''),
                'autor'  => $notificador->nome($usuarioId),
            ],
            $usuarioId
        );
    }

    /** Reserva dona do pedido de item ou software (para o controller conferir o escopo). */
    public function reservaDoItem(int $id): ?int
    {
        $linha = $this->db->query(
            'SELECT reserva_pontual_id FROM reservas_itens_extras
             WHERE id_reserva_item_extra = ? AND deletado_em IS NULL',
            [$id]
        )->getRow();

        return $linha === null ? null : (int) $linha->reserva_pontual_id;
    }

    public function reservaDoSoftware(int $id): ?int
    {
        $linha = $this->db->query(
            'SELECT reserva_pontual_id FROM reservas_softwares_extras
             WHERE id_reserva_software_extra = ? AND deletado_em IS NULL',
            [$id]
        )->getRow();

        return $linha === null ? null : (int) $linha->reserva_pontual_id;
    }

    private function textoOuNulo(string $texto): ?string
    {
        return trim($texto) === '' ? null : trim($texto);
    }
}

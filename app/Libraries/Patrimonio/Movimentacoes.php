<?php

namespace App\Libraries\Patrimonio;

use App\Libraries\Auditoria\Auditoria;
use App\Libraries\Notificacoes\Notificador;
use App\Models\Patrimonio\ItemModel;
use CodeIgniter\Database\BaseConnection;
use Config\Patrimonio;
use Throwable;

/**
 * Movimentação de um item com protocolo de recebimento: o envio deixa o item em trânsito
 * (continua no local de origem até a confirmação) e grava o prazo de resposta do campus de destino;
 * a confirmação de quem recebe leva o item ao destino. Sem cancelamento automático por atraso.
 *
 * Cada operação trava o item (e a movimentação) com SELECT ... FOR UPDATE dentro da transação, para
 * duas pessoas não moverem ou descartarem o mesmo item ao mesmo tempo.
 *
 * O envio avisa quem recebe; a confirmação avisa quem enviou; o cancelamento avisa quem ia receber (ninguém é
 * avisado do que ele mesmo fez). Movimentação e item entram no log de auditoria.
 */
final class Movimentacoes
{
    private readonly Auditoria $auditoria;
    private readonly Notificador $notificador;

    public function __construct(private readonly BaseConnection $db)
    {
        $this->auditoria   = new Auditoria($db);
        $this->notificador = new Notificador($db);
    }

    /**
     * @param array{tipo: string, campus_id: int, ambiente_id: ?int, almoxarifado_id: ?int} $destino
     *
     * @throws Recusa
     */
    public function enviar(
        int $itemId,
        array $destino,
        int $remetenteId,
        int $destinatarioId,
        ?int $quantidade = null
    ): int {
        return $this->emTransacao(function () use ($itemId, $destino, $remetenteId, $destinatarioId, $quantidade): int {
            $item = $this->travarItem($itemId);

            if (! in_array($item['status'], ['em_uso', 'almoxarifado'], true)) {
                throw new Recusa('Patrimonio.moveItemUnavailable');
            }

            if (ItemModel::pendente($item)) {
                throw new Recusa('Patrimonio.moveItemPending');
            }

            if ($this->temDescartePendente($itemId)) {
                throw new Recusa('Patrimonio.moveDisposalPending');
            }

            if ($quantidade !== null && ($quantidade < 1 || $quantidade > (int) $item['quantidade'])) {
                throw new Recusa('Patrimonio.quantityInvalid');
            }

            $origemAmbiente = $item['ambiente_id'] === null ? null : (int) $item['ambiente_id'];
            $origemAlmox    = $item['almoxarifado_id'] === null ? null : (int) $item['almoxarifado_id'];

            if ($origemAmbiente === $destino['ambiente_id'] && $origemAlmox === $destino['almoxarifado_id']) {
                throw new Recusa('Patrimonio.moveSameLocation');
            }

            // Item sem patrimônio: só a parte movida vai em trânsito (o resto continua no local, disponível).
            $itemId = $quantidade === null ? $itemId : (new GestorItens($this->db))->separar($itemId, $quantidade);
            $antes  = $this->auditoria->ler('itens', $itemId);

            $this->db->query(
                'INSERT INTO movimentacoes_itens (item_id, origem_tipo, origem_ambiente_id,
                    origem_almoxarifado_id, destino_tipo, destino_ambiente_id, destino_almoxarifado_id,
                    remetente_id, destinatario_id, status, data_envio, data_limite_confirmacao)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'pendente\', NOW(), DATE_ADD(NOW(), INTERVAL ? HOUR))',
                [
                    $itemId,
                    $origemAlmox === null ? Locais::AMBIENTE : Locais::ALMOXARIFADO,
                    $origemAmbiente,
                    $origemAlmox,
                    $destino['tipo'],
                    $destino['ambiente_id'],
                    $destino['almoxarifado_id'],
                    $remetenteId,
                    $destinatarioId,
                    $this->prazoHoras($destino['campus_id']),
                ]
            );
            $id = (int) $this->db->insertID();

            $this->db->query("UPDATE itens SET status = 'em_transito' WHERE id_item = ?", [$itemId]);

            $this->auditoria->registrarDepois('movimentacoes_itens', $id, null);
            $this->auditoria->registrarDepois('itens', $itemId, $antes);
            $this->notificador->notificar([$destinatarioId], 'movimentacao_enviada', 'movimentacao_item', $id, [
                'item'    => $this->notificador->item($itemId),
                'autor'   => $this->notificador->nome($remetenteId),
                'destino' => (new Locais($this->db))->rotulo($destino['ambiente_id'], $destino['almoxarifado_id']),
            ], $remetenteId);

            return $id;
        });
    }

    /**
     * Recebimento confirmado: o item passa ao destino.
     *
     * @throws Recusa
     */
    public function confirmar(int $movimentacaoId): void
    {
        $this->emTransacao(function () use ($movimentacaoId): void {
            $mov  = $this->travarPendente($movimentacaoId);
            $item = $this->travarItem((int) $mov['item_id']);

            $this->db->query(
                "UPDATE movimentacoes_itens SET status = 'confirmada', data_confirmacao = NOW()
                 WHERE id_movimentacao_item = ?",
                [$movimentacaoId]
            );

            $noAlmox = $mov['destino_tipo'] === Locais::ALMOXARIFADO;

            $this->db->query(
                'UPDATE itens SET ambiente_id = ?, almoxarifado_id = ?, status = ? WHERE id_item = ?',
                [
                    $mov['destino_ambiente_id'],
                    $mov['destino_almoxarifado_id'],
                    $noAlmox ? 'almoxarifado' : 'em_uso',
                    $mov['item_id'],
                ]
            );

            $this->registrar($mov, $item);
            (new GestorItens($this->db))->reunir((int) $item['id_item']);
            $this->notificador->notificar(
                [(int) $mov['remetente_id']],
                'movimentacao_confirmada',
                'movimentacao_item',
                $movimentacaoId,
                [
                    'item'  => $this->notificador->item((int) $item['id_item']),
                    'autor' => $this->notificador->nome((int) $mov['destinatario_id']),
                ],
                (int) $mov['destinatario_id']
            );
        });
    }

    /**
     * Cancelamento manual de um envio ainda pendente (engano): a movimentação sai por soft delete e o
     * item volta a ficar disponível no local de origem, onde nunca deixou de estar.
     *
     * @throws Recusa
     */
    public function cancelar(int $movimentacaoId): void
    {
        $this->emTransacao(function () use ($movimentacaoId): void {
            $mov  = $this->travarPendente($movimentacaoId);
            $item = $this->travarItem((int) $mov['item_id']);

            $this->db->query(
                'UPDATE movimentacoes_itens SET deletado_em = NOW() WHERE id_movimentacao_item = ?',
                [$movimentacaoId]
            );

            $this->db->query(
                'UPDATE itens SET status = ? WHERE id_item = ?',
                [$item['almoxarifado_id'] === null ? 'em_uso' : 'almoxarifado', $mov['item_id']]
            );

            $this->registrar($mov, $item);
            (new GestorItens($this->db))->reunir((int) $item['id_item']);
            $this->notificador->notificar(
                [(int) $mov['destinatario_id']],
                'movimentacao_cancelada',
                'movimentacao_item',
                $movimentacaoId,
                [
                    'item'  => $this->notificador->item((int) $item['id_item']),
                    'autor' => $this->notificador->nome((int) $mov['remetente_id']),
                ],
                (int) $mov['remetente_id']
            );
        });
    }

    /**
     * Log de auditoria da movimentação e do item, contra o estado travado antes da alteração.
     *
     * @param array<string, mixed> $mov
     * @param array<string, mixed> $item
     */
    private function registrar(array $mov, array $item): void
    {
        $this->auditoria->registrarDepois('movimentacoes_itens', (int) $mov['id_movimentacao_item'], $mov);
        $this->auditoria->registrarDepois('itens', (int) $item['id_item'], $item);
    }

    /** Prazo de resposta do campus de destino (configuracoes_campus) ou o padrão do sistema. */
    public function prazoHoras(int $campusId): int
    {
        $linha = $this->db->query(
            'SELECT prazo_resposta_movimentacao_horas AS horas FROM configuracoes_campus
             WHERE campus_id = ? AND deletado_em IS NULL',
            [$campusId]
        )->getRowArray();

        $horas = $linha['horas'] ?? null;

        return $horas === null ? config(Patrimonio::class)->prazoPadraoMovimentacaoHoras : (int) $horas;
    }

    /** @return array<string, mixed> */
    private function travarItem(int $itemId): array
    {
        $item = $this->db->query(
            'SELECT * FROM itens WHERE id_item = ? AND deletado_em IS NULL FOR UPDATE',
            [$itemId]
        )->getRowArray();

        return $item ?? throw new Recusa('Patrimonio.itemNotFound');
    }

    /** @return array<string, mixed> */
    private function travarPendente(int $movimentacaoId): array
    {
        $mov = $this->db->query(
            'SELECT * FROM movimentacoes_itens WHERE id_movimentacao_item = ? AND deletado_em IS NULL FOR UPDATE',
            [$movimentacaoId]
        )->getRowArray();

        if ($mov === null) {
            throw new Recusa('Patrimonio.movementNotFound');
        }

        if ($mov['status'] !== 'pendente') {
            throw new Recusa('Patrimonio.movementNotPending');
        }

        return $mov;
    }

    private function temDescartePendente(int $itemId): bool
    {
        return $this->db->table('descartes')
            ->where('item_id', $itemId)
            ->where('status', 'pendente')
            ->where('deletado_em', null)
            ->countAllResults() > 0;
    }

    /**
     * @template T
     *
     * @param callable(): T $operacao
     *
     * @return T
     */
    private function emTransacao(callable $operacao): mixed
    {
        $this->db->transBegin();

        try {
            $resultado = $operacao();
            $this->db->transCommit();

            return $resultado;
        } catch (Throwable $erro) {
            $this->db->transRollback();

            throw $erro;
        }
    }
}

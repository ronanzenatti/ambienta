<?php

namespace App\Libraries\Patrimonio;

use App\Libraries\Auditoria\Auditoria;
use App\Libraries\Notificacoes\Destinatarios;
use App\Libraries\Notificacoes\Notificador;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Baixa de um item: solicitação com motivo da lista fixa, decisão de quem tem
 * patrimonio.aprovar_descarte. Aprovada, o item fica "descartado" com deletado_em preenchido (soft
 * delete; nunca sai do banco) e o número de patrimônio continua ocupado para sempre (UNIQUE simples).
 * Item sem patrimônio pode ter só parte da quantidade baixada: a parte é separada num registro na solicitação
 * e volta a se juntar ao resto se a baixa for rejeitada.
 *
 * Mesmo cuidado de concorrência das movimentações: item e solicitação travados com FOR UPDATE.
 *
 * O pedido avisa quem pode aprovar no local do item; a decisão avisa quem pediu. Tudo entra no log de auditoria.
 */
final class Descartes
{
    private readonly Auditoria $auditoria;
    private readonly Notificador $notificador;

    public function __construct(private readonly BaseConnection $db)
    {
        $this->auditoria   = new Auditoria($db);
        $this->notificador = new Notificador($db);
    }

    /** @throws Recusa */
    public function solicitar(
        int $itemId,
        int $motivoId,
        ?string $justificativa,
        int $solicitanteId,
        ?int $quantidade = null
    ): int {
        $dados = [$itemId, $motivoId, $justificativa, $solicitanteId, $quantidade];

        return $this->emTransacao(function () use ($dados): int {
            [$itemId, $motivoId, $justificativa, $solicitanteId, $quantidade] = $dados;

            $item = $this->db->query(
                'SELECT status, quantidade FROM itens WHERE id_item = ? AND deletado_em IS NULL FOR UPDATE',
                [$itemId]
            )->getRowArray();

            if ($item === null) {
                throw new Recusa('Patrimonio.itemNotFound');
            }

            if ($item['status'] === 'em_transito') {
                throw new Recusa('Patrimonio.disposalInTransit');
            }

            if ($this->pendente($itemId) !== null) {
                throw new Recusa('Patrimonio.disposalAlreadyPending');
            }

            if ($quantidade !== null && ($quantidade < 1 || $quantidade > (int) $item['quantidade'])) {
                throw new Recusa('Patrimonio.quantityInvalid');
            }

            // Item sem patrimônio: só a parte a baixar fica com a solicitação (o resto segue disponível).
            $itemId = $quantidade === null ? $itemId : (new GestorItens($this->db))->separar($itemId, $quantidade);

            $this->db->table('descartes')->insert([
                'item_id'            => $itemId,
                'motivo_descarte_id' => $motivoId,
                'solicitante_id'     => $solicitanteId,
                'justificativa'      => $justificativa,
                'status'             => 'pendente',
            ]);
            $id = (int) $this->db->insertID();

            $this->auditoria->registrarDepois('descartes', $id, null);
            $this->notificador->notificar(
                (new Destinatarios($this->db))->doItem('patrimonio.aprovar_descarte', $itemId),
                'descarte_solicitado',
                'descarte',
                $id,
                ['item' => $this->notificador->item($itemId), 'autor' => $this->notificador->nome($solicitanteId)],
                $solicitanteId
            );

            return $id;
        });
    }

    /** @throws Recusa */
    public function aprovar(int $descarteId, int $aprovadorId): void
    {
        $this->emTransacao(function () use ($descarteId, $aprovadorId): void {
            $descarte = $this->travarPendente($descarteId);

            $item = $this->db->query(
                'SELECT id_item FROM itens WHERE id_item = ? AND deletado_em IS NULL FOR UPDATE',
                [$descarte['item_id']]
            )->getRowArray();

            if ($item === null) {
                throw new Recusa('Patrimonio.itemNotFound');
            }

            $this->decidir($descarte, 'aprovado', $aprovadorId);

            $itemId = (int) $descarte['item_id'];
            $antes  = $this->auditoria->ler('itens', $itemId);

            $this->db->query(
                "UPDATE itens SET status = 'descartado', deletado_em = NOW() WHERE id_item = ?",
                [$itemId]
            );
            $this->auditoria->registrarDepois('itens', $itemId, $antes);
        });
    }

    /** @throws Recusa */
    public function rejeitar(int $descarteId, int $aprovadorId): void
    {
        $this->emTransacao(function () use ($descarteId, $aprovadorId): void {
            $descarte = $this->travarPendente($descarteId);

            $this->decidir($descarte, 'rejeitado', $aprovadorId);
            (new GestorItens($this->db))->reunir((int) $descarte['item_id']);
        });
    }

    /** Solicitação pendente do item, se houver. */
    public function pendente(int $itemId): ?int
    {
        $linha = $this->db->table('descartes')
            ->select('id_descarte')
            ->where('item_id', $itemId)
            ->where('status', 'pendente')
            ->where('deletado_em', null)
            ->get()
            ->getRowArray();

        return $linha === null ? null : (int) $linha['id_descarte'];
    }

    /** @param array<string, mixed> $descarte a solicitação pendente, já travada */
    private function decidir(array $descarte, string $status, int $aprovadorId): void
    {
        $id = (int) $descarte['id_descarte'];

        $this->db->query(
            'UPDATE descartes SET status = ?, aprovador_id = ?, data_decisao = NOW() WHERE id_descarte = ?',
            [$status, $aprovadorId, $id]
        );
        $this->auditoria->registrarDepois('descartes', $id, $descarte);

        $this->notificador->notificar(
            [(int) $descarte['solicitante_id']],
            $status === 'aprovado' ? 'descarte_aprovado' : 'descarte_rejeitado',
            'descarte',
            $id,
            [
                'item'  => $this->notificador->item((int) $descarte['item_id']),
                'autor' => $this->notificador->nome($aprovadorId),
            ],
            $aprovadorId
        );
    }

    /** @return array<string, mixed> */
    private function travarPendente(int $descarteId): array
    {
        $descarte = $this->db->query(
            'SELECT * FROM descartes WHERE id_descarte = ? AND deletado_em IS NULL FOR UPDATE',
            [$descarteId]
        )->getRowArray();

        if ($descarte === null) {
            throw new Recusa('Patrimonio.disposalNotFound');
        }

        if ($descarte['status'] !== 'pendente') {
            throw new Recusa('Patrimonio.disposalAlreadyDecided');
        }

        return $descarte;
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

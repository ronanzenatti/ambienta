<?php

namespace App\Libraries\Patrimonio;

use App\Libraries\Auditoria\Auditoria;
use CodeIgniter\Database\BaseConnection;

/**
 * Criação, separação e reunião de itens. Item patrimonial é sempre um registro por número (quantidade 1); item
 * sem patrimônio (marcador, apagador) agrupa a quantidade num registro por tipo e local, e é separado quando só
 * parte dele é movida ou baixada, e reunido ao igual do mesmo local quando chega.
 *
 * Os métodos de separar/reunir pressupõem a transação aberta (quem chama); eles mesmos travam as linhas.
 *
 * @phpstan-type Molde array{
 *     modelo_item_id: ?int, categoria_item_id: int, nome: string, marca: ?string,
 *     especificacoes: ?string, patrimoniavel: int
 * }
 * @phpstan-type LocalItem array{tipo: string, ambiente_id: ?int, almoxarifado_id: ?int}
 */
final class GestorItens
{
    /** Campos copiados do molde (modelo ou item) para um item novo. */
    public const MOLDE = ['modelo_item_id', 'categoria_item_id', 'nome', 'marca', 'especificacoes', 'patrimoniavel'];

    private readonly Auditoria $auditoria;

    public function __construct(private readonly BaseConnection $db)
    {
        $this->auditoria = new Auditoria($db);
    }

    /**
     * Molde de um item novo a partir de uma linha de modelos_itens.
     *
     * @param array<string, mixed> $modelo
     *
     * @return Molde
     */
    public static function moldeDoModelo(array $modelo): array
    {
        return [
            'modelo_item_id'    => (int) $modelo['id_modelo_item'],
            'categoria_item_id' => (int) $modelo['categoria_item_id'],
            'nome'              => (string) $modelo['nome'],
            'marca'             => $modelo['marca'],
            'especificacoes'    => $modelo['especificacoes'],
            'patrimoniavel'     => (int) (bool) $modelo['patrimoniavel'],
        ];
    }

    /**
     * Cria itens num local. Patrimoniável: $quantidade registros sem número (ou um, com $numero). Sem patrimônio:
     * um registro com a quantidade, somado ao igual que já estiver no local.
     *
     * @param Molde     $molde
     * @param LocalItem $local
     *
     * @return list<int> ids dos itens criados (ou do que recebeu a quantidade)
     */
    public function criar(array $molde, array $local, int $quantidade, ?string $numero = null): array
    {
        if (! (bool) $molde['patrimoniavel']) {
            return [$this->reunir($this->inserir($molde, $local, $quantidade, null))];
        }

        $ids = [];

        for ($i = 0; $i < $quantidade; $i++) {
            $ids[] = $this->inserir($molde, $local, 1, $quantidade === 1 ? $numero : null);
        }

        return $ids;
    }

    /**
     * Separa $quantidade unidades de um item sem patrimônio num registro novo (mesmo local e status) e devolve o
     * id dele. Quantidade igual ou maior que a do item (ou item patrimonial): o próprio item, sem separar.
     */
    public function separar(int $itemId, int $quantidade): int
    {
        $item = $this->travar($itemId);

        if ((bool) $item['patrimoniavel'] || $quantidade >= (int) $item['quantidade']) {
            return $itemId;
        }

        $this->db->query('UPDATE itens SET quantidade = quantidade - ? WHERE id_item = ?', [$quantidade, $itemId]);
        $this->auditoria->registrarDepois('itens', $itemId, $item);

        $this->db->table('itens')->insert(array_intersect_key($item, array_flip(self::MOLDE)) + [
            'quantidade'      => $quantidade,
            'ambiente_id'     => $item['ambiente_id'],
            'almoxarifado_id' => $item['almoxarifado_id'],
            'status'          => $item['status'],
        ]);
        $id = (int) $this->db->insertID();
        $this->auditoria->registrarDepois('itens', $id, null);

        return $id;
    }

    /**
     * Item sem patrimônio parado num local (em uso ou no almoxarifado): soma a quantidade ao igual que já está no
     * mesmo local e baixa este registro (soft delete). Devolve o id do registro que ficou.
     */
    public function reunir(int $itemId): int
    {
        $item = $this->travar($itemId);

        if ((bool) $item['patrimoniavel'] || ! in_array($item['status'], ['em_uso', 'almoxarifado'], true)) {
            return $itemId;
        }

        $igual = $this->igual($item);

        if ($igual === null) {
            return $itemId;
        }

        $igualId    = (int) $igual['id_item'];
        $antesIgual = $this->auditoria->ler('itens', $igualId);

        $this->db->query('UPDATE itens SET quantidade = quantidade + ? WHERE id_item = ?', [
            $item['quantidade'],
            $igualId,
        ]);
        $this->db->query('UPDATE itens SET deletado_em = NOW() WHERE id_item = ?', [$itemId]);

        $this->auditoria->registrarDepois('itens', $igualId, $antesIgual);
        $this->auditoria->registrarDepois('itens', $itemId, $item);

        return $igualId;
    }

    /** @return array<string, mixed> */
    private function travar(int $itemId): array
    {
        return $this->db->query(
            'SELECT * FROM itens WHERE id_item = ? AND deletado_em IS NULL FOR UPDATE',
            [$itemId]
        )->getRowArray() ?? throw new Recusa('Patrimonio.itemNotFound');
    }

    /**
     * Outro item sem patrimônio, ativo e parado no mesmo local, com os mesmos dados.
     *
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>|null
     */
    private function igual(array $item): ?array
    {
        return $this->db->query(
            "SELECT * FROM itens
             WHERE id_item <> ? AND deletado_em IS NULL AND patrimoniavel = 0 AND numero_patrimonio IS NULL
               AND status IN ('em_uso', 'almoxarifado')
               AND modelo_item_id <=> ? AND categoria_item_id = ? AND nome = ? AND marca <=> ?
               AND especificacoes <=> ? AND ambiente_id <=> ? AND almoxarifado_id <=> ?
             LIMIT 1 FOR UPDATE",
            [
                $item['id_item'],
                $item['modelo_item_id'],
                $item['categoria_item_id'],
                $item['nome'],
                $item['marca'],
                $item['especificacoes'],
                $item['ambiente_id'],
                $item['almoxarifado_id'],
            ]
        )->getRowArray();
    }

    /**
     * @param Molde     $molde
     * @param LocalItem $local
     */
    private function inserir(array $molde, array $local, int $quantidade, ?string $numero): int
    {
        $this->db->table('itens')->insert(array_intersect_key($molde, array_flip(self::MOLDE)) + [
            'numero_patrimonio' => $numero,
            'quantidade'        => $quantidade,
            'ambiente_id'       => $local['ambiente_id'],
            'almoxarifado_id'   => $local['almoxarifado_id'],
            'status'            => $local['tipo'] === Locais::AMBIENTE ? 'em_uso' : 'almoxarifado',
        ]);
        $id = (int) $this->db->insertID();
        $this->auditoria->registrarDepois('itens', $id, null);

        return $id;
    }
}

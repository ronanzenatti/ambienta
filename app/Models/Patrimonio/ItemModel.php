<?php

namespace App\Models\Patrimonio;

use App\Libraries\Auditoria\Auditavel;
use App\Models\Institucional\ModeloInstitucional;

/**
 * Item: patrimonial (um registro por número de patrimônio) ou sem patrimônio (um registro por tipo e local,
 * com quantidade: marcador, apagador, grampeador). O item tem dados próprios (nome, marca, especificações,
 * categoria), copiados do modelo ao criar: trocar o SSD de um PC só altera aquele item.
 *
 * Sem exclusão comum: o soft delete só acontece na aprovação do descarte (App\Libraries\Patrimonio\Descartes),
 * e o número de patrimônio nunca é reaproveitado.
 */
class ItemModel extends ModeloInstitucional
{
    use Auditavel;

    protected $table         = 'itens';
    protected $primaryKey    = 'id_item';
    protected $allowedFields = [
        'modelo_item_id',
        'categoria_item_id',
        'nome',
        'marca',
        'especificacoes',
        'patrimoniavel',
        'numero_patrimonio',
        'quantidade',
        'ambiente_id',
        'almoxarifado_id',
        'status',
    ];

    /** Número já usado por qualquer item, inclusive descartado? */
    public function numeroEmUso(string $numero, ?int $ignorarId = null): bool
    {
        $consulta = $this->withDeleted()->where('numero_patrimonio', $numero);

        if ($ignorarId !== null) {
            $consulta->where('id_item !=', $ignorarId);
        }

        return $consulta->countAllResults() > 0;
    }

    /** Patrimoniável que ainda não recebeu número: pendente, não pode ser movido, alocado nem contado. */
    public static function pendente(array $item): bool
    {
        return (bool) $item['patrimoniavel'] && $item['numero_patrimonio'] === null;
    }
}

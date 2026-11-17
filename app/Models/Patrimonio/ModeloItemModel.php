<?php

namespace App\Models\Patrimonio;

use App\Models\Institucional\ModeloInstitucional;

/**
 * Modelo de item (ex.: "Notebook Dell Latitude 5420"): um molde. Ao criar itens a partir dele (replicação, planilha
 * da tela de números), os dados são copiados para cada item, que depois segue por conta própria.
 */
class ModeloItemModel extends ModeloInstitucional
{
    protected $table         = 'modelos_itens';
    protected $primaryKey    = 'id_modelo_item';
    protected $allowedFields = ['categoria_item_id', 'nome', 'marca', 'especificacoes', 'patrimoniavel', 'foto'];
}

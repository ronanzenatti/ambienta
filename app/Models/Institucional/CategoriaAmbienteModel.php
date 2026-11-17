<?php

namespace App\Models\Institucional;

class CategoriaAmbienteModel extends ModeloInstitucional
{
    protected $table         = 'categorias_ambientes';
    protected $primaryKey    = 'id_categoria_ambiente';
    protected $allowedFields = ['nome', 'descricao'];

    protected const DEPENDENTES = ['ambientes' => 'categoria_ambiente_id'];
}

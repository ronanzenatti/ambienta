<?php

namespace App\Models\Institucional;

class SemestreModel extends ModeloInstitucional
{
    public const STATUS = ['planejado', 'aberto', 'encerrado'];

    protected $table         = 'semestres';
    protected $primaryKey    = 'id_semestre';
    protected $allowedFields = ['nome', 'data_inicio', 'data_fim', 'status'];

    protected const DEPENDENTES = ['alocacoes_semestrais' => 'semestre_id'];
}

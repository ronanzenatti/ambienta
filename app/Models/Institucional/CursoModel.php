<?php

namespace App\Models\Institucional;

class CursoModel extends ModeloInstitucional
{
    public const TIPOS = ['extensao', 'graduacao', 'lato_sensu', 'stricto_sensu'];

    protected $table         = 'cursos';
    protected $primaryKey    = 'id_curso';
    protected $allowedFields = ['nome', 'abreviacao', 'descricao', 'tipo', 'responsavel_id'];

    protected const DEPENDENTES = [
        'disciplinas'          => 'curso_id',
        'professores_cursos'   => 'curso_id',
    ];
}

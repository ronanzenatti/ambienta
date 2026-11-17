<?php

namespace App\Models\Institucional;

class DisciplinaModel extends ModeloInstitucional
{
    protected $table         = 'disciplinas';
    protected $primaryKey    = 'id_disciplina';
    protected $allowedFields = ['curso_id', 'nome', 'sigla'];

    protected const DEPENDENTES = [
        'professores_disciplinas' => 'disciplina_id',
        'reservas_pontuais'     => 'disciplina_id',
        'requisitos_disciplina' => 'disciplina_id',
        'alocacoes_semestrais'  => 'disciplina_id',
    ];
}

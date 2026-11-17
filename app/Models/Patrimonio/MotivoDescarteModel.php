<?php

namespace App\Models\Patrimonio;

use App\Models\Institucional\ModeloInstitucional;

/** Lista fixa de motivos de descarte (seeder MotivosDescarteSeeder); só leitura na aplicação. */
class MotivoDescarteModel extends ModeloInstitucional
{
    protected $table      = 'motivos_descarte';
    protected $primaryKey = 'id_motivo_descarte';
}

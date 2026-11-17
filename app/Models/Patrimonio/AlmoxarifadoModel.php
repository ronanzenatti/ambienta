<?php

namespace App\Models\Patrimonio;

use App\Libraries\Auditoria\Auditavel;
use App\Models\Institucional\ModeloInstitucional;

/** Almoxarifado: um por campus (campus_unico no schema). */
class AlmoxarifadoModel extends ModeloInstitucional
{
    use Auditavel;

    protected $table         = 'almoxarifados';
    protected $primaryKey    = 'id_almoxarifado';
    protected $allowedFields = ['campus_id', 'nome'];

    protected const DEPENDENTES = [
        'itens'                           => 'almoxarifado_id',
    ];

    public function campusTemAlmoxarifado(int $campusId): bool
    {
        return $this->where('campus_id', $campusId)->countAllResults() > 0;
    }
}

<?php

namespace App\Models\Patrimonio;

use App\Models\Institucional\ModeloInstitucional;

/** Softwares disponíveis em um ambiente (par único entre os vínculos ativos). */
class AmbienteSoftwareModel extends ModeloInstitucional
{
    protected $table         = 'ambientes_softwares';
    protected $primaryKey    = 'id_ambiente_software';
    protected $allowedFields = ['ambiente_id', 'software_id'];

    /** @return list<array<string, mixed>> vínculos do ambiente, com os dados do software */
    public function doAmbiente(int $ambienteId): array
    {
        return $this->select('ambientes_softwares.id_ambiente_software')
            ->select('softwares.id_software, softwares.nome, softwares.versao, softwares.requisitos_tecnicos')
            ->join(
                'softwares',
                'softwares.id_software = ambientes_softwares.software_id AND softwares.deletado_em IS NULL'
            )
            ->where('ambientes_softwares.ambiente_id', $ambienteId)
            ->orderBy('softwares.nome')
            ->findAll();
    }

    public function vinculado(int $ambienteId, int $softwareId): bool
    {
        return $this->where('ambiente_id', $ambienteId)->where('software_id', $softwareId)->countAllResults() > 0;
    }
}

<?php

namespace App\Models\Chamados;

use App\Models\Institucional\ModeloInstitucional;

/** Softwares exigidos por um requisito. */
class RequisitoSoftwareModel extends ModeloInstitucional
{
    protected $table         = 'requisitos_disciplina_softwares';
    protected $primaryKey    = 'id_requisito_disciplina_software';
    protected $allowedFields = ['requisito_disciplina_id', 'software_id'];

    /** @return list<array<string, mixed>> com nome e versão do software */
    public function doRequisito(int $requisitoId): array
    {
        return $this->select('requisitos_disciplina_softwares.*, s.nome, s.versao, s.status, s.motivo_reprovacao')
            ->join('softwares s', 's.id_software = requisitos_disciplina_softwares.software_id')
            ->where('requisito_disciplina_id', $requisitoId)
            ->orderBy('s.nome')
            ->orderBy('s.versao')
            ->findAll();
    }

    public function vinculado(int $requisitoId, int $softwareId): bool
    {
        return $this->where('requisito_disciplina_id', $requisitoId)
            ->where('software_id', $softwareId)
            ->countAllResults() > 0;
    }
}

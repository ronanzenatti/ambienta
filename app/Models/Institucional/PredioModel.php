<?php

namespace App\Models\Institucional;

use App\Libraries\Auditoria\Auditavel;

class PredioModel extends ModeloInstitucional
{
    use Auditavel;

    protected $table         = 'predios';
    protected $primaryKey    = 'id_predio';
    protected $allowedFields = [
        'campus_id', 'nome', 'numero', 'abreviacao', 'logradouro', 'numero_endereco', 'complemento', 'bairro',
        'cidade', 'uf', 'cep', 'latitude', 'longitude', 'foto', 'mapa',
    ];

    protected const DEPENDENTES = [
        'ambientes'       => 'predio_id',
        'usuarios_perfis' => 'predio_id',
    ];

    /**
     * Prédios de um campus: todos (null) ou só os ids informados.
     *
     * @param list<int>|null $ids
     *
     * @return list<array<string, mixed>>
     */
    public function doCampus(int $campusId, ?array $ids = null): array
    {
        if ($ids === []) {
            return [];
        }

        $this->where('campus_id', $campusId);

        if ($ids !== null) {
            $this->whereIn('id_predio', $ids);
        }

        return $this->orderBy('nome')->findAll();
    }

    /**
     * Campi dos prédios informados (quem só tem escopo de prédio enxerga o campus deles).
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public function campiDe(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $linhas = $this->builder()
            ->distinct()
            ->select('campus_id')
            ->whereIn('id_predio', $ids)
            ->where('deletado_em', null)
            ->get()
            ->getResultArray();

        return array_map(static fn (array $l): int => (int) $l['campus_id'], $linhas);
    }
}

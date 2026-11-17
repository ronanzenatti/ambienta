<?php

namespace App\Models\Institucional;

use App\Libraries\Auditoria\Auditavel;

class CampusModel extends ModeloInstitucional
{
    use Auditavel;

    protected $table         = 'campus';
    protected $primaryKey    = 'id_campus';
    protected $allowedFields = [
        'nome', 'abreviacao', 'numero', 'logradouro', 'numero_endereco', 'complemento', 'bairro', 'cidade',
        'uf', 'cep', 'latitude', 'longitude', 'foto', 'mapa', 'logo', 'cor_primaria', 'cor_texto', 'ativo',
    ];

    protected const DEPENDENTES = [
        'predios'              => 'campus_id',
        'usuarios_perfis'      => 'campus_id',
        'almoxarifados'        => 'campus_id',
        'feriados'             => 'campus_id',
    ];

    /**
     * Campi visíveis: todos (null) ou só os ids informados.
     *
     * @param list<int>|null $ids
     *
     * @return list<array<string, mixed>>
     */
    public function listar(?array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        if ($ids !== null) {
            $this->whereIn('id_campus', $ids);
        }

        return $this->orderBy('nome')->findAll();
    }
}

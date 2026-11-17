<?php

namespace App\Models\Institucional;

use App\Libraries\Auditoria\Auditavel;

class AmbienteModel extends ModeloInstitucional
{
    use Auditavel;

    protected $table         = 'ambientes';
    protected $primaryKey    = 'id_ambiente';
    protected $allowedFields = [
        'predio_id', 'categoria_ambiente_id', 'numero', 'abreviacao', 'nome', 'descricao', 'capacidade',
        'tamanho_m2', 'foto', 'exige_confirmacao_reserva', 'ativo',
    ];

    protected const DEPENDENTES = [
        'itens'                           => 'ambiente_id',
        'ambientes_softwares'             => 'ambiente_id',
        'alocacoes_semestrais'            => 'ambiente_id',
        'reservas_pontuais'               => 'ambiente_id',
        'bloqueios_ambientes'             => 'ambiente_id',
        'chamados'                        => 'ambiente_id',
    ];

    /** @return list<array<string, mixed>> ambientes do prédio, com o nome da categoria */
    public function doPredio(int $predioId): array
    {
        return $this->select('ambientes.*, categorias_ambientes.nome AS categoria')
            ->join(
                'categorias_ambientes',
                'categorias_ambientes.id_categoria_ambiente = ambientes.categoria_ambiente_id'
            )
            ->where('ambientes.predio_id', $predioId)
            ->orderBy('ambientes.numero')
            ->findAll();
    }
}

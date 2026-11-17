<?php

namespace App\Models\Chamados;

use App\Models\Institucional\ModeloInstitucional;

/** Itens exigidos por um requisito: categoria de item e quantidade (uma linha por categoria). */
class RequisitoItemModel extends ModeloInstitucional
{
    protected $table         = 'requisitos_disciplina_itens';
    protected $primaryKey    = 'id_requisito_disciplina_item';
    protected $allowedFields = ['requisito_disciplina_id', 'categoria_item_id', 'quantidade'];

    /** @return list<array<string, mixed>> com o nome da categoria */
    public function doRequisito(int $requisitoId): array
    {
        return $this->select('requisitos_disciplina_itens.*, ci.nome')
            ->join('categorias_itens ci', 'ci.id_categoria_item = requisitos_disciplina_itens.categoria_item_id')
            ->where('requisito_disciplina_id', $requisitoId)
            ->orderBy('ci.nome')
            ->findAll();
    }

    /** @return array<string, mixed>|null */
    public function daCategoria(int $requisitoId, int $categoriaId): ?array
    {
        return $this->where('requisito_disciplina_id', $requisitoId)->where('categoria_item_id', $categoriaId)->first();
    }
}
